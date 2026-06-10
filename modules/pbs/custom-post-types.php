<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * PBS Module
 * custom-post-types.php
 */

add_action("init",
	"frl_pbs_register_custom_post_types",
	0,
	0);

add_filter("pre_get_posts",
	"frl_pbs_kill_taxonomy_archives");

function frl_pbs_register_custom_post_types()
{
	// Register custom post types
	frl_pbs_custom_post_type_service();
	frl_pbs_custom_post_type_team_member();
	frl_pbs_custom_post_type_university();

	// Register taxonomies
	frl_pbs_service_taxonomy();
	frl_pbs_team_member_taxonomy();
}

// Register Service Post Type
function frl_pbs_custom_post_type_service()
{
	$labels = array(
		'name'                  => _x( 'Services', 'Post Type General Name', PBS_PREFIX ),
		'singular_name'         => _x( 'Service', 'Post Type Singular Name', PBS_PREFIX ),
		'menu_name'             => __( 'Services', PBS_PREFIX ),
		'name_admin_bar'        => __( 'Service', PBS_PREFIX ),
		'archives'              => __( 'Service Archives', PBS_PREFIX ),
		'attributes'            => __( 'Service Attributes', PBS_PREFIX ),
		'parent_item_colon'     => __( 'Parent Service', PBS_PREFIX ),
		'all_items'             => __( 'All Services', PBS_PREFIX ),
		'add_new_item'          => __( 'Add New Service', PBS_PREFIX ),
		'add_new'               => __( 'Add New', PBS_PREFIX ),
		'new_item'              => __( 'New Service', PBS_PREFIX ),
		'edit_item'             => __( 'Edit Service', PBS_PREFIX ),
		'update_item'           => __( 'Update Service', PBS_PREFIX ),
		'view_item'             => __( 'View Service', PBS_PREFIX ),
		'view_items'            => __( 'View Services', PBS_PREFIX ),
		'search_items'          => __( 'Search Services', PBS_PREFIX ),
		'not_found'             => __( 'No services found.', PBS_PREFIX ),
		'not_found_in_trash'    => __( 'No services found in Trash.', PBS_PREFIX ),
		'featured_image'        => __( 'Featured Image', PBS_PREFIX ),
		'set_featured_image'    => __( 'Set featured image', PBS_PREFIX ),
		'remove_featured_image' => __( 'Remove featured image', PBS_PREFIX ),
		'use_featured_image'    => __( 'Use as featured image', PBS_PREFIX ),
		'insert_into_item'      => __( 'Insert into service', PBS_PREFIX ),
		'uploaded_to_this_item' => __( 'Uploaded to this service', PBS_PREFIX ),
		'items_list'            => __( 'Services list', PBS_PREFIX ),
		'items_list_navigation' => __( 'Services list navigation', PBS_PREFIX ),
		'filter_items_list'     => __( 'Filter services list', PBS_PREFIX ),
	);
	$rewrite = array(
		'with_front'            => false,
		'pages'                 => true,
		'feeds'                 => false,
	);
	$args = array(
		'label'                 => __( 'Service', PBS_PREFIX ),
		'description'           => __( 'Post Type Description', PBS_PREFIX ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes', 'excerpt' ),
		'taxonomies'            => array( 'service_category', 'service_tag' ),
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 20,
		'menu_icon'             => 'dashicons-star-filled',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => false,
		'exclude_from_search'   => false,
		'publicly_queryable'    => true,
		'rewrite'               => $rewrite,
		'capability_type'       => 'page',
		'show_in_rest'          => true,
	);

	register_post_type( 'service', $args );
}

// Register Service Taxonomy
function frl_pbs_service_taxonomy()
{
	$labels_category = array(
		'name'                       => _x( 'Service Categories', 'Taxonomy General Name', PBS_PREFIX ),
		'singular_name'              => _x( 'Service Category', 'Taxonomy Singular Name', PBS_PREFIX ),
		'menu_name'                  => __( 'Service Categories', PBS_PREFIX ),
		'all_items'                  => __( 'All Categories', PBS_PREFIX ),
		'parent_item'                => __( 'Parent Category', PBS_PREFIX ),
		'parent_item_colon'          => __( 'Parent Category:', PBS_PREFIX ),
		'new_item_name'              => __( 'New Service category', PBS_PREFIX ),
		'add_new_item'               => __( 'Add New Service category', PBS_PREFIX ),
		'edit_item'                  => __( 'Edit Service category', PBS_PREFIX ),
		'update_item'                => __( 'Update Service category', PBS_PREFIX ),
		'view_item'                  => __( 'View Service category', PBS_PREFIX ),
		'separate_items_with_commas' => __( 'Separate categories with commas', PBS_PREFIX ),
		'add_or_remove_items'        => __( 'Add or remove categories', PBS_PREFIX ),
		'choose_from_most_used'      => __( 'Choose from the most used', PBS_PREFIX ),
		'popular_items'              => __( 'Popular categories', PBS_PREFIX ),
		'search_items'               => __( 'Search categories', PBS_PREFIX ),
		'not_found'                  => __( 'Category Not Found', PBS_PREFIX ),
		'no_terms'                   => __( 'No categories', PBS_PREFIX ),
		'items_list'                 => __( 'Categories list', PBS_PREFIX ),
		'items_list_navigation'      => __( 'Categories list navigation', PBS_PREFIX ),
	);
	$arg_category = array(
		'labels'                     => $labels_category,
		'hierarchical'               => true,
		'public'                     => true,
		'show_ui'                    => true,
		'show_admin_column'          => true,
		'show_in_nav_menus'          => true,
		'show_tagcloud'              => false,
		'show_in_rest'               => true,
	);

	register_taxonomy( 'service_category', 'service', $arg_category );

	$labels_tag = array(
		'name'                       => __( 'Service Tags', 'Taxonomy General Name', PBS_PREFIX ),
		'singular_name'              => __( 'Service Tag', 'Taxonomy Singular Name', PBS_PREFIX ),
		'menu_name'                  => __( 'Service Tags', PBS_PREFIX ),
		'all_items'                  => __( 'All Tags', PBS_PREFIX ),
		'parent_item'                => __( 'Parent Tag', PBS_PREFIX ),
		'parent_item_colon'          => __( 'Parent Tag:', PBS_PREFIX ),
		'new_item_name'              => __( 'New Service tag', PBS_PREFIX ),
		'add_new_item'               => __( 'Add New Service tag', PBS_PREFIX ),
		'edit_item'                  => __( 'Edit Service tag', PBS_PREFIX ),
		'update_item'                => __( 'Update Service tag', PBS_PREFIX ),
		'view_item'                  => __( 'View Service tag', PBS_PREFIX ),
		'separate_items_with_commas' => __( 'Separate tags with commas', PBS_PREFIX ),
		'add_or_remove_items'        => __( 'Add or remove tags', PBS_PREFIX ),
		'choose_from_most_used'      => __( 'Choose from the most used', PBS_PREFIX ),
		'popular_items'              => __( 'Popular tags', PBS_PREFIX ),
		'search_items'               => __( 'Search tags', PBS_PREFIX ),
		'not_found'                  => __( 'Category Not Found', PBS_PREFIX ),
		'no_terms'                   => __( 'No taga', PBS_PREFIX ),
		'items_list'                 => __( 'Tags list', PBS_PREFIX ),
		'items_list_navigation'      => __( 'Tags list navigation', PBS_PREFIX ),
	);
	$args_tag = array(
		'labels'                     => $labels_tag,
		'hierarchical'               => false,
		'public'                     => true,
		'show_ui'                    => true,
		'show_admin_column'          => true,
		'show_in_nav_menus'          => true,
		'show_tagcloud'              => false,
		'show_in_rest'               => true,
	);

	register_taxonomy( 'service_tag', 'service', $args_tag );
}


// Register Team member Post Type
function frl_pbs_custom_post_type_team_member()
{
	$labels = array(
		'name'                  => _x( 'Team members', 'Post Type General Name', PBS_PREFIX ),
		'singular_name'         => _x( 'Team member', 'Post Type Singular Name', PBS_PREFIX ),
		'menu_name'             => __( 'Team members', PBS_PREFIX ),
		'name_admin_bar'        => __( 'Team member', PBS_PREFIX ),
		'archives'              => __( 'Team member Archives', PBS_PREFIX ),
		'attributes'            => __( 'Team member Attributes', PBS_PREFIX ),
		'parent_item_colon'     => __( 'Parent Team member', PBS_PREFIX ),
		'all_items'             => __( 'All Team members', PBS_PREFIX ),
		'add_new_item'          => __( 'Add New Team member', PBS_PREFIX ),
		'add_new'               => __( 'Add New', PBS_PREFIX ),
		'new_item'              => __( 'New Team member', PBS_PREFIX ),
		'edit_item'             => __( 'Edit Team member', PBS_PREFIX ),
		'update_item'           => __( 'Update Team member', PBS_PREFIX ),
		'view_item'             => __( 'View Team member', PBS_PREFIX ),
		'view_items'            => __( 'View Team members', PBS_PREFIX ),
		'search_items'          => __( 'Search Team members', PBS_PREFIX ),
		'not_found'             => __( 'No team members found.', PBS_PREFIX ),
		'not_found_in_trash'    => __( 'No team members found in Trash.', PBS_PREFIX ),
		'featured_image'        => __( 'Featured Image', PBS_PREFIX ),
		'set_featured_image'    => __( 'Set featured image', PBS_PREFIX ),
		'remove_featured_image' => __( 'Remove featured image', PBS_PREFIX ),
		'use_featured_image'    => __( 'Use as featured image', PBS_PREFIX ),
		'insert_into_item'      => __( 'Insert into team member', PBS_PREFIX ),
		'uploaded_to_this_item' => __( 'Uploaded to this team member', PBS_PREFIX ),
		'items_list'            => __( 'Team members list', PBS_PREFIX ),
		'items_list_navigation' => __( 'Team members list navigation', PBS_PREFIX ),
		'filter_items_list'     => __( 'Filter team members list', PBS_PREFIX ),
	);
	$rewrite = array(
		'slug'                  => 'about',
		'with_front'            => false,
		'pages'                 => true,
		'feeds'                 => false,
	);
	$args = array(
		'label'                 => __( 'Team member', PBS_PREFIX ),
		'description'           => __( 'Post Type Description', PBS_PREFIX ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes', 'excerpt' ),
		'taxonomies'            => array( 'team_category', 'team_tag' ),
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 22,
		'menu_icon'             => 'dashicons-groups',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => false,
		'exclude_from_search'   => true,
		'publicly_queryable'    => true,
		'rewrite'               => $rewrite,
		'capability_type'       => 'page',
		'show_in_rest'          => true,
	);

	register_post_type( 'team-member', $args );
}

// Register Team member Taxonomy
function frl_pbs_team_member_taxonomy()
{
	$labels_category = array(
		'name'                       => _x( 'Team Categories', 'Taxonomy General Name', PBS_PREFIX ),
		'singular_name'              => _x( 'Team Category', 'Taxonomy Singular Name', PBS_PREFIX ),
		'menu_name'                  => __( 'Team Categories', PBS_PREFIX ),
		'all_items'                  => __( 'All Categories', PBS_PREFIX ),
		'parent_item'                => __( 'Parent Category', PBS_PREFIX ),
		'parent_item_colon'          => __( 'Parent Category:', PBS_PREFIX ),
		'new_item_name'              => __( 'New Team category', PBS_PREFIX ),
		'add_new_item'               => __( 'Add New Team category', PBS_PREFIX ),
		'edit_item'                  => __( 'Edit Team category', PBS_PREFIX ),
		'update_item'                => __( 'Update Team category', PBS_PREFIX ),
		'view_item'                  => __( 'View Team category', PBS_PREFIX ),
		'separate_items_with_commas' => __( 'Separate categories with commas', PBS_PREFIX ),
		'add_or_remove_items'        => __( 'Add or remove categories', PBS_PREFIX ),
		'choose_from_most_used'      => __( 'Choose from the most used', PBS_PREFIX ),
		'popular_items'              => __( 'Popular categories', PBS_PREFIX ),
		'search_items'               => __( 'Search categories', PBS_PREFIX ),
		'not_found'                  => __( 'Category Not Found', PBS_PREFIX ),
		'no_terms'                   => __( 'No categories', PBS_PREFIX ),
		'items_list'                 => __( 'Categories list', PBS_PREFIX ),
		'items_list_navigation'      => __( 'Categories list navigation', PBS_PREFIX ),
	);
	$arg_category = array(
		'labels'                     => $labels_category,
		'hierarchical'               => true,
		'public'                     => true,
		'show_ui'                    => true,
		'show_admin_column'          => true,
		'show_in_nav_menus'          => true,
		'show_tagcloud'              => false,
		'show_in_rest'               => true,
	);

	register_taxonomy( 'team_category', 'team-member', $arg_category );

	$labels_tag = array(
		'name'                       => _x( 'Team Tags', 'Taxonomy General Name', PBS_PREFIX ),
		'singular_name'              => _x( 'Team Tag', 'Taxonomy Singular Name', PBS_PREFIX ),
		'menu_name'                  => __( 'Team Tags', PBS_PREFIX ),
		'all_items'                  => __( 'All Tags', PBS_PREFIX ),
		'parent_item'                => __( 'Parent Tag', PBS_PREFIX ),
		'parent_item_colon'          => __( 'Parent Tag:', PBS_PREFIX ),
		'new_item_name'              => __( 'New Team tag', PBS_PREFIX ),
		'add_new_item'               => __( 'Add New Team tag', PBS_PREFIX ),
		'edit_item'                  => __( 'Edit Team tag', PBS_PREFIX ),
		'update_item'                => __( 'Update Team tag', PBS_PREFIX ),
		'view_item'                  => __( 'View Team tag', PBS_PREFIX ),
		'separate_items_with_commas' => __( 'Separate tags with commas', PBS_PREFIX ),
		'add_or_remove_items'        => __( 'Add or remove tags', PBS_PREFIX ),
		'choose_from_most_used'      => __( 'Choose from the most used', PBS_PREFIX ),
		'popular_items'              => __( 'Popular tags', PBS_PREFIX ),
		'search_items'               => __( 'Search tags', PBS_PREFIX ),
		'not_found'                  => __( 'Category Not Found', PBS_PREFIX ),
		'no_terms'                   => __( 'No taga', PBS_PREFIX ),
		'items_list'                 => __( 'Tags list', PBS_PREFIX ),
		'items_list_navigation'      => __( 'Tags list navigation', PBS_PREFIX ),
	);
	$args_tag = array(
		'labels'                     => $labels_tag,
		'hierarchical'               => false,
		'public'                     => true,
		'show_ui'                    => true,
		'show_admin_column'          => true,
		'show_in_nav_menus'          => true,
		'show_tagcloud'              => false,
		'show_in_rest'               => true,
	);

	register_taxonomy( 'team_tag', 'team-member', $args_tag );
}

// Register University Post Type
function frl_pbs_custom_post_type_university()
{
	$labels = array(
		'name'                  => _x( 'Universities', 'Post Type General Name', PBS_PREFIX ),
		'singular_name'         => _x( 'University', 'Post Type Singular Name', PBS_PREFIX ),
		'menu_name'             => __( 'Universities', PBS_PREFIX ),
		'name_admin_bar'        => __( 'University', PBS_PREFIX ),
		'archives'              => __( 'University Archives', PBS_PREFIX ),
		'attributes'            => __( 'Universities Attributes', PBS_PREFIX ),
		'parent_item_colon'     => __( 'Parent University', PBS_PREFIX ),
		'all_items'             => __( 'All Universities', PBS_PREFIX ),
		'add_new_item'          => __( 'Add New University', PBS_PREFIX ),
		'add_new'               => __( 'Add New', PBS_PREFIX ),
		'new_item'              => __( 'New University', PBS_PREFIX ),
		'edit_item'             => __( 'Edit University', PBS_PREFIX ),
		'update_item'           => __( 'Update University', PBS_PREFIX ),
		'view_item'             => __( 'View University', PBS_PREFIX ),
		'view_items'            => __( 'View Universities', PBS_PREFIX ),
		'search_items'          => __( 'Search Universities', PBS_PREFIX ),
		'not_found'             => __( 'No universities found.', PBS_PREFIX ),
		'not_found_in_trash'    => __( 'No universities found in Trash.', PBS_PREFIX ),
		'featured_image'        => __( 'Featured Image', PBS_PREFIX ),
		'set_featured_image'    => __( 'Set featured image', PBS_PREFIX ),
		'remove_featured_image' => __( 'Remove featured image', PBS_PREFIX ),
		'use_featured_image'    => __( 'Use as featured image', PBS_PREFIX ),
		'insert_into_item'      => __( 'Insert into university', PBS_PREFIX ),
		'uploaded_to_this_item' => __( 'Uploaded to this university', PBS_PREFIX ),
		'items_list'            => __( 'Universities list', PBS_PREFIX ),
		'items_list_navigation' => __( 'Universities list navigation', PBS_PREFIX ),
		'filter_items_list'     => __( 'Filter universities list', PBS_PREFIX ),
	);
	$rewrite = array(
		'slug'                  => 'info/university',
		'with_front'            => false,
		'pages'                 => true,
		'feeds'                 => false,
	);
	$args = array(
		'label'                 => __( 'University', PBS_PREFIX ),
		'description'           => __( 'Post Type Description', PBS_PREFIX ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes', 'excerpt' ),
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 20,
		'menu_icon'             => 'dashicons-bank',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => false,
		'exclude_from_search'   => false,
		'publicly_queryable'    => true,
		'rewrite'               => $rewrite,
		'capability_type'       => 'page',
		'show_in_rest'          => true,
	);

	register_post_type( 'university', $args );
}

// Remove Service taxonomy in frontend
function frl_pbs_kill_taxonomy_archives( $query ) {

    if (frl_is_admin() || !is_archive()) {
        return;
    }

    $service_taxonomies = array('service_category', 'service_tag');
    if ($query->is_tax($service_taxonomies)) {
        $query->set_404();
    }
}

/**
 * Resolve /about/{slug}/ collision between team-member CPT and child pages.
 *
 * The team-member CPT rewrite slug 'about' generates a native rewrite rule
 * about/([^/]+)/?$ that matches before the generic page rule. When no
 * team-member post exists for the requested slug, fall back to page resolution
 * so child pages like /about/team/ resolve correctly.
 *
 * Hooks at parse_request priority 1 — rewrites matched_rule and query_vars
 * before WP_Query parses, so WordPress resolves the page natively.
 */
/**
 * Resolve /{parent}/{slug}/ collision between team-member CPT and child pages.
 *
 * The team-member CPT rewrite slug 'about' generates a native rewrite rule
 * about/([^/]+)/?$ that matches before the generic page rule. When no
 * team-member post exists for the requested slug, fall back to page resolution
 * so child pages like /about/team/ or /ru/o-nas/komanda/ resolve correctly.
 *
 * Hooks at parse_request priority 1 — rewrites matched_rule and query_vars
 * before WP_Query parses, so WordPress resolves the page natively.
 */
add_action('parse_request', function ($wp) {
    if (empty($wp->query_vars['team-member'])) {
        return;
    }

    $slug = $wp->query_vars['team-member'];

    // If a real team-member post exists, let it win.
    $team_member = get_page_by_path($slug, OBJECT, 'team-member');
    if ($team_member) {
        return;
    }

    // Check for child page of 'about' (English) or any translated parent.
    // Try English first, then search for any page ending with /{$slug}.
    $page = get_page_by_path("about/{$slug}", OBJECT, 'page');
    if (!$page) {
        // Fallback: find any published page with this slug, regardless of parent.
        $candidates = get_posts([
            'name'        => $slug,
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
        ]);
        if (!empty($candidates)) {
            $page = $candidates[0];
        }
    }
    if (!$page) {
        return;
    }

    // Build the correct pagename from the page's hierarchy.
    $pagename = $page->post_name;
    $parent_id = $page->post_parent;
    while ($parent_id) {
        $parent = get_post($parent_id);
        if (!$parent) {
            break;
        }
        $pagename = $parent->post_name . '/' . $pagename;
        $parent_id = $parent->post_parent;
    }

    // Rewrite query vars and matched rule so WP resolves this as a page.
    unset(
        $wp->query_vars['team-member'],
        $wp->query_vars['post_type'],
        $wp->query_vars['name'],
        $wp->query_vars['error']
    );
    $wp->query_vars['page_id']   = $page->ID;
    $wp->query_vars['pagename']  = $pagename;
    $wp->matched_rule  = '(.?.+?)(/[0-9]+)?/?$';
    $wp->matched_query = 'pagename=' . $pagename . '&page=';
}, 1);

/**
 * Fix page permalinks for translated child pages where the parent slug
 * differs due to translation (e.g., /ru/o-nas/komanda/ instead of /ru/about/komanda/).
 *
 * Hooks into page_link to ensure get_permalink() returns the correct translated path,
 * preventing Polylang's check_canonical_url() from redirecting.
 */
add_filter('page_link', function ($link, $post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'page') {
        return $link;
    }

    // Build the correct permalink from the page's actual hierarchy.
    $pagename = $post->post_name;
    $parent_id = $post->post_parent;
    while ($parent_id) {
        $parent = get_post($parent_id);
        if (!$parent) {
            break;
        }
        $pagename = $parent->post_name . '/' . $pagename;
        $parent_id = $parent->post_parent;
    }

    // Rebuild the URL with the correct path, preserving language prefix.
    $current_lang = frl_get_language();
    $default_lang = frl_get_default_language();
    $home_url = frl_get_home_url($current_lang);

    // Build the correct path with language prefix only if not default language.
    $path_prefix = ($current_lang !== $default_lang) ? $current_lang . '/' : '';
    $new_path = '/' . $path_prefix . $pagename . '/';

    $link = rtrim($home_url, '/') . $new_path;

    return $link;
}, 10, 2);
