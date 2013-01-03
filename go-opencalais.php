<?php
/*
Plugin Name: GO OpenCalais
Plugin URI:
Description:
Version: 0.1
Author: Adam Backstrom for GigaOM
Author URI: http://sixohthree.com/
License: GPL2
After upgrade/install, please run: /wp-admin/admin-ajax.php?action=oc_autotag_update
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

require_once __DIR__ . '/components/class-go-opencalais.php';
require_once __DIR__ . '/components/class-go-opencalais-enrich.php';
require_once __DIR__ . '/components/class-go-opencalais-autotagger.php';
require_once __DIR__ . '/components/functions.php';

add_action( 'init', 'go_oc_extras_init' );

$go_opencalais = new GO_OpenCalais;
$go_opencalais_autotagger = new GO_OpenCalais_AutoTagger;
