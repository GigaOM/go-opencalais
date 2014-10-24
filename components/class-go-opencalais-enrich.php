<?php

class GO_OpenCalais_Enrich
{
	public $api_url = 'http://api.opencalais.com/tag/rs/enrich';

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

	public function __construct( $post )
	{
		$this->post = $post;
	}//end __construct

	/**
	 * Enrich a post (i.e. call OpenCalais and get the suggested tags for the post's content)
	 */
	public function enrich()
	{
		$content = apply_filters( 'go_opencalais_content', $this->post->post_title . "\n\n" . $this->post->post_excerpt . "\n\n" . $this->post->post_content, $this->post );

		if ( empty( $content ) )
		{
			return new WP_Error( 'empty-content', 'Cannot enrich empty post', $this->post );
		}//end if

		$args = array(
			'body'    => $content,
			'headers' => array(
				'X-calais-licenseID' => go_opencalais()->config( 'api_key' ),
				'Accept'             => 'application/json',
				'Content-type'       => 'text/html',
				'enableMetadataType' => 'SocialTags',
			),
		);

		$response         = wp_remote_post( $this->api_url, $args );
		$response_code    = wp_remote_retrieve_response_code( $response );
		$response_message = wp_remote_retrieve_response_message( $response );
		$response_body    = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) )
		{
			return $response;
		}//end if
		elseif ( 200 != $response_code && ! empty( $response_message ) )
		{
			return new WP_Error( 'ajax-error', $response_message );
		}//end elseif
		elseif ( 200 != $response_code && ! empty( $response_body ) )
		{
			return new WP_Error( 'ajax-error', $response_body );
		}//end elseif
		elseif ( 200 != $response_code )
		{
			return new WP_Error( 'ajax-error', 'Unknown error occurred' );
		}//end elseif

		$this->response_raw = (array) json_decode( $response_body );
		$this->response     = apply_filters( 'go_opencalais_response', $this->response_raw, $this->post->ID, $this->post );
	}//end enrich

	/**
	 * Save OpenCalais resposne to the post meta
	 */
	public function save()
	{
		$meta = go_opencalais()->get_post_meta( $this->post->ID );

		$meta['enrich']            = json_encode( $this->response );
		$meta['enrich_unfiltered'] = json_encode( $this->response_raw );

		update_post_meta( $this->post->ID, go_opencalais()->post_meta_key, $meta );
	}//end save
}//end class
