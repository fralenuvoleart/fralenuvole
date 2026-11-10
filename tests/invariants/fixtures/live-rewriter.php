<?php
/**
 * Rewriter configuration read from pbservices.ge (live, 5.8.8) on 2026-11-10.
 *
 * The routing test builds its fixture from this array so the cases match the
 * production URL shapes (four languages, /blog/%postname%/, category base
 * removal, service CPT base translation, comments disabled).
 *
 * Values proven by read-only WP-CLI: pll languages en/ru/ar/zh; show_on_front
 * page; page_on_front 7 (homepage); page_for_posts 15 (blog); permalink
 * /blog/%postname%/; category/tag base empty; frl_rewriter_remove_tax_base
 * category; frl_rewriter_remove_cpt_base flag+jurisdiction (CPTs not
 * registered, so the feature is disabled); frl_rewriter_translate_cpt_slugs_service
 * en|services ru|uslugi ar|offers (no zh mapping); frl_disable_comments 1.
 *
 * @package Fralenuvole
 */

return array(
	'languages'           => array( 'en', 'ru', 'ar', 'zh' ),
	'default_language'    => 'en',
	'permalink_structure' => '/blog/%postname%/',
	'category_base'       => '',
	'tag_base'            => '',
	'remove_tax_base'     => array( 'category' ),
	'remove_cpt_base'     => array( 'flag', 'jurisdiction' ),
	'translate_post_base' => '',
	'translate_cpt_slugs' => array(
		'service' => array(
			'en' => 'services',
			'ru' => 'uslugi',
			'ar' => 'offers',
		),
	),
	'disable_comments'    => true,
	'post_types'          => array(
		'service'     => array(
			'rewrite'      => array( 'slug' => 'service' ),
			'has_archive'  => false,
			'hierarchical' => false,
			'query_var'    => 'service',
		),
		'lp'          => array(
			'rewrite'      => array( 'slug' => 'lp' ),
			'has_archive'  => false,
			'hierarchical' => false,
			'query_var'    => 'lp',
		),
		'team-member' => array(
			'rewrite'      => array( 'slug' => 'about' ),
			'has_archive'  => false,
			'hierarchical' => false,
			'query_var'    => 'team-member',
		),
		'university'  => array(
			'rewrite'      => array( 'slug' => 'university' ),
			'has_archive'  => false,
			'hierarchical' => false,
			'query_var'    => 'university',
		),
	),
	'taxonomies'          => array(
		'category'         => array(
			'rewrite'      => array( 'slug' => 'category' ),
			'query_var'    => 'category_name',
			'hierarchical' => true,
		),
		'post_tag'         => array(
			'rewrite'      => array( 'slug' => 'tag' ),
			'query_var'    => 'tag',
			'hierarchical' => false,
		),
		'service_category' => array(
			'rewrite'      => array( 'slug' => 'service_category' ),
			'query_var'    => 'service_category',
			'hierarchical' => true,
		),
		'service_tag'      => array(
			'rewrite'      => array( 'slug' => 'service_tag' ),
			'query_var'    => 'service_tag',
			'hierarchical' => false,
		),
		'team_category'    => array(
			'rewrite'      => array( 'slug' => 'team_category' ),
			'query_var'    => 'team_category',
			'hierarchical' => true,
		),
		'team_tag'         => array(
			'rewrite'      => array( 'slug' => 'team_tag' ),
			'query_var'    => 'team_tag',
			'hierarchical' => false,
		),
	),
	'samples'             => array(
		'post'             => 'best-luxury-real-estate-options-available-in-georgia-caucasus',
		'category_en'      => 'licensing',
		'category_ru'      => 'liczenzirovanie',
		'service'          => 'igaming-license-georgia',
		'team_member'      => 'daria-migunova',
		'page_under_about' => 'team',
		'posts_page'       => 'blog',
		'front_page'       => 'homepage',
	),
);
