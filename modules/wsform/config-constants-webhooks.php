<?php

/**
 * Webhooks modular configuration.
 * Each environment can have multiple webhooks.
 * Each webhook can be restricted to a specific form_id (or null for all forms).
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Webhook dedupe settings (per channel)
const WSFORM_WEBHOOK_DEDUPE_ENABLED = true;
const WSFORM_WEBHOOK_DEDUPE_REFERENCE_KEYS = ['Reference ID', 'reference_id'];
const WSFORM_WEBHOOK_DEDUPE_CHANNEL_KEYS = ['Preferred contact method', 'Contact method'];
const WSFORM_WEBHOOK_DEDUPE_TTL = 6 * HOUR_IN_SECONDS;

const WSFORM_ALL_WEBHOOKS_CONFIG = [
    'default' => [], // No webhooks for unknown domains
    // PBS Services
    'pbs' => [
        [
            'form_id'          => 12, // Must match the WS Form ID exactly. 
            'url'              => 'https://webhooks.integrately.com/a/webhooks/d3db87eb88ee48eeac177a49fc159070',
            'spam_filter' => [
                'block_if_all_filled' => [],
            ],
            'fields_map'       => [
                'Name'                      => 'field_225',
                'Email'                     => 'field_226',
                'Phone'                     => 'field_227',
                'Contact method'            => 'field_228',
                'Other contact method'      => 'field_229',
                'Telegram ID'               => 'field_230',
                'Message'                   => 'field_231',
                'Reference ID'              => 'field_232',
                'CTA'                       => 'field_233',
                'Service'                   => 'field_234',
                'Language'                  => 'field_235',
                'Page URL'                  => 'field_236',
                'Refer URL'                 => 'field_237',
                'User IP'                   => 'field_238',
                'Channel Source'            => 'field_240',
                'Channel Medium'            => 'field_241',
                'Channel Campaign'          => 'field_242',
                'Channel Term'              => 'field_243',
                'Channel Content'           => 'field_249',
                'Channel GCLID'             => 'field_245',
                'Channel FBCLID'            => 'field_246',
                'Channel Landing'           => 'field_247',
            ],
        ],
        [
            'form_id'    => 9, // Second webhook trigger
            'use_cron'   => false,
            'url'        => 'https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7',
            'fields_map' => [
                // Reference ID is used to identify webhook submission, must be present to dedupe.
                'Reference ID'              => 'field_157',
                // CTA is used to identify the channel of the webhook submission. Must be present to dedupe.
                'CTA'                       => 'field_158',
                'Contact method'            => 'field_158',
                'Service'                   => 'field_159',
                'Language'                  => 'field_162',
                'Page URL'              => 'field_160',
                'Referer'                   => 'field_163',
                'User IP'                   => 'field_164',
                'Channel Source'            => 'field_165',
                'Channel Medium'            => 'field_166',
                'Channel Landing'           => 'field_167',
                'Channel Campaign'          => 'field_168',
                'Channel Term'              => 'field_169',
                'Channel Content'           => 'field_170',
                'Channel GCLID'             => 'field_171',
                'Channel FBCLID'            => 'field_172',
            ],
        ],
    ],
    // PB Property
    'pbp' => [
        [
            'form_id'          => 2, // Must match the WS Form ID exactly. 
            'use_cron'   => false,
            'url'              => 'https://webhooks.integrately.com/a/webhooks/70ace417574440f7b3835a71655b8a40',
            'spam_filter' => [
                'block_if_all_filled' => [],
            ],
            'fields_map'       => [
                'Name'                      => 'field_14',
                'Email'                     => 'field_15',
                'Phone'                     => 'field_16',
                'Contact method'            => 'field_17',
                'Other contact method'      => 'field_18',
                'Telegram ID'               => 'field_19',
                'Message'                   => 'field_20',
                'Reference ID'              => 'field_21',
                'CTA'                       => 'field_22',
                'Service'                   => 'field_23',
                'Language'                  => 'field_24',
                'Page URL'                  => 'field_25',
                'Refer URL'                 => 'field_26',
                'User IP'                   => 'field_27',
                'Channel Source'            => 'field_29',
                'Channel Medium'            => 'field_30',
                'Channel Campaign'          => 'field_31',
                'Channel Term'              => 'field_32',
                'Channel Content'           => 'field_33',
                'Channel GCLID'             => 'field_34',
                'Channel FBCLID'            => 'field_35',
                'Channel Landing'           => 'field_36',

            ],
        ],
    ],
];
