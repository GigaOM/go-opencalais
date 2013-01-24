<?php

function go_oc_extras_init()
{
	register_taxonomy(
		'company',
		'post',
		array(
			'label' => 'Companies and Organizations',
			'rewrite'      => array(
				'slug'         => 'company',
				'with_front'   => FALSE,
				'hierarchical' => FALSE,
				'ep_mask'      => EP_TAGS,
			),
		)
	);

	register_taxonomy(
		'technology',
		'post',
		array(
			'label' => 'Technologies and Products',
			'rewrite'      => array(
				'slug'         => 'technology',
				'with_front'   => FALSE,
				'hierarchical' => FALSE,
				'ep_mask'      => EP_TAGS,
			),
		)
	);
}//end go_oc_extras_init
