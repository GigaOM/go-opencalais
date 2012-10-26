<?php

class GO_OpenCalais
{
	// Max length of an ignored tag (used during sanitization)
	const TAG_LENGTH = 100;

	// Max number of ignored tags per taxonomy
	const IGNORED_TAGS = 30;

	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->hooks();
	}//end __construct

	public function action_admin_enqueue_scripts( $hook_suffix )
	{
		if ( 'post.php' != $hook_suffix )
		{
			return;
		}//end if

		wp_enqueue_script( 'go_opencalais', plugins_url( 'js/go-oc.js', __FILE__ ), 'jquery', 1, true );
		wp_enqueue_style( 'go_opencalais_css', plugins_url( 'css/go-oc.css', __FILE__ ), null, 1 );
		wp_enqueue_style( 'go_opencalais_dyn_css', admin_url( 'admin-ajax.php?action=go_oc_css' ), null, 1 );
	}//end action_admin_enqueue_scripts

	public function action_admin_footer_post( $hook_suffix )
	{
		global $action, $post, $GO_OPENCALAIS_MAPPING;

		if ( 'edit' !== $action )
		{
			return;
		}//end if

		$meta = (array) get_post_meta( $post->ID, 'go_oc_settings', true );

		if ( ! isset( $meta['ignored'] ))
		{
			$meta['ignored'] = array();
		}//end if

		$ignored = json_encode( $meta['ignored'] );

		// Sanitize taxonomy mapping
		$mapping = array();
		foreach ( $GO_OPENCALAIS_MAPPING as $remote => $local )
		{
			// valid local taxonomy and clean remote taxonomy
			if ( ! taxonomy_exists( $local ) || ! preg_match( '/^[a-z]{1,50}$/i', $remote ) )
			{
				continue;
			}//end if

			$mapping[$remote] = $local;
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
		if ( 'post' !== $post->post_type )
		{
			return;
		}//end if

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
				$tag = substr( trim( $tag ), 0, self::TAG_LENGTH );
				$clean_tags[] = wp_filter_nohtml_kses( $tag );

				if ( count($clean_tags) > self::IGNORED_TAGS )
				{
					break;
				}//end if
			}//end foreach

			$ignore[$tax] = $clean_tags;
		}//end foreach

		$meta = (array) get_post_meta( $post_id, 'go_oc_settings', true );
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

	/**
	 *
	 */
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
		$this->threshold = apply_filters( 'go_oc_threshold', defined( 'GO_OPENCALAIS_THRESHOLD' ) ? GO_OPENCALAIS_THRESHOLD : .1 );
		return array_filter( $response, array( $this, '_filter_response_threshold' ) );
	}//end filter_response_threshold

	public function hooks()
	{
		add_action( 'admin_enqueue_scripts', array( $this, 'action_admin_enqueue_scripts' ) );
		add_action( 'admin_footer-post.php', array( $this, 'action_admin_footer_post' ) );
		add_action( 'wp_ajax_go_oc_css', array( $this, 'wp_ajax_go_oc_css' ) );
		add_action( 'wp_ajax_go_oc_enrich', array( $this, 'wp_ajax_go_oc_enrich' ) );
		add_action( 'save_post', array( $this, 'action_save_post' ), 10, 2 );

		add_filter( 'go_oc_response', array( $this, 'filter_normalize_relevance' ), 5 );
		add_filter( 'go_oc_response', array( $this, 'filter_response_threshold' ), 10 );
	}//end hooks

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
		$content = null;
		$post_id = null;

		if ( isset( $_REQUEST['content'] ) )
		{
			$content = wp_kses_data( $_REQUEST['content'] );
		}//end if

		if ( isset( $_REQUEST['post_id'] ) )
		{
			$post_id = absint( $_REQUEST['post_id'] );
		}//end if

		if ( null === $post_id )
		{
			$this->ajax_error( "post id was not provided" );
		}//end if

		if( ! ( $post = get_post( $post_id ) ) )
		{
			$this->ajax_error( "invalid post id $post_id" );
		}//end if

		// temporary content override
		if( $content )
		{
			$post->post_content = $content;
		}//end if

		$enrich = new go_opencalais_enrich( $post );

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

		die();
	}//end wp_ajax_go_oc_enrich

	/**
	 *
	 */
	public function _filter_response_threshold( $member )
	{
		if( isset( $member->relevance ) )
		{
			return $member->relevance > $this->threshold;
		}//end if

		// if there was no relevance, just let it through
		return true;
	}//end _filter_response_threshold
}//end class
