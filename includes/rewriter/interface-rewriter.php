<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for rewriter implementations.
 *
 * Defines the contract that all rewriter implementations must follow.
 */
interface Frl_Rewriter_Interface {
    /**
     * Initialize the rewriter.
     *
     * @return self The initialized rewriter instance
     */
    public static function init(): self;

    /**
     * Filter post and CPT links.
     *
     * @param string $link The original link
     * @param mixed $post The post object
     * @return string The filtered link
     */
    public function filter_post_link(string $link, $post): string;

    /**
     * Filter term links.
     *
     * @param string $link The original link
     * @param mixed $term The term object
     * @param string $taxonomy The taxonomy name
     * @return string The filtered link
     */
    public function filter_term_link(string $link, $term, string $taxonomy = ''): string;

    /**
     * Add rewrite rules.
     *
     * @return void
     */
    public function add_rewrite_rules(): void;


}
