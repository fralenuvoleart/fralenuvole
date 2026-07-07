<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Host normalization helpers for environment comparisons.
 * Keeping www. significant to distinguish apex vs subdomain.
 */
trait Frl_Environment_Host_Normalizer {

	/**
	 * Normalize a host for comparison: lowercase, trim, drop trailing :port and trailing dot.
	 *
	 * @param string $host The host string to normalize.
	 * @return string The normalized host string.
	 */
	private static function normalize_host_value( $host ) {
		if ( ! is_string( $host ) ) {
			return '';
		}
		$normalized = strtolower( trim( $host ) );
		if ( $normalized === '' ) {
			return '';
		}
		if ( substr( $normalized, -1 ) === '.' ) {
			$normalized = rtrim( $normalized, '.' );
		}
		// strip :port suffix if present
		$normalized = preg_replace( '/:\\d+$/', '', $normalized );
		return $normalized ?: '';
	}

	/**
	 * Extract host from a URL-like string and normalize it.
	 *
	 * @param string $url The URL string to extract host from.
	 * @return string The extracted and normalized host string.
	 */
	private static function extract_host_from_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return '';
		}
		$host = parse_url( $url, PHP_URL_HOST );
		if ( ! $host && strpos( $url, '://' ) === false ) {
			$host = parse_url( 'https://' . ltrim( $url, '/' ), PHP_URL_HOST );
		}
		return self::normalize_host_value( $host ?: '' );
	}
}
