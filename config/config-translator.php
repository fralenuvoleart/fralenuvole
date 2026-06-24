<?php
/**
 * Custom Fields Module: Translation Configuration
 */
if (!defined('ABSPATH')) exit;

/**
 * Constants for the Fralenuvole Translator module
 */

// Default language fallback — used when no multilingual plugin is active
// or when Polylang's configuration cannot be read.
const FRL_TRANSLATOR_DEFAULT_LANG = 'en';

// Source language — the language in which string tokens and block content
// The source language remains 'en' even if Polylang's default changes on subdomains for clean URLs.
const FRL_TRANSLATOR_SOURCE_LANG = 'en';

// Strings that should NOT be translated or registered.
// Matched against the inner content of {{...}} delimiters.
// Format:
//   'TOKEN'  = exact match only (e.g., excludes only {{TOKEN}})
//   'TOKEN*' = prefix wildcard (e.g., 'CONTENT*' matches {{CONTENT}}, {{CONTENT_TITLE}}, etc.)
const FRL_TRANSLATOR_EXCLUDE = [
    'CONTENT*' => true,
];

// Taxonomies to auto-translate (supports % wildcard). Empty = translate none.
const FRL_TRANSLATOR_TAXONOMIES = [
    'flag-category',
    'service_category',
];

// Field keys to translate (supports % wildcard). Applied to meta keys and repeater names.
const FRL_TRANSLATOR_FIELDS = [
    // '%_subheading',
    // '%_title',
    // '%_content',
    // '%_footer',
    // '%_link',
    // 'cards_cards', // repeater field
];

// To translate term meta (custom fields on the term, supports % wildcard).
const FRL_TRANSLATOR_FIELDS_TERMS = [];

// User meta keys to translate (supports % wildcard).
const FRL_TRANSLATOR_FIELDS_USERS = [];

// Option keys to translate (exact option_name; no wildcard).
const FRL_TRANSLATOR_OPTIONS = [
    // 'options_address_full',
    // 'options_address_street',
    // 'options_address_city',
    // 'options_address_country',
    // 'options_address_country_code',
];

// Complex field handlers.
const FRL_TRANSLATOR_FIELDS_ACF = [
    'repeater' => 'frl_translator_acf_repeater',
    'taxonomy' => 'frl_translator_acf_taxonomy',
    'link'     => 'frl_translator_acf_link',
];



// Repeater subfield names allowed by default (supports % wildcard).
// Requires: repeater enabled + type in FRL_TRANSLATOR_REPEATER_SUBFIELD_TYPES.
// Set to [] to allow all text-like subfields.
const FRL_TRANSLATOR_REPEATER_SUBFIELDS = [
    'subheading',
    'title',
    'content',
    'link',
    'footer',
];

// Per-repeater override: ONLY the listed subfield names are translated (supports % wildcard).
// Requires type in FRL_TRANSLATOR_REPEATER_SUBFIELD_TYPES. Others are ignored.
const FRL_TRANSLATOR_REPEATER_SUBFIELDS_OVERRIDE = [
    // 'steps' => ['subheading'],
];

// Text-like ACF types eligible inside repeaters.
const FRL_TRANSLATOR_REPEATER_SUBFIELD_TYPES = [
    'text',
    'textarea',
    'wysiwyg',
];

// Debug: log missing translation functions.
const FRL_TRANSLATOR_LOG_MISSING_TRANSLATION = false;

// Translation delimiters for placeholders.
const FRL_TRANSLATOR_DELIMITER_TEXT = [
    'start' => '{{', 
    'end' => '}}'
];

const FRL_TRANSLATOR_DELIMITER_LINK = [
    'start' => '##', 
    'end' => '##'
];

// Maximum number of strings to queue for registration per request to prevent memory exhaustion.
const FRL_TRANSLATOR_MAX_QUEUE_SIZE = 500;
