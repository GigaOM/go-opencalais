<?php

function go_oc_extras_init()
{
	register_taxonomy( 'company', 'post', array( 'label' => 'Companies and Organizations' ));
	register_taxonomy( 'technology', 'post', array( 'label' => 'Technologies and Products' ));
}//end go_oc_extras_init
