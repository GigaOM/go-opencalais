<?php
/*
Plugin Name: GO OpenCalais
Plugin URI: 
Description: 
Version: 0.1
Author: Adam Backstrom for GigaOM
Author URI: http://sixohthree.com/
License: GPL2
*/

/*

define( 'GO_OPENCALAIS_KEY', 'your key here' );

define( 'GO_OPENCALAIS_THRESHOLD', 0.5 );

$GLOBALS['GO_OPENCALAIS_MAPPING'] = array(
	// OpenCalas Tax         Local tax
	'Company'             => 'company',
	'Organization'        => 'company',

	'Person'              => 'post_tag',
);

*/

class go_opencalais {
	// Max length of an ignored tag (used during sanitization)
	const TAG_LENGTH = 100;

	// Max number of ignored tags per taxonomy
	const IGNORED_TAGS = 30;

	public function __construct() {
		$this->hooks();
	}//end __construct

	public function action_admin_enqueue_scripts( $hook_suffix ) {
		if( 'post.php' != $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'go_opencalais', plugins_url( 'go-oc.js', __FILE__ ), 'jquery', 1, true );
		wp_enqueue_style( 'go_opencalais_css', plugins_url( 'go-oc.css', __FILE__ ), null, 1 );
		wp_enqueue_style( 'go_opencalais_dyn_css', admin_url( 'admin-ajax.php?action=go_oc_css' ), null, 1 );
	}//end action_admin_enqueue_scripts

	public function action_admin_footer_post( $hook_suffix ) {
		global $action, $post, $GO_OPENCALAIS_MAPPING;

		if( 'edit' !== $action ) {
			return;
		}

		$meta = (array) get_post_meta( $post->ID, 'go_oc_settings', true );

		if( ! isset( $meta['ignored'] )) {
			$meta['ignored'] = array();
		}

		$ignored = json_encode( $meta['ignored'] );

		// Sanitize taxonomy mapping
		$mapping = array();
		foreach( $GO_OPENCALAIS_MAPPING as $remote => $local ) {
			// valid local taxonomy and clean remote taxonomy
			if( ! taxonomy_exists( $local ) || ! preg_match( '/^[a-z]{1,50}$/i', $remote ) ) {
				continue;
			}

			$mapping[$remote] = $local;
		}

		?>
		<script type="text/javascript">
		go_oc_ignored_tags = <?php echo $ignored; ?>;
		go_oc_taxonomy_map = <?php echo json_encode( $mapping ); ?>;
		</script>
		<?php
	}//end action_admin_footer_post

	public function hooks() {
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
	public function wp_ajax_go_oc_css() {
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
	public function wp_ajax_go_oc_enrich() {
		header( 'Content-type: application/json' );

		// content may be passed in via POST
		$content = null;
		$post_id = null;

		if( isset( $_REQUEST['content'] ) ) {
			$content = wp_kses_data( $_REQUEST['content'] );
		}

		if( isset( $_REQUEST['post_id'] ) ) {
			$post_id = absint( $_REQUEST['post_id'] );
		}

		if( null === $post_id ) {
			$this->_ajax_error( "post id was not provided" );
		}

		if( ! ( $post = get_post( $post_id ) ) ) {
			$this->_ajax_error( "invalid post id $post_id" );
		}

		// temporary content override
		if( $content ) {
			$post->post_content = $content;
		}

		$enrich = new go_opencalais_enrich( $post );

		$result = $enrich->enrich();
		if( is_wp_error( $result ) ) {
			$this->_ajax_error( $result );
		}

		$result = $enrich->save();
		if( is_wp_error( $result ) ) {
			$this->_ajax_error( $result );
		}

		echo json_encode( $enrich->response );

		die();
	}

	/**
	 * Sanitize incoming tax_ignore[taxonomy] values, which are comma-
	 * separated lists of ignored tags from textareas, a la built-in .the-tags
	 */
	public function action_save_post( $post_id, $post ) {
		if( 'post' !== $post->post_type ) {
			return;
		}

		if( ! isset( $_POST['tax_ignore'] ) ||
			! is_array($_POST['tax_ignore']) ) {
			return;
		}

		$ignore = array();
		foreach( $_POST['tax_ignore'] as $tax => $tags ) {
			if( ! taxonomy_exists( $tax ) ) {
				continue;
			}

			// not all of these tags will exist as valid terms, they are merely
			// tags returned by OpenCalais that we want to ignore
			$tags = explode( ',', $tags );

			// Do some basic sanitization on tags (tag length, number of tags)
			$clean_tags = array();
			foreach( $tags as $tag ) {
				$tag = substr( trim( $tag ) , 0 , self::TAG_LENGTH );
				$clean_tags[] = wp_filter_nohtml_kses( $tag );

				if( count($clean_tags) > self::IGNORED_TAGS ) {
					break;
				}
			}

			$ignore[$tax] = $clean_tags;
		}

		$meta = (array) get_post_meta( $post_id, 'go_oc_settings', true );
		if( empty($meta) ) {
			$meta = array();
		}

		$meta['ignored'] = $ignore;
		update_post_meta( $post_id, 'go_oc_settings', $meta );
	}//end action_save_post

	/**
	 *
	 */
	public function filter_normalize_relevance( $response ) {
		$max_relevance = 0;

		// first, find max
		foreach( $response as $object ) {
			if( isset( $object->relevance ) && $object->relevance > $max_relevance ) {
				$max_relevance = $object->relevance;
			}
		}

		// then normalize
		foreach( $response as &$object ) {
			if( isset( $object->relevance ) ) {
				$object->_go_orig_relevance = $object->relevance;

				$relevance = ( 1 / $max_relevance ) * $object->relevance;
				$relevance = round( $relevance, 3 );

				$object->relevance = $relevance;
			}
		}

		return $response;
	}//end filter_response_threshold

	/**
	 *
	 */
	public function filter_response_threshold( $response ) {
		$this->threshold = apply_filters( 'go_oc_threshold', defined( 'GO_OPENCALAIS_THRESHOLD' ) ? GO_OPENCALAIS_THRESHOLD : .1 );
		return array_filter( $response, array( $this, '_filter_response_threshold' ) );
	}//end filter_response_threshold

	/**
	 *
	 */
	public function _filter_response_threshold( $member ) {
		if( isset( $member->relevance ) ) {
			return $member->relevance > $this->threshold;
		}

		// if there was no relevance, just let it through
		return true;
	}//end _filter_response_threshold

	protected function _ajax_error( $message ) {
		if( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}

		echo json_encode(
			array(
				'error' => $message,
			)
		);

		die();
	}//end _ajax_error
}

class go_opencalais_enrich {
	const OC_URL = 'http://api.opencalais.com/tag/rs/enrich';

	/**
	 * The post we're enriching.
	 */
	public $post;

	/**
	 * The OpenCalais API response, as an array.
	 */
	public $response;

	/**
	 * The original API response before filters are run,
	 * as an array.
	 */
	public $response_raw;

	public function __construct( $post ) {
		$this->post = $post;
	}

	public function enrich() {
		$content = apply_filters( 'go_oc_content', $this->post->post_content,
			$this->post->ID , $this->post );

		if( empty( $content ))
			return new WP_Error( 'empty-content', 'Cannot enrich empty post', $this->post );

		$args = array(
			'body' => $content,
			'headers' => array(
				'X-calais-licenseID' => GO_OPENCALAIS_KEY,
				'Accept' => 'application/json',
				'Content-type' => 'text/html',
			),
		);

		$response         = wp_remote_post( self::OC_URL, $args );
		$response_code    = wp_remote_retrieve_response_code( $response );
		$response_message = wp_remote_retrieve_response_message( $response );
		$response_body    = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) )
			return $response;
		elseif ( 200 != $response_code && ! empty( $response_message ) )
			return new WP_Error( 'ajax-error', $response_message );
		elseif ( 200 != $response_code && ! empty( $response_body ) )
			return new WP_Error( 'ajax-error', $response_body );
		elseif ( 200 != $response_code )
			return new WP_Error( 'ajax-error', 'Unknown error occurred' );

		$this->response_raw = (array) json_decode( $response_body );
		$this->response     = apply_filters( 'go_oc_response', $this->response_raw, $this->post->ID , $this->post );
	}//end enrich

	public function save() {
		$meta = (array) get_post_meta( $this->post->ID, 'go_oc_settings', true );

		if( empty($meta) ) {
			$meta = array();
		}

		$meta['enrich'] = json_encode( $this->response );
		$meta['enrich_unfiltered'] = json_encode( $this->response_raw );
		update_post_meta( $this->post->ID, 'go_oc_settings', $meta );
	}//end save
}//end class go_opencalais_enrich

new go_opencalais();
