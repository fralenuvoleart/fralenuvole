<?php

/**
 * Submodule Name: Menu Sitemap
 * Description: Display a WP menu as nested HTML list starting from a specific parent item
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize Menu Sitemap submodule
 */
function frl_menu_sitemap_init()
{
    add_shortcode(FRL_PREFIX . '_menu_sitemap', 'frl_shortcode_menu_sitemap');
}
add_action('init', 'frl_menu_sitemap_init');

/**
 * Shortcode: [frl_menu_sitemap]
 * Attributes:
 *   - menu (optional): Menu location or name. Defaults to FRL_MENU_SITEMAP_MENU
 *   - parent (optional): Parent menu item title. Empty/full menu if omitted.
 *   - title (optional): Heading text to display. Empty = no title.
 *   - heading (optional): Heading level h1-h6. Defaults to h2.
 *   - class (optional): Additional CSS class for the wrapper
 */
function frl_shortcode_menu_sitemap($atts)
{
    $menu_sitemap_tag = FRL_PREFIX . '_menu_sitemap';

    $a = shortcode_atts([
        'menu'    => FRL_MENU_SITEMAP_MENU,
        'parent'  => FRL_MENU_SITEMAP_PARENT,
        'title'   => '',
        'heading' => 'h2',
        'class'   => '',
    ], $atts, $menu_sitemap_tag);

    $menu_location = sanitize_text_field($a['menu']);
    $parent_title  = sanitize_text_field($a['parent']);
    $title         = sanitize_text_field($a['title']);
    $heading       = sanitize_text_field($a['heading']);
    $extra_class   = sanitize_html_class($a['class']);

    // Validate heading level
    $allowed_headings = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    if (!in_array($heading, $allowed_headings, true)) {
        $heading = 'h2';
    }

    $cache_key = $menu_sitemap_tag . '_' . md5($menu_location . '_' . $parent_title . '_' . $title . '_' . $heading);

    return frl_cache_remember('shortcodes', $cache_key, function () use ($menu_location, $parent_title, $title, $heading, $extra_class) {
        return frl_render_menu_sitemap($menu_location, $parent_title, $title, $heading, $extra_class);
    });
}

/**
 * Render the menu sitemap as nested HTML list
 *
 * @param string $menu_location Menu location or name
 * @param string $parent_title  Parent menu item title to start from
 * @param string $title         Heading text to display (empty = none)
 * @param string $heading       Heading level h1-h6. Defaults to h2.
 * @param string $extra_class   Additional CSS class
 * @return string HTML output
 */
function frl_render_menu_sitemap($menu_location, $parent_title, $title = '', $heading = 'h2', $extra_class = '')
{
    // Try block-based navigation first (wp_navigation post type)
    $navigation_post = frl_get_block_navigation_menu($menu_location);
    if ($navigation_post) {
        return frl_render_block_navigation_sitemap($navigation_post, $parent_title, $title, $heading, $extra_class);
    }

    // Fall back to classic menu
    $menu = wp_get_nav_menu_object($menu_location);

    if (!$menu) {
        $menu_locations = get_nav_menu_locations();
        if (isset($menu_locations[$menu_location])) {
            $menu = wp_get_nav_menu_object($menu_locations[$menu_location]);
        }
    }

    if (!$menu) {
        return '<!-- Menu sitemap: menu not found (' . esc_html($menu_location) . ') -->';
    }

    $menu_items = wp_get_nav_menu_items($menu->term_id);

    if (empty($menu_items)) {
        return '<!-- Menu sitemap: no items found -->';
    }

    // If no parent specified, show full menu from root
    if (empty($parent_title)) {
        $parent_id = 0;
    } else {
        $parent_id = frl_find_menu_parent_id($menu_items, $parent_title);
        if ($parent_id === null) {
            return '<!-- Menu sitemap: parent item "' . esc_html($parent_title) . '" not found -->';
        }
    }

    $tree = frl_build_menu_tree($menu_items, $parent_id);

    if (empty($tree)) {
        return '<!-- Menu sitemap: no items to display -->';
    }

    $wrapper_class = FRL_PREFIX . '-menu-sitemap';
    $wrapper_class .= empty($parent_title) ? ' no-parent' : ' has-parent';
    if ($extra_class) {
        $wrapper_class .= ' ' . $extra_class;
    }

    $output = '<div class="' . esc_attr($wrapper_class) . '">';

    // Determine title to display
    $display_title = '';
    if ($title !== '') {
        // Shortcode attribute overrides everything
        $display_title = $title;
    } elseif (defined('FRL_MENU_SITEMAP_TITLE') && FRL_MENU_SITEMAP_TITLE !== '') {
        // Fall back to constant
        $display_title = FRL_MENU_SITEMAP_TITLE;
    }

    if (!empty($display_title)) {
        $output .= '<' . $heading . '>' . esc_html($display_title) . '</' . $heading . '>';
    }

    $output .= frl_render_menu_tree($tree);
    $output .= '</div>';

    return $output;
}

/**
 * Find the parent menu item ID by title (case-insensitive)
 * Strips HTML tags (like <strong>) before comparison
 *
 * @param array  $menu_items Array of menu item objects
 * @param string $title      Title to search for
 * @return int|null Menu item ID or null if not found
 */
function frl_find_menu_parent_id($menu_items, $title)
{
    $search_lower = strtolower($title);

    foreach ($menu_items as $item) {
        // Strip HTML tags before comparison
        $clean_title = strtolower(wp_strip_all_tags($item->title));
        if ($clean_title === $search_lower) {
            return (int) $item->ID;
        }
    }

    return null;
}

/**
 * Build hierarchical tree from flat menu items array
 *
 * @param array $items    Flat array of menu items
 * @param int   $parent_id Parent ID to build tree from
 * @return array Hierarchical array of menu items
 */
function frl_build_menu_tree($items, $parent_id = 0)
{
    $branch = [];

    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === $parent_id) {
            $children = frl_build_menu_tree($items, $item->ID);
            if (!empty($children)) {
                $item->children = $children;
            }
            $branch[] = $item;
        }
    }

    return $branch;
}

/**
 * Render menu tree as nested HTML unordered list
 *
 * @param array $items Hierarchical array of menu items
 * @return string HTML output
 */
function frl_render_menu_tree($items)
{
    if (empty($items)) {
        return '';
    }

    $output = '<ul>';

    foreach ($items as $item) {
        $output .= '<li>';
        $output .= '<a href="' . esc_url($item->url) . '">' . wp_kses_post($item->title) . '</a>';

        if (!empty($item->children)) {
            $output .= frl_render_menu_tree($item->children);
        }

        $output .= '</li>';
    }

    $output .= '</ul>';

    return $output;
}

/**
 * Get block-based navigation menu by name or ID
 *
 * @param string|int $menu_name Menu name, slug, or ID
 * @return WP_Post|null Navigation post object or null
 */
function frl_get_block_navigation_menu($menu_name)
{
    // Try by ID first
    if (is_numeric($menu_name)) {
        $post = get_post((int) $menu_name);
        if ($post && $post->post_type === 'wp_navigation') {
            return $post;
        }
    }

    // Try by name/slug
    $posts = get_posts([
        'post_type'      => 'wp_navigation',
        'posts_per_page' => 1,
        'title'          => $menu_name,
        'post_status'    => 'publish',
    ]);

    if (!empty($posts)) {
        return $posts[0];
    }

    // Try by slug (post_name)
    $posts = get_posts([
        'post_type'      => 'wp_navigation',
        'posts_per_page' => 1,
        'name'           => sanitize_title($menu_name),
        'post_status'    => 'publish',
    ]);

    if (!empty($posts)) {
        return $posts[0];
    }

    return null;
}

/**
 * Render sitemap from block-based navigation
 *
 * @param WP_Post $navigation_post Navigation post object
 * @param string  $parent_title    Parent menu item title to start from
 * @param string  $title           Heading text to display (empty = none)
 * @param string  $heading         Heading level h1-h6. Defaults to h2.
 * @param string  $extra_class     Additional CSS class
 * @return string HTML output
 */
function frl_render_block_navigation_sitemap($navigation_post, $parent_title, $title = '', $heading = 'h2', $extra_class = '')
{
    $blocks = parse_blocks($navigation_post->post_content);

    if (empty($blocks)) {
        return '<!-- Menu sitemap: no blocks found in navigation -->';
    }

    // Extract all navigation items from blocks
    $items = frl_extract_block_navigation_items($blocks);

    if (empty($items)) {
        return '<!-- Menu sitemap: no navigation items found -->';
    }

    // If no parent specified, show full menu from root (parent_id = 0)
    if (empty($parent_title)) {
        $parent_id = 0;
    } else {
        // Find parent item
        $parent_id = null;
        $search_lower = strtolower($parent_title);

        foreach ($items as $item) {
            // Strip HTML tags before comparison
            $clean_label = strtolower(wp_strip_all_tags($item['label']));
            if ($clean_label === $search_lower) {
                $parent_id = $item['id'];
                break;
            }
        }

        if ($parent_id === null) {
            return '<!-- Menu sitemap: parent item "' . esc_html($parent_title) . '" not found -->';
        }
    }

    // Build tree starting from parent
    $tree = frl_build_block_navigation_tree($items, $parent_id);

    if (empty($tree)) {
        return '<!-- Menu sitemap: no items to display -->';
    }

    $wrapper_class = FRL_PREFIX . '-menu-sitemap';
    $wrapper_class .= empty($parent_title) ? ' no-parent' : ' has-parent';
    if ($extra_class) {
        $wrapper_class .= ' ' . $extra_class;
    }

    $output = '<div class="' . esc_attr($wrapper_class) . '">';

    // Determine title to display
    $display_title = '';
    if ($title !== '') {
        // Shortcode attribute overrides everything
        $display_title = $title;
    } elseif (defined('FRL_MENU_SITEMAP_TITLE') && FRL_MENU_SITEMAP_TITLE !== '') {
        // Fall back to constant
        $display_title = FRL_MENU_SITEMAP_TITLE;
    }

    if (!empty($display_title)) {
        $output .= '<' . $heading . '>' . esc_html($display_title) . '</' . $heading . '>';
    }

    $output .= frl_render_block_navigation_tree($tree);
    $output .= '</div>';

    return $output;
}

/**
 * Extract navigation items from parsed blocks
 *
 * @param array $blocks Parsed blocks array
 * @param int   $parent_id Parent ID for nested items
 * @return array Flat array of navigation items
 */
function frl_extract_block_navigation_items($blocks, $parent_id = 0)
{
    $items = [];
    $counter = 0;

    foreach ($blocks as $block) {
        if (empty($block['blockName'])) {
            continue;
        }

        // Handle navigation-link (single item)
        if ($block['blockName'] === 'core/navigation-link') {
            $counter++;
            $item_id = $parent_id ? $parent_id . '-' . $counter : $counter;

            $items[] = [
                'id'       => $item_id,
                'parent'   => $parent_id,
                'label'    => $block['attrs']['label'] ?? '',
                'url'      => $block['attrs']['url'] ?? '',
                'kind'     => $block['attrs']['kind'] ?? 'custom',
            ];

            // Check for nested items in innerBlocks
            if (!empty($block['innerBlocks'])) {
                $children = frl_extract_block_navigation_items($block['innerBlocks'], $item_id);
                $items = array_merge($items, $children);
            }
        }

        // Handle navigation-submenu (parent with children)
        if ($block['blockName'] === 'core/navigation-submenu') {
            $counter++;
            $item_id = $parent_id ? $parent_id . '-' . $counter : $counter;

            $items[] = [
                'id'       => $item_id,
                'parent'   => $parent_id,
                'label'    => $block['attrs']['label'] ?? '',
                'url'      => $block['attrs']['url'] ?? '',
                'kind'     => $block['attrs']['kind'] ?? 'custom',
            ];

            // Extract children from innerBlocks
            if (!empty($block['innerBlocks'])) {
                $children = frl_extract_block_navigation_items($block['innerBlocks'], $item_id);
                $items = array_merge($items, $children);
            }
        }

        // Handle page-list (auto-generated from pages)
        if ($block['blockName'] === 'core/page-list') {
            // Get pages as navigation items
            $pages = get_pages(['sort_column' => 'menu_order, post_title']);
            foreach ($pages as $page) {
                $counter++;
                $items[] = [
                    'id'       => $parent_id ? $parent_id . '-' . $counter : $counter,
                    'parent'   => $parent_id,
                    'label'    => $page->post_title,
                    'url'      => get_permalink($page->ID),
                    'kind'     => 'post-type',
                ];
            }
        }

        // Handle navigation (root container) - recurse into innerBlocks
        if ($block['blockName'] === 'core/navigation' && !empty($block['innerBlocks'])) {
            $children = frl_extract_block_navigation_items($block['innerBlocks'], $parent_id);
            $items = array_merge($items, $children);
        }
    }

    return $items;
}

/**
 * Build hierarchical tree from flat block navigation items
 *
 * @param array $items    Flat array of items
 * @param int   $parent_id Parent ID to build tree from
 * @return array Hierarchical array
 */
function frl_build_block_navigation_tree($items, $parent_id = 0)
{
    $branch = [];

    foreach ($items as $item) {
        if ($item['parent'] === $parent_id) {
            $children = frl_build_block_navigation_tree($items, $item['id']);
            if (!empty($children)) {
                $item['children'] = $children;
            }
            $branch[] = $item;
        }
    }

    return $branch;
}

/**
 * Render block navigation tree as HTML
 *
 * @param array $items Hierarchical array of items
 * @return string HTML output
 */
function frl_render_block_navigation_tree($items)
{
    if (empty($items)) {
        return '';
    }

    $output = '<ul>';

    foreach ($items as $item) {
        $output .= '<li>';
        $output .= '<a href="' . esc_url($item['url']) . '">' . wp_kses_post($item['label']) . '</a>';

        if (!empty($item['children'])) {
            $output .= frl_render_block_navigation_tree($item['children']);
        }

        $output .= '</li>';
    }

    $output .= '</ul>';

    return $output;
}
