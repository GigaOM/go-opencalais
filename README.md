# === Gigaom OpenCalais ===

Contributors: abackstrom, borkweb, methnen, misterbisson, zbtirrell

Tags: bSuite, taxonomies, terms, tag suggestions, opencalais

License: GPLv2

Requires at least: 3.7

Tested up to: 4.0

Stable tag: trunk

Easier tagging thanks to suggestions from OpenCalais

## == Description ==

Integrates [OpenCalais](http://www.opencalais.com) tag suggestions in the WordPress edit interface. Supports multiple and custom taxonomies. Maps between [OpenCalais entities and facts](http://www.opencalais.com/documentation/calais-web-service-api/api-metadata/entity-index-and-definitions) and WordPress taxonomies, including custom taxonomies.

Suggested tags are shown in the editor interface in the same UI sections where tags are typically added.

Use of this plugin requires an API key and is limited by the API license and terms of service. Register for an API key at http://www.opencalais.com/user/register .

See installation section for custom configuration instructions.

### = Batch auto-tagging =

The plugin also includes (not pretty) tools for automatically tagging previously published content. Logged-in users with sufficient permissions can visit `wp-admin/admin-ajax.php?action=go_opencalais_autotag` which will automatically start tagging content.

### = In the WordPress.org plugin repo =

Eventually at: https://wordpress.org/plugins/go-opencalais/

### = Fork me! =

This plugin is on Github: https://github.com/GigaOM/go-opencalais

## == Installation ==

1. Place the plugin folder in your `wp-content/plugins/` directory and activate it.
1. Follow the configuration instructions below to set the `api_key`

### = Configuration =

In your theme or another plugin, add a filter on the `go_config` hook that returns an array of taxonomies when the the 2nd paramter is `go-opencalais`

* Config array format example:

	```php
	array(
		'api_key' => 'insert your api key here',
		'post_types' => array( 'post' ),
		'confidence_threshold' => 0,
		// Number of characters to allow for stuggested tags
		'max_tag_length' => 100,
		// The number of allowed ignored tags per post
		'max_ignored_tags' => 30,
		'mapping' => array(
			// In the format of 'OpenCalais entity name' => 'WordPress taxonomy',
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
	);
	```

That configuration is where the `api_key` is specified, as well as mapping between OpenCalais entities and WordPress taxonomies, valid post types on which to suggest terms, etc.