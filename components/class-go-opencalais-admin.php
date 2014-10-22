<?php

class GO_OpenCalais_Admin
{
	public $enrich_loaded = FALSE;

	private $dependencies = array(
		'go-ui' => 'https://github.com/GigaOM/go-ui',
	);
	private $missing_dependencies = array();

	/**
	 * constructor
	 */
	public function __construct()
	{
		// check to see if the API is set and we have mappings befor adding hooks
		add_action( 'init', array( $this, 'init' ), 2 );
	}//end __construct

	/**
	 * Start things up!
	 */
	public function init()
	{
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_footer-post.php', array( $this, 'action_admin_footer_post' ) );
		add_action( 'wp_ajax_go_opencalais_enrich', array( $this, 'ajax_enrich' ) );
		add_action( 'wp_ajax_go_opencalais_content', array( $this, 'ajax_content' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		add_filter( 'go_opencalais_response', array( $this, 'filter_insert_socialtags_as_entities' ), 1 );
		add_filter( 'go_opencalais_response', array( $this, 'filter_normalize_relevance' ), 3 );
		add_filter( 'go_opencalais_response', array( $this, 'filter_response_threshold' ), 7 );

		// check if the autotagger is configged before loading it
		if ( is_array( go_opencalais()->config( 'autotagger' ) ) )
		{
			// Register the OpenCalais Autotagger taxonomy
			register_taxonomy(
				go_opencalais()->slug . '-autotagger',
				go_opencalais()->config( 'post_types' ),
					array(
						'label'     => 'OpenCalais Autotagger',
						'query_var' => FALSE,
						'rewrite'   => FALSE,
						'show_ui'   => FALSE,
					)
			);

			go_opencalais()->autotagger();
		}//end if
	}//end init

	/**
	 * Setup scripts and check dependencies for the admin interface
	 */
	public function admin_enqueue_scripts( $hook_suffix )
	{
		$this->check_dependencies();

		if ( $this->missing_dependencies )
		{
			return;
		}//end if

		// make sure go-ui has been instantiated and its resources registered
		go_ui();

		if ( 'post.php' != $hook_suffix )
		{
			return;
		}//end if

		$script_config = apply_filters( 'go-config', array( 'version' => 1 ), 'go-script-version' );

		wp_enqueue_script( 'handlebars' );
		wp_enqueue_script( go_opencalais()->slug, plugins_url( 'js/go-opencalais.js', __FILE__ ), array( 'jquery' ), $script_config['version'], TRUE );
		wp_enqueue_style( go_opencalais()->slug . '-css', plugins_url( 'css/go-opencalais.css', __FILE__ ), array(), $script_config['version'] );
		wp_enqueue_style( 'fontawesome' );

		$post = get_post();
		$meta = go_opencalais()->get_post_meta( $post->ID );

		$localized_values = array(
			'post_id'          => $post->ID,
			'nonce'            => wp_create_nonce( 'go-opencalais' ),
			'ignored_by_tax'   => isset( $meta['ignored-tags'] ) ? $meta['ignored-tags'] : array(),
			'taxonomy_map'     => $this->get_sanitized_mapping(),
			'local_taxonomies' => go_opencalais()->get_local_taxonomies(),
			'suggested_terms'  => array(),
		);

		wp_localize_script( go_opencalais()->slug, 'go_opencalais', $localized_values );
	}//end admin_enqueue_scripts

	/**
	 * check plugin dependencies
	 */
	public function check_dependencies()
	{
		foreach ( $this->dependencies as $dependency => $url )
		{
			if ( function_exists( str_replace( '-', '_', $dependency ) ) )
			{
				continue;
			}//end if

			$this->missing_dependencies[ $dependency ] = $url;
		}//end foreach

		if ( $this->missing_dependencies )
		{
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		}//end if
	}//end check_dependencies

	/**
	 * hooked to the admin_notices action to inject a message if depenencies are not activated
	 */
	public function admin_notices()
	{
		?>
		<div class="error">
			<p>
				You must <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">activate</a> the following plugins before using <code>bstat</code>'s report:
			</p>
			<ul>
				<?php
				foreach ( $this->missing_dependencies as $dependency => $url )
				{
					?>
					<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $dependency ); ?></a></li>
					<?php
				}//end foreach
				?>
			</ul>
		</div>
		<?php
	}//end admin_notices

	/**
	 * Return a sanitized version of the taxonomy => OpenCalais entity mapping
	 */
	public function get_sanitized_mapping()
	{
		$mapping = array();

		foreach ( go_opencalais()->config( 'mapping' ) as $remote => $local )
		{
			// valid local taxonomy and clean remote taxonomy
			if ( ! taxonomy_exists( $local ) || ! preg_match( '/^[a-z]{1,50}$/i', $remote ) )
			{
				continue;
			}//end if

			$mapping[ $remote ] = $local;
		}//end foreach

		return $mapping;
	}//end get_sanitized_mapping

	/**
	 * Set handlebars.js templates
	 */
	public function action_admin_footer_post( $hook_suffix )
	{
		global $action, $post;

		if ( 'edit' !== $action )
		{
			return;
		}//end if

		?>
		<script id="go-opencalais-handlebars-tags" type="text/x-handlebars-template">
			<div class="go-opencalais">
				<div>
					<a href="#" class="go-opencalais-taggroup go-opencalais-suggested">Suggested Tags</a>
					<a href="#" class="go-opencalais-refresh">Refreshing...</a>
					<div class="go-opencalais-taglist go-opencalais-suggested-list"></div>
				</div>
				<div>
					<a href="#" class="go-opencalais-taggroup go-opencalais-ignored">Ignored Tags</a>
					<div style="display:none;" class="go-opencalais-taglist go-opencalais-ignored-list"></div>
				</div>
			</div>
		</script>
		<script id="go-opencalais-handlebars-nonce" type="text/x-handlebars-template">
			<input type="hidden" id="go-opencalais-nonce" name="go-opencalais-nonce" value="{{nonce}}" />
		</script>
		<script id="go-opencalais-handlebars-ignore" type="text/x-handlebars-template">
			<textarea name="tax_ignore[{{taxonomy}}]" class="the-ignored-tags" id="tax-ignore-{{taxonomy}}">{{ignored_taxonomies}}</textarea>
		</script>
		<script id="go-opencalais-handlebars-tag" type="text/x-handlebars-template">
			<span><a class="go-opencalais-ignore" title="Ignore tag"><i class="fa fa-times-circle"></i></a>&nbsp;<a class="go-opencalais-use">{{name}}</a></span>
		</script>
		<?php
	}//end action_admin_footer_post

	/**
	 * Sanitize incoming tax_ignore[taxonomy] values, which are comma-
	 * separated lists of ignored tags from textareas, a la built-in .the-tags
	 */
	public function save_post( $post_id, $post )
	{
		// Check nonce
		if (
			   ! isset( $_POST['go-opencalais-nonce'] )
			|| ! wp_verify_nonce( $_POST['go-opencalais-nonce'], 'go-opencalais' )
		)
		{
			return;
		}// end if

		// Check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		{
			return;
		}// end if

		if ( ! is_object( $post ) )
		{
			return;
		}// end if

		// check post type matches what you intend
		if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, go_opencalais()->config( 'post_types' ) ) )
		{
			return;
		}// end if

		// Don't run on post revisions (almost always happens just before the real post is saved)
		if ( wp_is_post_revision( $post->ID ) )
		{
			return;
		}// end if

		// Check the permissions
		if ( ! current_user_can( 'edit_post', $post->ID ) )
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
				$tag = substr( trim( $tag ), 0, go_opencalais()->config( 'max_tag_length' ) );
				$clean_tags[] = wp_kses( $tag, array() );

				if ( count( $clean_tags ) > go_opencalais()->config( 'max_ignored_tags' ) )
				{
					break;
				}//end if
			}//end foreach

			$ignore[ $tax ] = $clean_tags;
		}//end foreach

		$meta = go_opencalais()->get_post_meta( $post->ID );
		$meta['ignored-tags'] = $ignore;

		update_post_meta( $post_id, go_opencalais()->post_meta_key, $meta );
	}//end save_post

	/**
	 * Filter the response to add socialTags as entities when there's no other entity with the same value
	 */
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

				}// end switch
			}// end if
		}// end foreach

		// identify the unqique tags and insert additional elements so they can be treated as entities
		foreach ( array_diff( $tags, $entities ) as $k => $v )
		{
			$response[ $k ]->_type = 'socialTag';
			$response[ $k ]->relevance = (float) '0.6';
		}//end foreach

		return $response;
	}//end filter_insert_socialtags_as_entities

	/**
	 * Filter the response to normalize the relevance values
	 */
	public function filter_normalize_relevance( $response )
	{
		$max_relevance = 0;

		// first, find max
		foreach( $response as $object )
		{
			if ( isset( $object->relevance ) && $object->relevance > $max_relevance )
			{
				$max_relevance = $object->relevance;
			}//end if
		}//end foreach

		// then normalize
		foreach( $response as &$object )
		{
			if ( isset( $object->relevance ) )
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
	 * Filter the response to handle the tag relevance threshold
	 */
	public function filter_response_threshold( $response )
	{
		return array_filter( $response, array( $this, '_filter_response_threshold' ) );
	}//end filter_response_threshold

	/**
	 * To enrich content, we must either receive a valid post ID or the
	 * content that should be enriched.
	 */
	public function ajax_enrich()
	{
		// Check nonce
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'go-opencalais' ) )
		{
			wp_send_json_error( array( 'message' => 'You do not have permission to be here.' ) );
		}// end if

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
			wp_send_json_error( array( 'message' => 'No post_id provided.' ) );
		}//end if

		if ( ! ( $post = get_post( $post_id ) ) )
		{
			wp_send_json_error( array( 'message' => 'This is not a valid post.' ) );
		}//end if

		if ( ! current_user_can( 'edit_post', $post_id ) )
		{
			wp_send_json_error( array( 'message' => 'You do not have permission to edit this post.' ) );
		}//end if

		// Override post content for this request if needed
		if ( $content )
		{
			$post->post_content = $content;
		}//end if

		// Call OpenCalais
		$enrich = $this->enrich( $post );

		$result = $enrich->enrich();

		if ( is_wp_error( $result ) )
		{
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}//end if

		$result = $enrich->save();

		if ( is_wp_error( $result ) )
		{
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}//end if

		// Send the response back
		wp_send_json( $enrich->response );
	}//end ajax_enrich

	/**
	 * Retrieves content to be used for getting suggestions
	 */
	public function ajax_content()
	{
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'go-opencalais' ) )
		{
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		} // END if

		if ( ! isset( $_GET['post_id'] ) )
		{
			wp_send_json_error( array( 'message' => 'No post ID given.' ) );
		} // END if

		if ( ! $post = get_post( $_GET['post_id'] ) )
		{
			wp_send_json_error( array( 'message' => 'Post does not exist.' ) );
		} // END if

		if ( ! current_user_can( 'edit_post', $post->ID ) )
		{
			wp_send_json_error( array( 'message' => 'You do not have permission to edit this post.' ) );
		} // END if

		// Allow scripts to modify the content we send
		$content = apply_filters( 'go_opencalais_content', $post->post_title . "\n\n" . $post->post_excerpt . "\n\n" . $post->post_content, $post );
		wp_send_json_success( array( 'content' => $content ) );
	}//end ajax_content

	/**
 	 * a singleton for the enrich object
 	 */
	public function enrich( $post )
	{
		// fail if the config isn't set
		if ( ! go_opencalais()->config( 'api_key' ) )
		{
			return FALSE;
		}//end if

		if ( ! $this->enrich_loaded )
		{
			require_once __DIR__ . '/class-go-opencalais-enrich.php';
			$this->enrich_loaded = TRUE;
		}//end if

		return new GO_OpenCalais_Enrich( $post );
	}//end enrich

	/**
	 * Check relevence of the member
	 */
	public function _filter_response_threshold( $member )
	{
		if ( isset( $member->relevance ) )
		{
			return $member->relevance > go_opencalais()->config( 'confidence_threshold' );
		}//end if

		// if there was no relevance, just let it through
		return TRUE;
	}//end _filter_response_threshold
}//end class
