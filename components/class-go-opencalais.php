<?php

class GO_OpenCalais
{
	public $slug = 'go-opencalais';
	public $post_meta_key = 'go-opencalais';
	public $autotagger = NULL;
	public $admin = NULL;
	public $config = NULL;

	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->config();

		// Make sure we've got an api_key and are in the admin panel before continuing
		if ( ! $this->config( 'api_key' ) || ! is_admin() )
		{
			return;
		} // END if

		$this->admin();
	}//end __construct

	/**
	 * Get config settings
	 */
	public function config( $key = NULL )
	{
		if ( ! $this->config )
		{
			$this->config = apply_filters(
				'go_config',
				array(
					'api_key' => FALSE,
					'post_types' => array( 'post' ),
					'confidence_threshold' => 0,
					// Number of characters to allow for stuggested tags
					'max_tag_length' => 100,
					// The number of allowed ignored tags per post
					'max_ignored_tags' => 30,
					'mapping' => array(
						// 'Open Calais entity name' => 'WordPress taxonomy',
						'socialTag' => 'post_tag',
					),
					'autotagger' => array(
						// Term to indicate when a post has been autotagged already
						'term' => 'autotagged',
						// The relevance threshold at which to not add a tag
						'threshold' => 0.29,
						// Number of posts to auto tag per batch
						'num' => 5,
					),
				),
				'go-opencalais'
			);
		}//END if

		if ( ! empty( $key ) )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL ;
		}

		return $this->config;
	}//end config

	/**
	 * a singleton for the admin object
	 */
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-opencalais-admin.php';
			$this->admin = new GO_OpenCalais_Admin();
		}//end if

		return $this->admin;
	} // END admin

	/**
	 * a singleton for the enrich object
	 */
	public function enrich( $post )
	{
		return $this->admin()->enrich( $post );
	}//end enrich

	/**
	 * a singleton for the autotagger object
	 */
	public function autotagger()
	{
		if ( ! $this->autotagger )
		{
			require_once __DIR__ . '/class-go-opencalais-autotagger.php';
			$this->autotagger = new GO_OpenCalais_AutoTagger();

			// also load the admin object, in case it hasn't already been loaded
			$this->admin();
		}// end if

		return $this->autotagger;
	} // END autotagger

	/**
	 * Return an array of the local taxonomies that go-opencalais is configured to work with
	 */
	public function get_local_taxonomies()
	{
		$local_taxonomies = array();

		foreach ( $this->config( 'mapping' ) as $local_taxonomy )
		{
			if ( ! isset( $local_taxonomies[ $local_taxonomy ] ) )
			{
				$local_taxonomies[ $local_taxonomy ] = $local_taxonomy;
			} // END if
		} // END foreach

		return $local_taxonomies;
	} // END get_local_taxonomies

	/**
	 * Get post meta
	 */
	public function get_post_meta( $post_id )
	{
		if ( ! $meta = get_post_meta( $post_id, $this->post_meta_key, TRUE ) )
		{
			return array();
		} // END if

		return $meta;
	} // END get_post_meta
}//end class

function go_opencalais()
{
	global $go_opencalais;

	if ( ! isset( $go_opencalais ) )
	{
		$go_opencalais = new GO_OpenCalais();
	}// end if

	return $go_opencalais;
}// end go_opencalais