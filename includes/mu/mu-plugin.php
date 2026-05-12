<?php

/**
 * Fralenuvole - MU Plugin Loader.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load MU-plugin-specific helpers
// @phpstan-ignore requireOnce.fileNotFound
require_once FRL_DIR_PATH . 'includes/mu/functions-mu-plugin.php';

/**
 * Setup plugin exclusion filter before other plugins load.
 */
add_action('muplugins_loaded', 'frl_filter_plugin_exclusions', 5);

/**
 * Plugin Name: Server-Level User Agent Throttling
 * Description: Limits the ChatGPT-User bot to 10 requests per minute per IP.
 */

// Check if the User-Agent contains "ChatGPT-User"
if (!empty($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], 'ChatGPT-User') !== false) {

    // Get the bot's IP address, preferring X-Forwarded-For behind proxies (e.g., Cloudflare)
    $ip = $_SERVER['REMOTE_ADDR'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Trim to remove leading/trailing whitespace from proxy IP lists like "1.2.3.4, 5.6.7.8"
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    // Set limits: 10 requests per 60 seconds
    $limit = 10;
    $time_window = 60;

    $transient_key = 'chatgpt_throttle_' . md5($ip);
    $request_count = (int) get_transient($transient_key);

    // Check if they exceeded the limit
    if ($request_count >= $limit) {
        // Drop the connection immediately with a 429 Too Many Requests code
        http_response_code(429);
        header('Retry-After: 60');
        exit('Rate limit exceeded for AI Assistant bots.');
    }

    // If under the limit, log the request and let them pass
    set_transient($transient_key, $request_count + 1, $time_window);
}
