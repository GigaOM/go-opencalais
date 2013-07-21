<?php

class GO_OpenCalais
{

	/**
	 * constructor
	 */
	public function __construct()
	{

		// only continue if this is a request in the admin dashboard
		if ( is_admin() )
		{
			add_action( 'init', array( $this, 'init' ), 1 );
		}
	}//end __construct

	// runs on init
	public function init()
	{
		// best not to run this on __construct(),
		// as the chain calls go_opencalais()
		$this->admin();
	}//end init

	// a singleton for the admin object
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-opencalais-admin.php';
			$this->admin = new GO_OpenCalais_Admin();
		}

		return $this->admin;
	} // END admin

	// a singleton for the enrich object
	public function new_enrich_obj( $post )
	{
		return $this->admin()->new_enrich_obj( $post );
	} // END enrich

	// a singleton for the autotagger object
	public function autotagger()
	{
		if ( ! $this->autotagger )
		{
			require_once __DIR__ . '/class-go-opencalais-autotagger.php';
			$this->admin = new GO_OpenCalais_AutoTagger();

			// also load the admin object, in case it hasn't already been loaded
			$this->admin();

		}

		return $this->autotagger;
	} // END admin


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