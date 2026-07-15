<?php

/**
 * WS Form module settings
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Stats widgets: integer IDs get a per-form widget, 'all' adds a combined widget.
// Examples: [10, 9, 'all']  |  ['all']  |  [10]  |  []
const WS_STATS_FORM_IDS = array( 'all' );
