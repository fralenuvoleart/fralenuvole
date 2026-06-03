<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Host normalization helpers for environment comparisons.
 * Keeping www. significant to distinguish apex vs subdomain.
 */
trait Frl_Environment_Host_Normalizer
{
    /**
     * Normalize a host for comparison: lowercase, trim, drop trailing :port and trailing dot.
     *
     * @param string $host The host string to normalize.
     * @return string The normalized host string.
     */
    private static function normalize_host_value($host)
    {
        if (!is_string($host)) {
            return '';
        }
        $normalized = strtolower(trim($host));
        if ($normalized === '') return '';
        if (substr($normalized, -1) === '.') {
            $normalized = rtrim($normalized, '.');
        }
        // strip :port suffix if present
        $normalized = preg_replace('/:\\d+$/', '', $normalized);
        return $normalized ?: '';
    }

    /**
     * Extract host from a URL-like string and normalize it.
     *
     * @param string $url The URL string to extract host from.
     * @return string The extracted and normalized host string.
     */
    private static function extract_host_from_url($url)
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host && strpos($url, '://') === false) {
            $host = parse_url('https://' . ltrim($url, '/'), PHP_URL_HOST);
        }
        return self::normalize_host_value($host ?: '');
    }
}

/**
 * Logging helpers for environment operations
 */
class Frl_Environment_Utils
{
    /**
     * Log final environment change with structured context.
     *
     * @param array $config The environment configuration array.
     * @param string $origin_host The origin host.
     * @param string $dest_host The destination host.
     * @param string $dest_siteurl The destination site URL.
     * @param string $dest_home The destination home URL.
     * @param array $transients The transients information array.
     * @return void
     */
    public static function log_environment_change($config, $origin_host, $dest_host, $dest_siteurl, $dest_home, $transients)
    {
        frl_log(
            'Environment change applied on type={env_type} to {env_const} with prefix {prefix}
            Origin: host={origin_host}, siteurl={origin_siteurl}, home={origin_home}
            Destination: host={dest_host}, siteurl={dest_siteurl}, home={dest_home}
            Website transients deleted:{transients_deleted} {transients_status}',
            [
                'env_type' => $config['type'] ?? '',
                'env_const' => $config['current_environment'] ?? '',
                'prefix' => $config['prefix'] ?? '',
                'origin_host' => $origin_host,
                'origin_siteurl' => site_url(),
                'origin_home' => home_url(),
                'dest_host' => $dest_host,
                'dest_siteurl' => $dest_siteurl,
                'dest_home' => $dest_home,
                'transients_status' => $transients['transients_status'] ?? 'skipped',
                'transients_deleted' => $transients['transients_deleted'] ?? 0,
            ]
        );
    }
}
