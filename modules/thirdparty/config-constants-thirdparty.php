<?php

/**
 * Thirdparty module constants
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Squeeze: maximum dimension (width or height) for uploaded images.
 * Images exceeding this value are resized client-side BEFORE Squeeze
 * compression, so the compressed output is already at the target size.
 * Set to 0 to disable resize.
 */
const FRL_THIRDPARTY_SQUEEZE_MAX_DIM = 1920;
