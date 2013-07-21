<?php
/*
Plugin Name: GigaOM OpenCalais
Plugin URI:
Description:
Version: 0.2
Author: Adam Backstrom for GigaOM
Author URI: http://sixohthree.com/
License: GPL2
After upgrade/install, please run: /wp-admin/admin-ajax.php?action=oc_autotag_update
*/

require_once __DIR__ . '/components/class-go-opencalais.php';
go_opencalais();
