<?php
/**
 * Plugin Name: Gigaom OpenCalais
 * Plugin URI: http://github.com/GigaOM/go-opencalais/
 * Description: WordPress integration with the OpenCalais API
 * Version: 1.0
 * Author: A Backstrom, Gigaom
 * Author URI: http://gigaom.com/
 * License: GPL2
 * After upgrade/install, please run: /wp-admin/admin-ajax.php?action=oc_autotag_update
 */

require_once __DIR__ . '/components/class-go-opencalais.php';
go_opencalais();