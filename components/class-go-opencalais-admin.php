<?php

class GO_OpenCalais_Admin
{

	public $config = array(
		'api_key' => FALSE,
		'confidence_threshold_default' => 0,
		'max_tag_length' => 100,
		'max_ignored_tags' => 30,
		'mapping' => array(
			// 'Open Calais entity name' => 'WordPress taxonomy',
			'socialTag' => 'post_tag',
		),
		// Not configured by default, as it requires a custom taxonomy to track its progress
		// 'autotagger' => array(
		//		'taxonomy' => 'utility_taxonomy',
		//		'term' => 'go-opencalais-autotagged',
		//		'per_page' => 5,
		// ),
	);

	private $enrich_loaded = FALSE;

	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->config = apply_filters( 'go_config', array(), 'go-opencalais' );
		// check to see if the API is set and we have mappings befor adding hooks
		if ( isset( $this->config['api_key'], $this->config['mapping'] ) )
		{
			add_action( 'init', array( $this, 'init' ), 2 );
		}
		// @TODO: add an else condition that gets noisy about the config being incorrect

	}//end __construct

	public function init()
	{
		add_action( 'admin_enqueue_scripts', array( $this, 'action_admin_enqueue_scripts' ) );
		add_action( 'admin_footer-post.php', array( $this, 'action_admin_footer_post' ) );
		add_action( 'wp_ajax_go_oc_css', array( $this, 'wp_ajax_go_oc_css' ) );
		add_action( 'wp_ajax_go_oc_enrich', array( $this, 'wp_ajax_go_oc_enrich' ) );
		add_action( 'save_post', array( $this, 'action_save_post' ), 10, 2 );

		add_filter( 'go_oc_response', array( $this, 'filter_insert_socialtags_as_entities' ), 1 );
		add_filter( 'go_oc_response', array( $this, 'filter_normalize_relevance' ), 3 );
		add_filter( 'go_oc_response', array( $this, 'filter_response_threshold' ), 7 );

		// check if the autotagger is configged before loading it
		if ( is_array( $this->config['autotagger'] ) )
		{
			go_opencalais()->autotagger();
		}
	}//end init

	public function action_admin_enqueue_scripts( $hook_suffix )
	{
		if ( 'post.php' != $hook_suffix )
		{
			return;
		}//end if

		wp_enqueue_script( 'go_opencalais', plugins_url( 'js/go-oc.js', __FILE__ ), 'jquery', 1, TRUE );
		wp_enqueue_style( 'go_opencalais_css', plugins_url( 'css/go-oc.css', __FILE__ ), NULL, 1 );
		wp_enqueue_style( 'go_opencalais_dyn_css', admin_url( 'admin-ajax.php?action=go_oc_css' ), NULL, 1 );
	}//end action_admin_enqueue_scripts

	public function action_admin_footer_post( $hook_suffix )
	{
		global $action, $post;

		if ( 'edit' !== $action )
		{
			return;
		}//end if

		$meta = (array) get_post_meta( $post->ID, 'go_oc_settings', TRUE );

		if ( ! isset( $meta['ignored'] ))
		{
			$meta['ignored'] = array();
		}//end if

		$ignored = json_encode( $meta['ignored'] );

		// Sanitize taxonomy mapping
		$mapping = array();
		foreach ( $this->config['mapping'] as $remote => $local )
		{
			// valid local taxonomy and clean remote taxonomy
			if ( ! taxonomy_exists( $local ) || ! preg_match( '/^[a-z]{1,50}$/i', $remote ) )
			{
				continue;
			}//end if

			$mapping[ $remote ] = $local;
		}//end foreach

		?>
		<script type="text/javascript">
		go_oc_ignored_tags = <?php echo $ignored; ?>;
		go_oc_taxonomy_map = <?php echo json_encode( $mapping ); ?>;
		</script>
		<?php
	}//end action_admin_footer_post

	/**
	 * Sanitize incoming tax_ignore[taxonomy] values, which are comma-
	 * separated lists of ignored tags from textareas, a la built-in .the-tags
	 */
	public function action_save_post( $post_id, $post )
	{
		// Check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		{
			return;
		}// end if

		$post = get_post( $post_id );
		if ( ! is_object( $post ) )
		{
			return;
		}// end if

		// check post type matches what you intend
		$whitelisted_post_types = array( 'post' );
		if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, $whitelisted_post_types ) )
		{
			return;
		}// end if

		// Don't run on post revisions (almost always happens just before the real post is saved)
		if ( wp_is_post_revision( $post->ID ) )
		{
			return;
		}// end if

		// Check the permissions
		if ( ! current_user_can( 'edit_post', $post->ID  ) )
		{
			return;
		}// end if

		if ( ! isset( $_POST['tax_ignore'] ) || ! is_array( $_POST['tax_ignore'] ) )
		{
			return;
		}//end if

		$ignore = array();
		foreach ( $_POST['tax_ignore'] as $tax => $tags )
		{
			if ( ! taxonomy_exists( $tax ) )
			{
				continue;
			}//end if

			// not all of these tags will exist as valid terms, they are merely
			// tags returned by OpenCalais that we want to ignore
			$tags = explode( ',', $tags );

			// Do some basic sanitization on tags (tag length, number of tags)
			$clean_tags = array();
			foreach ( $tags as $tag )
			{
				$tag = substr( trim( $tag ), 0, $this->config['max_tag_length'] );
				$clean_tags[] = wp_kses( $tag, array() );

				if ( count( $clean_tags ) > $this->config['max_ignored_tags'] )
				{
					break;
				}//end if
			}//end foreach

			$ignore[ $tax ] = $clean_tags;
		}//end foreach

		$meta = (array) get_post_meta( $post_id, 'go_oc_settings', TRUE );
		if( empty($meta) )
		{
			$meta = array();
		}//end if

		$meta['ignored'] = $ignore;
		update_post_meta( $post_id, 'go_oc_settings', $meta );
	}//end action_save_post

	protected function ajax_error( $message )
	{
		if( is_wp_error( $message ) )
		{
			$message = $message->get_error_message();
		}//end if

		echo json_encode(
			array(
				'error' => $message,
			)
		);

		die();
	}//end ajax_error


	// insert socialTags as entities when there's no other entity wit the same value
	public function filter_insert_socialtags_as_entities( $response )
	{

		// get the list of all entites and tags
		$tags = $entities = array();
		foreach ( $response as $k => $v )
		{
			if ( isset( $v->_typeGroup, $v->name ) )
			{
				switch ( $v->_typeGroup )
				{
					case 'socialTag':
						$tags[ $k ] = $v->name;
						break;
					case 'entities':
						$entities[ $k ] = $v->name;
						break;
					default:
						break;

				}
			}

		}

		// identify the unqique tags and insert additional elements so they can be treated as entities
		foreach ( array_diff( $tags, $entities ) as $k => $v )
		{
			$response[ $k ]->_type = 'socialTag';
			$response[ $k ]->relevance = (float) '0.6';
		}

		return $response;
	}//end filter_insert_socialtags_as_entities

	public function filter_normalize_relevance( $response )
	{
		$max_relevance = 0;

		// first, find max
		foreach( $response as $object )
		{
			if( isset( $object->relevance ) && $object->relevance > $max_relevance )
			{
				$max_relevance = $object->relevance;
			}//end if
		}//end foreach

		// then normalize
		foreach( $response as &$object )
		{
			if( isset( $object->relevance ) )
			{
				$object->_go_orig_relevance = $object->relevance;

				$relevance = ( 1 / $max_relevance ) * $object->relevance;
				$relevance = round( $relevance, 3 );

				$object->relevance = $relevance;
			}//end if
		}//end foreach

		return $response;
	}//end filter_normalize_relevance

	/**
	 *
	 */
	public function filter_response_threshold( $response )
	{
		return array_filter( $response, array( $this, '_filter_response_threshold' ) );
	}//end filter_response_threshold

	/**
	 * Predictable path for CSS declarations that reference admin media.
	 */
	public function wp_ajax_go_oc_css()
	{
		header('Content-type: text/css');

		?>
		.go-oc-ignore {
			background: url(images/xit.gif) no-repeat;
		}
		.go-oc-ignore:hover {
			background: url(images/xit.gif) no-repeat -10px 0;
		}
		<?php

		die();
	}//end wp_ajax_go_oc_css

	/**
	 * To enrich content, we must either receive a valid post ID or the
	 * content that should be enriched.
	 */
	public function wp_ajax_go_oc_enrich()
	{
		header( 'Content-type: application/json' );

		// content may be passed in via POST
		$content = NULL;
		$post_id = NULL;

		if ( isset( $_REQUEST['content'] ) )
		{
			$content = wp_kses_data( $_REQUEST['content'] );
		}//end if

		if ( isset( $_REQUEST['post_id'] ) )
		{
			$post_id = absint( $_REQUEST['post_id'] );
		}//end if

		if ( NULL === $post_id )
		{
			$this->ajax_error( 'post id was not provided' );
		}//end if

		if ( ! current_user_can( 'edit_post' , $post_id ) )
		{
			$this->ajax_error( "no permission to edit post $post_id" );
		} // END if

		if( ! ( $post = get_post( $post_id ) ) )
		{
			$this->ajax_error( "invalid post id $post_id" );
		}//end if

		// temporary content override
		if( $content )
		{
			$post->post_content = $content;
		}//end if

		$enrich = $this->new_enrich_obj( $post );

		$result = $enrich->enrich();
		if( is_wp_error( $result ) )
		{
			$this->ajax_error( $result );
		}//end if

		$result = $enrich->save();
		if( is_wp_error( $result ) )
		{
			$this->ajax_error( $result );
		}//end if

		echo json_encode( $enrich->response );

		die;
	}//end wp_ajax_go_oc_enrich


	// a singleton for the admin object
	public function new_enrich_obj( $post )
	{
		// fail if the config isn't set
		if ( ! isset( $this->config['api_key'], $this->config['mapping'] ) )
		{
			return FALSE;
		}

		if ( ! $this->enrich_loaded )
		{
			require_once __DIR__ . '/class-go-opencalais-enrich.php';
			$this->enrich_loaded = TRUE;
		}

		return new GO_OpenCalais_Enrich( $post );
	} // END admin

	/**
	 *
	 */
	public function _filter_response_threshold( $member )
	{
		if( isset( $member->relevance ) )
		{
			return $member->relevance > $this->config['confidence_threshold_default'];
		}//end if

		// if there was no relevance, just let it through
		return TRUE;
	}//end _filter_response_threshold
}//end class