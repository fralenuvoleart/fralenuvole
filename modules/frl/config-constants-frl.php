<?php

/**
 * FRL module settings
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Constants
 */

const FRL_BIBLE_API_BASE_URL = 'https://api.esv.org/v3/passage/audio/';
const FRL_BIBLE_ENDPOINT = 'bible-audio';

// API Key - define in wp-config.php: define('FRL_BIBLE_API_KEY', 'your_key_here');
const FRL_BIBLE_API_KEY = '675af0ff2f440bd5983ae0a5c05f81b9bb89b2af';

// Default passage for [frl_bible_audio] shortcode
const FRL_BIBLE_DEFAULT_PASSAGE = '';

// Optional caption prefix for audio player
const FRL_BIBLE_CAPTION_PREFIX = 'Listen to audio: ';

/**
 * Menu Sitemap Settings
 */

// The menu location or name to use (e.g., 'primary', 'main-menu', or menu name)
const FRL_MENU_SITEMAP_MENU = 'Navigation';

// The title of the parent menu item to start listing from (case-insensitive match)
const FRL_MENU_SITEMAP_PARENT = 'The Bible';

// The H1 title displayed at the top of the sitemap (empty = no title)
const FRL_MENU_SITEMAP_TITLE = 'The Bible - Chapters';

/**
 * Bible URL Builder Settings
 */

// Subpath from root for bible URLs
const FRL_BIBLE_URL_BASE = 'bible/';

// Bibles to include in URL (comma-separated, e.g., 'net,cebbugna' or just 'net')
const FRL_BIBLE_URL_BIBLES = 'net,cebbugna';

// Whether to include the ?frlq= query parameter in the URL (1 = enabled, 0 = disabled)
const FRL_BIBLE_URL_QUERY_PARAM = 1;
