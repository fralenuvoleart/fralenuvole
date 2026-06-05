<?php
/**
 * Schema Generator Config
 *
 * Each definition mirrors Schema.org JSON-LD structure.
 * Scalar values are ACF/ACPT field names resolved at runtime.
 *
 * HowTo schema (https://schema.org/HowTo):
 *
 *   @type            HowTo
 *   name             → HowTo.name     (default: post title)
 *   description      → HowTo.description  (optional)
 *   image            → HowTo.image    (default: featured image)
 *   totalTime        → HowTo.totalTime    (optional, ISO 8601 duration)
 *   estimatedCost    → HowTo.estimatedCost (optional, text or field)
 *   step             → HowTo.step (required)
 *     repeater        ACF/ACPT field to iterate
 *     source          'acf' or 'acpt'
 *     name            → HowToStep.name  ← sub-field
 *     text            → HowToStep.text  ← sub-field
 *
 * Omit optional keys to skip them. Values can be literal strings
 * (e.g. 'totalTime' => 'PT30M') or field names resolved at runtime.
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'post' => [
        [
            '@type' => 'HowTo',
            'name'  => 'service-howtos_title',
            'description' => 'service-howtos_description', // optional          
            'about' => '{{post_title}}', // optional
            'totalTime'   => 'service-howtos_time', // optional: ACF field (e.g. 'PT30M')
            'estimatedCost' => 'service-howtos_cost', // optional: ACF field
            'step'         => [
                '@type'    => 'HowToStep',
                'repeater' => 'service-howtos_howto',
                'source'   => 'acpt',
                'position' => '{{index}}',
                'name'     => 'title',
                'text'     => 'answer',
            ],
        ],
    ],
];
