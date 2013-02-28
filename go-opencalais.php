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

require_once __DIR__ . '/components/class-go-opencalais.php';
require_once __DIR__ . '/components/class-go-opencalais-enrich.php';
require_once __DIR__ . '/components/class-go-opencalais-autotagger.php';
require_once __DIR__ . '/components/functions.php';

add_action( 'init', 'go_oc_extras_init', 1 );

go_opencalais();
new GO_OpenCalais_AutoTagger;
