(function($){
	'use strict';

	go_opencalais.init = function() {
		$(document).on( 'click', '.go-opencalais-taggroup', go_opencalais.tags_toggle );
		$(document).on( 'click', '.go-opencalais-use', go_opencalais.tag_use );
		$(document).on( 'click', '.go-opencalais-ignore', go_opencalais.tag_ignore );
		$(document).on( 'click', '.go-opencalais-refresh', go_opencalais.tag_refresh );

		go_opencalais.first_run = true;

	  	go_opencalais.setup_templates();
		go_opencalais.ignored_terms();
		go_opencalais.prep_metaboxes();

		$( '#post input:first' ).after( go_opencalais.templates.nonce( { nonce: go_opencalais.nonce } ) );

		// Call OpenCalais
		go_opencalais.enrich();
	};

	// Initialize some templates for use later
	go_opencalais.setup_templates = function() {
		go_opencalais.templates = {
			tags:   Handlebars.compile( $("#go-opencalais-handlebars-tags").html() ),
			nonce:  Handlebars.compile( $("#go-opencalais-handlebars-nonce").html() ),
			ignore: Handlebars.compile( $("#go-opencalais-handlebars-ignore").html() ),
			tag:    Handlebars.compile( $("#go-opencalais-handlebars-tag").html() ),
		}
	};

	// Handle ignored terms
	go_opencalais.ignored_terms = function() {
		$( '.the-tags' ).each(function() {
			var taxonomy = $( this ).attr( 'id' ).substr( 10 );

			if ( go_opencalais.ignored_by_tax.hasOwnProperty( taxonomy ) ) {
				$( go_opencalais.templates.ignore({
					taxonomy: taxonomy,
					ignored_taxonomies: go_opencalais.ignored_by_tax[ taxonomy ].join( ',' )
				}) ).insertAfter( this );
			}
		});
	};

	// Prep the tag metaboxes with the initial OpenCalais interface
	go_opencalais.prep_metaboxes = function() {
		$( '.the-tags' ).each(function() {
			var taxonomy = $( this ).attr( 'id' ).substr( 10 );

			if ( go_opencalais.local_taxonomies.hasOwnProperty( taxonomy ) ) {
				$( '#tagsdiv-' + taxonomy + ' .inside' ).append( go_opencalais.templates.tags );
			}
		});
	};

	// Call OpenCalais and get the suggested tags
	go_opencalais.enrich = function() {
		var params = {
			'action': 'go_opencalais_enrich',
			'post_id': go_opencalais.post_id,
			'nonce': go_opencalais.nonce
		};

		$.getJSON( ajaxurl, params, go_opencalais.enrich_callback );
	};

	// Handle response from OpenCalais
	go_opencalais.enrich_callback = function( data, text_status, xhr ) {
		// container of our local taxonomies
		var taxonomies = {};

		for ( var prop in go_opencalais.taxonomy_map ) {
			taxonomies[ go_opencalais.taxonomy_map[ prop ] ] = [];
		}//end for

		// Look at terms returned and add terms to their matching local taxonomy
		$.each( data, function( idx, obj ) {
			var type = obj._type;

			if ( go_opencalais.taxonomy_map.hasOwnProperty( type ) ) {
				taxonomies[ go_opencalais.taxonomy_map[ type ] ].push( obj );
			}//end if
		});

		$.each( taxonomies, function( taxonomy, obj ) {
			if ( 0 < obj.length ) {
				go_opencalais.enrich_taxonomy( taxonomy, obj );
			}//end if
		});

		// We could be running because of a refresh so we set this just in case
		$( '.go-opencalais-refresh' ).text( 'Refresh' );

		$(document).trigger( 'go-opencalais.complete' );

		go_opencalais.first_run = false;
	};

	// Handle suggestions for a given taxonomy
	go_opencalais.enrich_taxonomy = function( taxonomy, oc_objs ) {
		var $inside = $( '#tagsdiv-' + taxonomy + ' .inside');

		if ( ! go_opencalais.suggested_terms.hasOwnProperty( taxonomy ) ) {
			go_opencalais.suggested_terms[ taxonomy ] = {};
		}//end if

		// build list of existing tags
		var existing_tags_hash = {};

		$.each( $inside.find( '.the-tags' ).val().split(','), function( key, tag ){
			existing_tags_hash[ tag.trim() ] = true;
		});

		var ignored_tags_hash = {};
		var html = '';

		// build list of ignored tags
		$.each( $inside.find( '.the-ignored-tags' ).val().split(','), function( key, tag ){
			tag = tag.trim();

			// skip empty tags (usually if .val() above was zero length
			if ( '' === tag ) {
				return;
			}//end if

			// skip tags that are already in use
			if ( existing_tags_hash.hasOwnProperty( tag ) ) {
				return;
			}//end if

			if ( go_opencalais.first_run ) {
				html = html + go_opencalais.templates.tag( { name: tag } );
			}//end if

			ignored_tags_hash[ tag ] = true;
		});

		$inside.find('.go-opencalais-ignored-list').append( html );

		$.each( oc_objs, function( idx, obj ) {
			if ( ignored_tags_hash[ obj.name.trim() ] || existing_tags_hash[ obj.name.trim() ] ) {
				return;
			}//end if

			if ( ! go_opencalais.suggested_terms[ taxonomy ].hasOwnProperty( obj.name ) ) {
				go_opencalais.suggested_terms[ taxonomy ][ obj.name ] = true;
				html = html + go_opencalais.templates.tag( { name: obj.name } );
			}//end if
		});

		$inside.find('.go-opencalais-suggested-list').append( html );
	};

	// Toggle taglist
	go_opencalais.tags_toggle = function( e ) {
		var $obj = $( e.currentTarget );
		$obj.nextAll( '.go-opencalais-taglist' ).toggle();
		e.preventDefault();
	};

	// Use an OpenCalais tag
	go_opencalais.tag_use = function( e ) {
		tagBox.flushTags( $( this ).closest( '.inside' ).children( '.tagsdiv' ), this );

		// Remove tag after it's added
		$( this ).parent().remove();
	};

	// Toggle a suggested tag
	// @TODO This one could still use a little code cleanup but it does function as is.
	go_opencalais.tag_ignore = function( e ) {
		var $tag = $( this ).parent(),
			$inside = $tag.closest( '.inside' ),
			$ignored = $inside.find( '.go-opencalais-ignored-list '),
			tags = $inside.find( '.the-ignored-tags' ),
			taxonomy = $inside.find( '.tagsdiv' ).attr( 'id' ),
			tagsval, newtags, text;

		$tag.appendTo( $ignored );
		text = $tag.find( '.go-opencalais-use' ).text();

		delete go_opencalais.suggested_terms[ taxonomy ][ text ];

		// Borrowed from wp-admin/js/post.dev.js
		tagsval = tags.val();
		newtags = tagsval ? tagsval + ',' + text : text;

		newtags = tagBox.clean( newtags );
		newtags = array_unique_noempty( newtags.split(',') ).join(',');
		tags.val( newtags );
	};

	// Manually refresh the tag list
	go_opencalais.tag_refresh = function( e ) {
		var params = {
			'action': 'go_opencalais_enrich',
			'content': $( 'input[name="post_title"]' ).val() + "\n\n" + $( '.wp-editor-area' ).val(),
			'post_id': go_opencalais.post_id,
			'nonce': go_opencalais.nonce
		};

		$( '.go-opencalais-refresh' ).text( 'Refreshing...' );

		$.post( ajaxurl, params, go_opencalais.enrich_callback, 'json' );

		e.preventDefault();
	};

	$(function() {
		go_opencalais.init();
	});
})(jQuery);
