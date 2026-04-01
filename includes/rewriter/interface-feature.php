<?php
declare(strict_types=1);
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract that every independent rewriter feature must fulfil.
 * This enables early static inspection (IDE / PHPStan) without altering runtime behaviour.
 */
interface Frl_Rewriter_Feature_Interface
{
    /** Priority: lower number = higher specificity */
    public function get_priority(): int;

    /** Return human-readable feature name */
    public function get_name(): string;

    /** Whether this feature is currently enabled by configuration */
    public function is_enabled(): bool;

    /** Rewrite rules pattern => query vars */
    public function generate_rules(): array;

    /** Does this feature handle the given request URI? */
    public function applies_to_request(string $request_uri): bool;

    /** Resolve request URI into WP query vars */
    public function resolve_request(string $request_uri): array;

    /** Whether this feature applies to a given object (post/term) for outbound URL transformation */
    public function applies_to($object): bool;

    /** Transform outbound URL for the given object */
    public function transform(string $url, $object): string;

    /** Query var used by catch-all rules or empty string */
    public function get_catch_all_query_var(): string;

    /** Convenience flag */
    public function uses_catch_all(): bool;
}
