<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole Docs Display
 *
 * Handles the discovery, rendering, and AJAX delivery of the plugin's
 * documentation files (docs/*.md), exposed through the "Documentation" tab
 * on the admin settings page.
 *
 * @package Fralenuvole
 * @since  1.0.0
 */

/**
 * Retrieve the list of available documentation files.
 *
 * Scans docs/*.md, extracts the H1 title and file modification time,
 * and caches the result in the staticdata group.
 *
 * @return array[] List of docs with keys: slug, title, file, mtime.
 */
function frl_docs_get_documents() {
	static $docs_cache = null;

	if ( $docs_cache !== null ) {
		return $docs_cache;
	}

	$docs_dir = FRL_DIR_PATH . 'docs/';

	// Build a cache-key signature from the latest filemtime + file count.
	$sig = frl_docs_build_signature( $docs_dir );

	$docs = frl_cache_remember(
		'staticdata',
		'docs_index_' . $sig,
		function () use ( $docs_dir ) {
			$files = glob( $docs_dir . '*.md', GLOB_NOSORT );

			$docs = array();
			if ( empty( $files ) ) {
				return $docs;
			}

			sort( $files );

			foreach ( $files as $file ) {
				$slug  = strtolower( basename( $file, '.md' ) );
				$title = frl_docs_extract_title( $file );
				$mtime = filemtime( $file );

				$docs[] = array(
					'slug'  => $slug,
					'title' => $title,
					'file'  => $file,
					'mtime' => $mtime,
				);
			}

			return $docs;
		}
	);

	// Guard against stale/polluted cache: if the persistent cache returned
	// an empty array but the directory actually contains .md files, purge
	// the cache entry and rebuild it eagerly.
	if ( empty( $docs ) ) {
		$on_disk = glob( $docs_dir . '*.md', GLOB_NOSORT );

		if ( ! empty( $on_disk ) ) {
			frl_cache_clear( 'staticdata', 'docs_index_' . $sig );

			$docs = frl_cache_remember(
				'staticdata',
				'docs_index_' . $sig,
				function () use ( $docs_dir ) {
					$files = glob( $docs_dir . '*.md', GLOB_NOSORT );
					$docs  = array();
					if ( empty( $files ) ) {
						return $docs;
					}
					sort( $files );
					foreach ( $files as $file ) {
						$slug  = strtolower( basename( $file, '.md' ) );
						$title = frl_docs_extract_title( $file );
						$mtime = filemtime( $file );
						$docs[] = array(
							'slug'  => $slug,
							'title' => $title,
							'file'  => $file,
							'mtime' => $mtime,
						);
					}
					return $docs;
				}
			);
		}
	}

	$docs_cache = $docs;
	return $docs_cache;
}

/**
 * Build a cache-busting signature from the docs directory.
 *
 * Uses the latest filemtime and file count so that adding, removing,
 * or editing any .md file invalidates the cached index.
 *
 * @param string $docs_dir Absolute path to docs/.
 * @return string Signature string (md5 hash).
 */
function frl_docs_build_signature( $docs_dir ) {
	$files  = glob( $docs_dir . '*.md', GLOB_NOSORT );
	$count  = count( $files );
	$latest = 0;

	foreach ( $files as $f ) {
		$m = filemtime( $f );
		if ( $m > $latest ) {
			$latest = $m;
		}
	}

	return md5( $count . '-' . $latest );
}

/**
 * Extract the first H1 heading from a Markdown file as its title.
 *
 * Falls back to the basename (without .md) if no `# ` line is found.
 *
 * @param string $file Absolute path to the .md file.
 * @return string Extracted title.
 */
function frl_docs_extract_title( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		return basename( $file, '.md' );
	}

	$title = basename( $file, '.md' );

	$line = fgets( $fh );
	while ( $line !== false ) {
		$trimmed = ltrim( $line );
		if ( str_starts_with( $trimmed, '# ' ) ) {
			$title = trim( substr( $trimmed, 2 ) );
			break;
		}
		$line = fgets( $fh );
	}

	fclose( $fh );
	return $title;
}

/**
 * Render a documentation file to HTML.
 *
 * Reads, converts via Parsedown Extra, rewrites internal links,
 * sanitizes the output, and caches in staticdata keyed by filemtime.
 *
 * @param string $slug Document slug (lowercase basename without .md).
 * @return string|false Rendered HTML or false on failure.
 */
function frl_docs_render_document( $slug ) {
	$documents = frl_docs_get_documents();

	// Whitelist check — slug must match a discovered document exactly.
	$doc = null;
	foreach ( $documents as $d ) {
		if ( $d['slug'] === $slug ) {
			$doc = $d;
			break;
		}
	}

	if ( $doc === null ) {
		return false;
	}

	$file      = $doc['file'];
	$mtime     = $doc['mtime'];
	$cache_key = 'docs_html_' . $slug . '_' . $mtime;

	return frl_cache_remember(
		'staticdata',
		$cache_key,
		function () use ( $file ) {
			if ( ! is_readable( $file ) ) {
				return false;
			}

			$markdown = file_get_contents( $file );
			if ( $markdown === false ) {
				return false;
			}

			// Load Parsedown + Parsedown Extra (lazy, only on miss).
			require_once FRL_DIR_PATH . 'assets/lib/parsedown/Parsedown.php';
			require_once FRL_DIR_PATH . 'assets/lib/parsedown/ParsedownExtra.php';

			$parsedown = new ParsedownExtra();
			$parsedown->setSafeMode( false ); // Docs are plugin-authored, trusted.
			$html = $parsedown->text( $markdown );

			// Rewrite internal links.
			$html = frl_docs_rewrite_links( $html );

			// Sanitize (defense-in-depth — docs are trusted but we run kses).
			return wp_kses_post( $html );
		}
	);
}

/**
 * Rewrite links in rendered doc HTML.
 *
 * - Relative *.md links → data-frl-doc attributes for in-viewer navigation.
 * - External http(s) links → target="_blank" rel="noopener".
 *
 * @param string $html Rendered HTML.
 * @return string Rewritten HTML.
 */
function frl_docs_rewrite_links( $html ) {
	// Pattern 1: <a href="something.md"> → <a href="#" data-frl-doc="something">
	$html = preg_replace_callback(
		'/<a\s[^>]*\shref="([^"#][^"]*\.md)"([^>]*)>/i',
		function ( $matches ) {
			$href = $matches[1];
			$rest = $matches[2];

			// Extract the slug: "CACHE.md" → "cache", "docs/CACHE.md" → "cache"
			$slug = strtolower( basename( $href, '.md' ) );

			return '<a href="#" data-frl-doc="' . esc_attr( $slug ) . '"' . $rest . '>';
		},
		$html
	);

	// Pattern 2: external http(s) links → target="_blank" rel="noopener"
	$html = preg_replace_callback(
		'/<a\s[^>]*\shref="https?:\/\/[^"]*"[^>]*>/i',
		function ( $matches ) {
			$tag = $matches[0];
			if ( ! str_contains( $tag, 'target=' ) ) {
				$tag = str_replace( '>', ' target="_blank" rel="noopener">', $tag );
			}
			return $tag;
		},
		$html
	);

	return $html;
}

/**
 * Render the Documentation tab shell.
 *
 * Outputs the server-side doc index (a list of links with data-frl-doc
 * attributes) and an empty content container that admin-docs.js fills
 * via AJAX on click.
 *
 * @return void
 */
function frl_docs_content_render() {

	$documents = frl_docs_get_documents();

	if ( empty( $documents ) ) {
		echo '<p>' . esc_html__( 'No documentation files found.', FRL_PREFIX ) . '</p>';
		return;
	}

	?>
	<div class="frl-docs-viewer" id="frl-docs-viewer">
		<div class="frl-docs-index">
			<ul>
				<?php foreach ( $documents as $doc ) : ?>
					<li>
						<a href="#"
							data-frl-doc="<?php echo esc_attr( $doc['slug'] ); ?>"
							title="<?php echo esc_attr( $doc['title'] ); ?>">
							<?php echo esc_html( $doc['title'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div class="frl-docs-content" id="frl-docs-content">
			<p class="frl-docs-placeholder">
				<?php esc_html_e( 'Select a document from the list to view it.', FRL_PREFIX ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * AJAX handler for loading documentation content.
 *
 * Auto-discovered as wp_ajax_frl_post_ajax_docs_content via
 * frl_autodiscover_admin_actions(). Verifies the nonce and the
 * page-level FRL_PLUGIN_ACCESS capability, validates the requested
 * doc slug, renders the Markdown, and returns the HTML.
 *
 * @return void (sends JSON response via wp_send_json_*)
 */
function frl_post_ajax_docs_content() {
	// Verify nonce + FRL_PLUGIN_ACCESS capability.
	frl_verify_simple_nonce( 'docs_content', 'nonce', 'GET' );

	$slug = isset( $_REQUEST['doc'] ) ? sanitize_key( $_REQUEST['doc'] ) : '';

	if ( empty( $slug ) ) {
		wp_send_json_error( array( 'message' => __( 'No document specified.', FRL_PREFIX ) ) );
	}

	$html = frl_docs_render_document( $slug );

	if ( $html === false ) {
		wp_send_json_error( array( 'message' => __( 'Document not found.', FRL_PREFIX ) ) );
	}

	// Resolve the title for the response.
	$title     = '';
	$documents = frl_docs_get_documents();
	foreach ( $documents as $doc ) {
		if ( $doc['slug'] === $slug ) {
			$title = $doc['title'];
			break;
		}
	}

	wp_send_json_success(
		array(
			'html'  => $html,
			'title' => $title,
			'slug'  => $slug,
		)
	);
}
