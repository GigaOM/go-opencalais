=== Gigaom OpenCalais ===

Tags: wordpress, taxonomies, terms, opencalais
Requires at least: 3.6.1
Tested up to: 4.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==

WordPress integration with the OpenCalais API. Requires [Gigaom UI](https://github.com/GigaOM/go-ui)

== Usage ==

1. Add a filter on the `go_config` hook that returns an array of taxonomies when the the 2nd paramter is `go-opencalais`
	* Config array format example:

		```
		array(
			'api_key' => FALSE,
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

2. There's also a batch autotagging method that can be used via an admin-ajax URL:
	* `wp-admin/admin-ajax.php?action=go_opencalais_autotag&num=5`
		* `num` being the number of posts to include per batch (there's a maximum of 20 allowed)

== Installation ==

1. Upload `go-opencalais` and `go-ui` to the `/wp-content/plugins/` directory
2. Activate 'Gigaom OpenCalais' and 'Gigaom UI' through the 'Plugins' menu in WordPress

== Contributing ==

https://github.com/GigaOM/go-opencalais/