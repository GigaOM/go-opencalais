<?php
/*
Plugin Name: GO OpenCalais Extras
Plugin URI: 
Description: Company taxonomy, auto-expand igore list, etc.
Version: 0.1
Author: Adam Backstrom for GigaOM
Author URI: http://sixohthree.com/
License: GPL2
*/

@include 'dBug.php';

$GLOBALS['GO_OPENCALAIS_MAPPING'] = array(
	//	'Open Calais entity name' 	=> 'WordPress taxonomy',
	'Company' 					=> 'company',
	'Organization' 				=> 'company',

	'Technology'				=> 'technology',
	'Product' 					=> 'technology',
	'OperatingSystem'			=> 'technology',
	'ProgrammingLanguage'		=> 'technology',

	'Anniversary' 				=> 'post_tag',
	'EntertainmentAwardEvent' 	=> 'post_tag',
	'Holiday' 					=> 'post_tag',
	'IndustryTerm' 				=> 'post_tag',
	'MedicalCondition' 			=> 'post_tag',
	'MedicalTreatment' 			=> 'post_tag',
	'Movie' 					=> 'post_tag',
	'MusicAlbum' 				=> 'post_tag',
	'MusicGroup' 				=> 'post_tag',
	'NaturalFeature' 			=> 'post_tag',
	'Person' 					=> 'post_tag',
	'PoliticalEvent' 			=> 'post_tag',
	'PublishedMedium' 			=> 'post_tag',
	'RadioProgram' 				=> 'post_tag',
	'RadioStation' 				=> 'post_tag',
	'SportsEvent' 				=> 'post_tag',
	'SportsGame' 				=> 'post_tag',
	'SportsLeague' 				=> 'post_tag',
	'TVShow' 					=> 'post_tag',
	'TVStation' 				=> 'post_tag',
	'Anniversary' 				=> 'post_tag',
);
// any entity not named above is ignored

function go_oc_extras_init() {
	register_taxonomy( 'company' , 'post' , array( 'label' => 'Companies and Organizations' ));
	register_taxonomy( 'technology' , 'post' , array( 'label' => 'Technologies and Products' ));
}
add_action( 'init', 'go_oc_extras_init' );

define( 'GO_OPENCALAIS_KEY', '5uud73xp9xrkd4raxcneg5g9' );