<?php

/**
 * Submodule Name: Bible Audio
 * Description: Bible passage audio integration via ESV API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize Bible submodule
 */
function frl_bible_init()
{
    // Register shortcodes
    add_shortcode('frl_bible_audio', 'frl_shortcode_bible_audio');

    // Handle audio proxy via query parameter (no rewrite rules needed)
    add_action('template_redirect', 'frl_bible_handle_proxy', 1, 0);

    // Register Bible URL transform for nav menus
    add_filter('frl_nav_menu_url_transforms', 'frl_register_bible_url_transform');
}

/**
 * Add Bible URL transform handler to nav menu transforms.
 *
 * @param array $handlers Existing transform handlers.
 * @return array Modified handlers array.
 */
function frl_register_bible_url_transform($handlers)
{
    $handlers['bible'] = 'frl_build_bible_url';
    return $handlers;
}
frl_bible_init();

/**
 * Register rewrite endpoint for audio proxy
 * URL: /bible-audio/?passage=John+3:16
 */
function frl_bible_register_rewrite()
{
    // Add endpoint with EP_ROOT (root-level endpoint like /bible-audio/)
    add_rewrite_endpoint(FRL_BIBLE_ENDPOINT, EP_ROOT);
}

/**
 * Handle audio proxy - redirects to actual MP3 while keeping API key hidden
 * Triggered by: ?esv-audio=1&passage=John+3:16
 */
function frl_bible_handle_proxy()
{
    // Check for our trigger parameter
    if (empty($_GET[FRL_BIBLE_ENDPOINT])) {
        return;
    }

    $passage = sanitize_text_field($_GET['passage'] ?? '');
    if (empty($passage)) {
        wp_die('No passage specified');
    }

    $api_key = FRL_BIBLE_API_KEY;
    if (!$api_key) {
        wp_die('ESV API key not configured. Define FRL_BIBLE_API_KEY in wp-config.php');
    }

    // Build API URL
    $api_url = add_query_arg([
        'q' => $passage
    ], FRL_BIBLE_API_BASE_URL);

    // Request WITHOUT following redirects (like curl -L)
    // ESV API returns 302 with Location header containing signed MP3 URL
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => 'Token ' . $api_key
        ],
        'timeout' => 30,
        'redirection' => 0  // Do not follow - we need the Location header
    ]);

    if (is_wp_error($response)) {
        error_log('ESV Error: wp_remote_get failed: ' . $response->get_error_message());
        wp_die('Audio unavailable');
    }

    $response_code = wp_remote_retrieve_response_code($response);

    // WordPress stores headers lowercase
    $headers = wp_remote_retrieve_headers($response);
    $audio_url = $headers['location'] ?? '';

    // ESV API returns 302 or 307 redirect with Location header
    if ((!in_array($response_code, [302, 307]) && $response_code !== 200) || empty($audio_url)) {
        $body = wp_remote_retrieve_body($response);
        wp_die('Audio not found (HTTP ' . $response_code . '). Body: ' . esc_html(substr($body, 0, 200)));
    }

    // Redirect to the actual MP3
    wp_redirect(esc_url_raw($audio_url), 302);
    exit;
}

/**
 * Parse frlq URL parameter into ESV passage format
 * Input: Genesis/2:4-2:24
 * Output: Genesis 2:4-24
 */
function frl_bible_parse_frlq($frlq)
{
    if (empty($frlq)) {
        return '';
    }

    // Remove any hash fragment (everything after #)
    $frlq = explode('#', $frlq)[0];

    // Pattern: Book/Chapter:Verse-Chapter:Verse or Book/Chapter:Verse
    // Examples: Genesis/2:4-2:24, John/3:16, Exodus/1:1-2:10
    if (!preg_match('|^([^/]+)/(.+)$|', $frlq, $matches)) {
        return '';
    }

    $book = $matches[1];
    $reference = $matches[2];

    // Convert reference format: "2:4-2:24" becomes "2:4-24", "1:1-2:10" becomes "1:1-2:10"
    // If the end chapter matches the start chapter, simplify: 2:4-2:24 → 2:4-24
    if (preg_match('/^(\d+):(\d+)-(\d+):(\d+)$/', $reference, $ref_matches)) {
        $start_chapter = $ref_matches[1];
        $start_verse = $ref_matches[2];
        $end_chapter = $ref_matches[3];
        $end_verse = $ref_matches[4];

        if ($start_chapter === $end_chapter) {
            $reference = $start_chapter . ':' . $start_verse . '-' . $end_verse;
        } else {
            $reference = $start_chapter . ':' . $start_verse . '-' . $end_chapter . ':' . $end_verse;
        }
    }

    return $book . ' ' . $reference;
}

/**
 * Shortcode: [frl_bible_audio passage="John 3:16"]
 * Attributes:
 *   - passage (optional): Bible reference. If empty, extracts from frlq URL parameter
 *   - label (optional): Screen reader text
 */
function frl_shortcode_bible_audio($atts)
{
    $a = shortcode_atts([
        'passage' => '',
        'label'   => 'Bible passage audio'
    ], $atts, 'frl_bible_audio');

    $passage = sanitize_text_field($a['passage']);

    // If no passage provided, try to extract from frlq URL parameter
    if (empty($passage) && !empty($_GET['frlq'])) {
        $passage = frl_bible_parse_frlq($_GET['frlq']);
    }

    // If still no passage, use default constant
    if (defined('FRL_BIBLE_DEFAULT_PASSAGE') && FRL_BIBLE_DEFAULT_PASSAGE) {
        $passage = FRL_BIBLE_DEFAULT_PASSAGE;
    }

    // If still no passage, return empty (no audio player)
    if (empty($passage)) {
        return '';
    }

    $cache_key = 'bible_audio_' . md5($passage);

    return frl_cache_remember('shortcodes', $cache_key, function () use ($passage, $a) {
        $proxy_url = add_query_arg([
            FRL_BIBLE_ENDPOINT => '1',
            'passage' => $passage
        ], home_url('/'));

        $label = esc_html($a['label']);
        $caption_prefix = defined('FRL_BIBLE_CAPTION_PREFIX') ? FRL_BIBLE_CAPTION_PREFIX : '';
        $display_ref = esc_html($caption_prefix . $passage);
        
        return sprintf(
            '<figure class="frl-bible-audio">
                <figcaption>%s</figcaption>
                <audio controls preload="none">
                    <source src="%s" type="audio/mpeg">
                    %s
                </audio>
            </figure>',
            $display_ref,
            esc_url($proxy_url),
            esc_html__('Your browser does not support the audio element.', FRL_NAME)
        );
    });
}

/**
 * Build a Bible URL from a verse reference.
 *
 * Used by nav menu URL transforms (#frl_url_bible=*) to generate full Bible URLs.
 *
 * Example:
 *   Input:  Genesis/2:4-2:24
 *   Output: https://fralenuvole.art/bible/?frlq=Genesis/2:4-2:24#/p/net,cebbugna/Genesis/2:4-2:24
 *
 * @param string $verse Verse reference (e.g., Genesis/2:4-2:24 or John/1)
 * @return string Full Bible URL
 */
function frl_build_bible_url($verse)
{
    if (empty($verse)) {
        return '';
    }

    $base_url = home_url('/');
    $url_base = defined('FRL_URL_BIBLE_BASE') ? FRL_URL_BIBLE_BASE : 'bible/';
    $bibles = defined('FRL_URL_BIBLES') ? FRL_URL_BIBLES : 'net,cebbugna';
    $add_query = defined('FRL_URL_BIBLE_QUERY_PARAM') ? FRL_URL_BIBLE_QUERY_PARAM : 1;

    if ($add_query) {
        $url = add_query_arg('frlq', $verse, trailingslashit($base_url) . $url_base);
        $url .= '#/p/' . $bibles . '/' . $verse;
    } else {
        $url = trailingslashit($base_url) . $url_base . '#/p/' . $bibles . '/' . $verse;
    }

    return $url;
}
