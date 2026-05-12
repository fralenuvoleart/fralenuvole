<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MU plugin throttle constants.
 *
 * These must be defined before includes/mu/mu.php executes
 * (which happens at top-level, not inside a hook). The config/ directory
 * is loaded by config/config.php from bootstrap.php, which runs before
 * mu-plugin.php is required, so this is the correct location.
 *
 * @package Fralenuvole
 */

/**
 * User-Agent substrings to throttle. Each entry is checked via stripos.
 * Add more bot user-agent substrings as needed.
 * @var string[]
 */
const FRL_MU_THROTTLE_USER_AGENT = [
    'ChatGPT-User',
];

/**
 * Maximum allowed requests within the throttle period.
 * @var int
 */
const FRL_MU_THROTTLE_LIMIT = 10;

/**
 * Throttle time window in seconds.
 * @var int
 */
const FRL_MU_THROTTLE_PERIOD = 60;

/**
 * HTTP status code to return when rate limit is exceeded.
 * @var int
 */
const FRL_MU_THROTTLE_STATUS_CODE = 429;
