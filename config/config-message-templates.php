<?php
/**
 * Shared Message Templates — CTA links and email bodies.
 *
 * Consumed by both the call_to_actions module (on-site CTA links) and the
 * wsform module (email @@cta:...@@ / @@include:...@@ directives). Living in
 * config/ keeps the two modules decoupled — neither depends on the other.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Per-environment CTA templates: URL, template, subject.
// Each entry is a list of actions; {reference_id}, {template}, {subject} and
// {br} placeholders are resolved at click time (client) or email-build time.
const FRL_CTA_TEMPLATES = array(
	'default' => array(),
	'pbs'     => array(
		array(
			'action_id' => 'whatsapp',
			'url'       => 'https://wa.me/995599654454?text={template}',
			'template'  => "Support number: PIN-{reference_id}-PBS (please do not delete)\r\n---\r\nHello, please type your message below.{br}{br}",
		),
		array(
			'action_id' => 'telegram',
			'url'       => 'https://t.me/pbservicesGeorgia?text={template}',
			'template'  => "Support number: PIN-{reference_id}-PBS (please do not delete)\r\n---\r\nHello, please type your message below.{br}{br}",
		),
		array(
			'action_id' => 'email',
			'url'       => 'mailto:info@pbservices.ge?subject={subject}&body={template}',
			'subject'   => 'PB Services Enquiry from Website',
			'template'  => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS",
		),
	),
	'pbp'     => array(
		array(
			'action_id' => 'whatsapp',
			'url'       => 'https://wa.me/995511290408?text={template}',
			'template'  => "Support number: PIN-{reference_id}-PBS (please do not delete)\r\n---\r\nHello, please type your message below.{br}{br}",
		),
		array(
			'action_id' => 'telegram',
			'url'       => 'https://t.me/PBPropertyGeorgia',
			'template'  => '{reference_id}',
		),
		array(
			'action_id' => 'email',
			'url'       => 'mailto:info@pbservices.ge?subject={subject}&body={template}',
			'subject'   => 'PB Property Enquiry',
			'template'  => "Hello,\r\nI'd like to enquire about your services.\r\n\r\n\r\n---\r\nSupport number: PIN-{reference_id}-PBS",
		),
	),
);

// Email template whitelist: @@include:<key>@@ → file in assets/templates/emails/.
// A whitelist is safer than a filename regex (no path traversal, explicit set).
const FRL_EMAIL_TEMPLATES = array(
	'confirmation_email_weekday' => 'confirmation_email_weekday.html',
	'confirmation_email_weekend' => 'confirmation_email_weekend.html',
);
