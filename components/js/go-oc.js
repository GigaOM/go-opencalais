(function($){

// On first run, we do some one-time processing.
var first_run = true;

// List of suggested tags (not active tags, or
// ignored tags), by taxonomy.
var suggestedTagHash = {};

// Various template strings
var tmpl = '<div><a href="#" class="go-oc-taggroup go-oc-suggested">Suggested Tags</a> <a href="#" class="go-oc-refresh">refresh</a>' +
	'<div class="go-oc-taglist go-oc-suggested-list"></div></div>' +
	'<div><a href="#" class="go-oc-taggroup go-oc-ignored">Ignored Tags</a>' +
	'<div style="display:none;" class="go-oc-taglist go-oc-ignored-list"></div></div>';

// @TODO: these field names and IDs don't fit our code standards
var tmpl_nonce = '<input type="hidden" id="go-oc-nonce" name="go-oc-nonce" value="{{nonce}}"> ';
var tmpl_ignore = '<textarea name="tax_ignore[{{tax}}]" class="the-ignored-tags" id="tax-ignore-{{tax}}"></textarea>';

var tmpl_tag = '<span><a class="go-oc-ignore" title="Ignore tag">X</a>&nbsp;<a class="go-oc-use">{{name}}</a></span>';

function oc_ignored_tags() {

	html = tmpl_nonce.replace( /{{nonce}}/g, go_oc_nonce );
	$( '#post input:first' ).after( html );

	var tags = $('.the-tags');

	$.each( tags, function(){
		// id="tax-input-[taxonomy]"
		var taxonomy = $(this).attr('id').substr(10),
			html = tmpl_ignore.replace( /{{tax}}/g, taxonomy ),
			theIgnored;

		theIgnored = $(html).insertAfter( this );

		if( go_oc_ignored_tags[taxonomy] ) {
			theIgnored.val( go_oc_ignored_tags[taxonomy].join(',') );
		}
	});
}

// Callback for ob_enrich()
function oc_enrich_cb( data, textStatus, xhr ) {
	// container of our local taxonomies, and oc
	// enrich objects suggested for those taxonomies
	var taxonomies = {
	};

	var local_tax;
	for( var prop in go_oc_taxonomy_map ) {
		local_tax = go_oc_taxonomy_map[prop];
		taxonomies[local_tax] = [];
	}

	$.each( data, function(idx, obj){
		var type = obj._type;
		if( typeof go_oc_taxonomy_map[type] != 'undefined' ) {
			taxonomies[ go_oc_taxonomy_map[type] ].push( obj );
		}
	});

	$.each( taxonomies, function(tax, obj) {
		if( obj.length > 0 ) {
			enrich_taxonomy( tax, obj );
		}
	});

	$('.go-oc-refresh').text('refresh');

	$(document).trigger( 'go-oc.complete' );
}

function enrich_taxonomy( taxonomy, oc_objs ) {
	var $tagsdiv = $('#tagsdiv-' + taxonomy),
		$inside = $tagsdiv.find('.inside'),
		ignoredTags, ignoredTagsHash = {}, html = '',
		existingTagsHash = {}, i, len, theTags;

	if( typeof suggestedTagHash[taxonomy] == 'undefined' ) {
		suggestedTagHash[taxonomy] = {};
	}

	// Append "Suggested" and "Ignored" sections
	if( $inside.find( '.go-oc-suggested-list' ).length === 0 ) {
		$inside.append( tmpl );
	}

	// build list of existing tags
	theTags = $inside.find( '.the-tags').val().split(',');
	for( i = 0, len = theTags.length; i < len; i++ ) {
		existingTagsHash[theTags[i]] = true;
	}

	// build list of ignored tags
	ignoredTags = $inside.find('.the-ignored-tags').val().split(',');
	for( i = 0, len = ignoredTags.length; i < len; i++ ) {
		// skip empty tags (usually if .val() above was zero length
		if( '' === ignoredTags[i] ) {
			continue;
		}

		// skip tags that are already in use
		if( existingTagsHash[ignoredTags[i]] ) {
			continue;
		}

		if( first_run ) {
			html = html + tmpl_tag.replace( "{{name}}", ignoredTags[i] );
		}

		ignoredTagsHash[ignoredTags[i]] = true;
	}
	$inside.find('.go-oc-ignored-list').append(html);

	html = '';
	$.each( oc_objs, function( idx, obj ) {
		if( ignoredTagsHash[obj.name] || existingTagsHash[obj.name] ) {
			return;
		}

		if( typeof suggestedTagHash[taxonomy][obj.name] == 'undefined' ) {
			suggestedTagHash[taxonomy][obj.name] = true;
			html = html + tmpl_tag.replace( "{{name}}", obj.name );
		}
	});
	$inside.find('.go-oc-suggested-list').append(html);

	first_run = false;
}

// Toggle display of the suggested/ignored tags
function tags_toggle(e) {
	var $obj = $(e.currentTarget);
	$obj.nextAll('.go-oc-taglist').toggle();
	e.preventDefault();
}

function oc_enrich( post_id ) {
	var params = {
		'action': 'go_oc_enrich',
		'post_id': post_id,
		'nonce': go_oc_nonce
	};

	$.getJSON( ajaxurl, params, oc_enrich_cb );
}

// Use a calais tag, adding it to the tag list for this post
function tag_use(e) {
	tagBox.flushTags( $(this).closest('.inside').children('.tagsdiv'), this);

	// Remove tag after it's added
	$(this).parent().remove();
}

// Ignore a suggested tag
function tag_ignore(e) {
	var $tag = $(this).parent(),
		$inside = $tag.closest('.inside'),
		$ignored = $inside.find('.go-oc-ignored-list'),
		tags = $inside.find('.the-ignored-tags'),
		taxonomy = $inside.find('.tagsdiv').attr('id'),
		tagsval, newtags, text;

	$tag.appendTo( $ignored );
	text = $tag.find('.go-oc-use').text();

	delete suggestedTagHash[taxonomy][text];

	// from wp-admin/js/post.dev.js
	tagsval = tags.val();
	newtags = tagsval ? tagsval + ',' + text : text;

	newtags = tagBox.clean( newtags );
	newtags = array_unique_noempty( newtags.split(',') ).join(',');
	tags.val(newtags);
}

// Manually refresh the tag list
function tag_refresh(e) {
	var params, content,
		post_id = $('#post_ID').val();

	content = tinyMCE.activeEditor.getContent( { format: 'raw' } );

	params = {
		'action': 'go_oc_enrich',
		'content': content,
		'post_id': post_id
	};

	$('.go-oc-refresh').text('refreshing...');
	$.post( ajaxurl, params, oc_enrich_cb, 'json' );

	e.preventDefault();
}

//
// document.ready hook to enrich content
//

$(function(){
	var post_id = $('#post_ID').val();

	// set up ignored tags first, so oc_enrich() can filter ignored
	// tags out of its own display
	oc_ignored_tags();
	oc_enrich( post_id );
});

//
// javascript event bindings
//

var on_cleanup = false;

if( typeof jQuery.fn.on == 'undefined' ) {
	on_cleanup = true;

	jQuery.fn.on = function( event, selector, cb ) {
		$(selector).live( event, cb );
	};
}

$(document).on( 'click', '.go-oc-taggroup', tags_toggle );
$(document).on( 'click', '.go-oc-use', tag_use );
$(document).on( 'click', '.go-oc-ignore', tag_ignore );
$(document).on( 'click', '.go-oc-refresh', tag_refresh );

// cleanup
if( on_cleanup ) {
	delete jQuery.fn.on;
	delete on_cleanup;
}

})(jQuery);
