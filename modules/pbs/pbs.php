<?php

/**
 * Module Name: PB Services Module
 * Description: Customizations for PB Services (Service form filters, custom post-types, etc.)
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config-constants-pbs.php';
require_once ( __DIR__ . '/custom-post-types.php' );

add_action('init',
    'frl_pbs_load_public_scripts',
    10,
    0);

add_filter('wsf_pre_render',
    'frl_pbs_set_monday_field',
    10,
    2);

/**
 * Add common scripts
 */
 function frl_pbs_load_public_scripts()
 {
    $assets = ['pbs-public-js' => 'modules/pbs/assets/js/public.js'];
    frl_enqueue_scripts($assets, 'pbs_public');

    // Inline script with version check
    $data = [
        'selector' => PBS_JS_REMOVE_HTML_SELECTOR,
        'substrings' => PBS_JS_REMOVE_HTML_STRINGS[frl_get_language()] // Returns the current language code
    ];
    wp_localize_script(FRL_PREFIX . '-pbs-public', 'pbsSettings', $data);

    // The function call is now handled within the JavaScript file after document load
}


/** Set Service-Type field default value
*/
function frl_pbs_set_monday_field( $form, $preview )
{
   /** @disregard P1010 Undefined type */
   $fields = wsf_form_get_fields( $form );

   foreach( $fields as $object ) {
       /** @disregard P1010 Undefined type */
       $field = wsf_field_get_object( $form, $object->id );

       if( 'Service-Type' == $field->label && empty( $field->meta->default_value ) ) {
           $field->meta->default_value = 'Webpage';
       }
   }

   return $form;
}
