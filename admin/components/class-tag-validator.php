<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * tag-validator.php - Validate plugin HTML tags
 */

/**
 * Tag Validator Class
 * Handles validating HTML tags in frontend pages
 *
 * This class provides tag validation functionality with a clean API separation:
 * - validate_url() - Handles validation logic and returns structured data
 * - render_tag_validation_results() - Renders validation results as HTML
 *
 * The render() method uses these newer methods internally for better separation of concerns.
 * JavaScript functionality is handled by the external assets/js/tag-validator.js file,
 * which is loaded in scripts-ui.php.
 */
class Frl_Tag_Validator {

	/**
	 * Get form inputs HTML.
	 *
	 * @param string $url URL to pre-fill in the form.
	 * @return string Form inputs HTML.
	 */
	private function get_form_inputs_html( $url = '' ) {
		return '<div id="tag-validator-controls">
            <div class="form-row">
                <label for="tag_validator_url">URL:</label>
                 <div class="form-url">
                    <input type="url" id="tag_validator_url" name="frl_tag_validator_url" value="' . esc_attr( $url ) . '" placeholder="https://example.com/page" required>
                 </div>
               <button type="button" id="tag-validator-button" class="button button-secondary">Validate Tags</button>
             </div>
        </div>';
	}

	/**
	 * Get page content directly without sanitizing the URL.
	 *
	 * @param string $url URL to fetch.
	 * @return array{html?: string, status?: int, checked_url: string, warning?: string, error?: string} Content and meta information.
	 */
	private function direct_get_page_content( $url ) {
		// Basic cleanup without losing parts of the URL
		$url = trim( $url );

		// Add protocol if missing
		if ( ! preg_match( '~^(?:f|ht)tps?://~i', $url ) ) {
			$url = 'https://' . $url;
		}

		// Parse the URL to get components
		$parsed_url = parse_url( $url );
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		$query      = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';

		if ( empty( $host ) ) {
			return array(
				'error'       => 'Invalid URL format: No host specified',
				'checked_url' => $url,
			);
		}

		// Use cURL with specific options to bypass loopback restrictions
		$ch = curl_init();

		// Set cURL options to mimic an anonymous visitor
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL                  => $url,
				CURLOPT_RETURNTRANSFER       => true,
				CURLOPT_FOLLOWLOCATION       => true,
				CURLOPT_MAXREDIRS            => 5,
				CURLOPT_TIMEOUT              => 30,
				// TLS verification kept ON (unlike an earlier version of this code): this tool is
				// manage_options-gated, but a MITM able to feed it fabricated "validated" content
				// is a real risk with verification off, and disabling it has no bearing on the
				// loopback/internal-URL testing purpose below — only the DNS/firewall options do.
				CURLOPT_SSL_VERIFYPEER       => true,
				CURLOPT_SSL_VERIFYHOST       => 2,
				CURLOPT_HTTPHEADER           => array(
					'Host: ' . $host,
					'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/96.0.4664.110 Safari/537.36',
					'Accept: text/html,application/xhtml+xml,application/xml',
					'Accept-Language: en-US,en;q=0.9',
					'Cache-Control: no-cache',
					'Connection: close',
				),
				// Use external DNS resolution to avoid loopback detection
				CURLOPT_DNS_USE_GLOBAL_CACHE => false,
				// Bypass potential firewall rules
				CURLOPT_FRESH_CONNECT        => true,
				// Force IPv4 to avoid IPv6 issues
				CURLOPT_IPRESOLVE            => (int) CURL_IPRESOLVE_V4,
			)
		);

		// Execute the cURL request
		$html        = curl_exec( $ch );
		$error       = curl_error( $ch );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$info        = curl_getinfo( $ch );

		// Check for errors
		if ( $error ) {
			return array(
				'error'       => 'Failed to fetch URL: ' . $error,
				'checked_url' => $url,
			);
		}

		// Check HTTP status - provide specific warning messages for common HTTP errors
		if ( $status_code !== 200 ) {
			$warning_message = "HTTP status code {$status_code}";

			switch ( $status_code ) {
				case 404:
					$warning_message = "The page at {$url} was not found (404). The validation is performed on the 404 page content.";
					break;
				case 403:
					$warning_message = "Access to {$url} is forbidden (403). The validation is performed on the error page content.";
					break;
				case 500:
					$warning_message = "The server at {$url} encountered an internal error (500). The validation is performed on the error page content.";
					break;
				case 502:
				case 503:
				case 504:
					$warning_message = "The server at {$url} is temporarily unavailable (Status: {$status_code}). The validation is performed on the error page content.";
					break;
			}

			// Return content with a warning instead of an error
			return array(
				'html'        => $html,
				'status'      => $status_code,
				'checked_url' => $url,
				'warning'     => $warning_message,
			);
		}

		// Validate that we got some HTML
		if ( empty( $html ) ) {
			return array(
				'error'       => 'Empty response from URL',
				'checked_url' => $url,
			);
		}

		return array(
			'html'        => $html,
			'status'      => $status_code,
			'checked_url' => $url,
		);
	}

	/**
	 * Validate a URL against tags.
	 *
	 * This is the API for validations, handling sanitization, fetching, and tag validation.
	 *
	 * @param string $url URL to validate.
	 * @param string $tags_string Comma-separated list of tags to validate.
	 * @return array{tags: array<string, mixed>, checked_url: string, warning?: string, error?: string} Validation results or error message.
	 */
	public function validate_url( $url, $tags_string ) {
		// Basic URL sanitization
		$url = trim( $url );

		// Add protocol if missing
		if ( ! preg_match( '~^(?:f|ht)tps?://~i', $url ) ) {
			$url = 'https://' . $url;
		}

		// Fixed set of tags - for better consistency we always use these
		if ( empty( $tags_string ) ) {
			$tags_string = '#frl-critical-css,#frl-preload-img,#frl-schema';
		}

		// Initialize results array with the URL we're validating
		$results = array(
			'tags'        => array(),
			'checked_url' => $url,
		);

		// Check if we're validating the local site or an external URL
		$home_url_host = parse_url( home_url(), PHP_URL_HOST );
		$url_host      = parse_url( $url, PHP_URL_HOST );

		// Get page content - use direct method for all URLs since wp_remote_get has issues with local URLs
		$html_content_result = $this->direct_get_page_content( $url );

		// Check for errors
		if ( isset( $html_content_result['error'] ) ) {
			return array(
				'error'       => $html_content_result['error'],
				'checked_url' => $html_content_result['checked_url'],
			);
		}

		$html_content = $html_content_result['html'];

		// Add warning to results if present
		if ( isset( $html_content_result['warning'] ) ) {
			$results['warning'] = $html_content_result['warning'];
		}

		// Add check for print media scripts
		$results['tags']['print-media-scripts'] = $this->extract_print_media_scripts( $html_content );

		// Split tags and clean them
		$tags = array_map( 'trim', explode( ',', $tags_string ) );

		// Validate each tag
		foreach ( $tags as $tag ) {
			if ( empty( $tag ) ) {
				continue;
			}

			// Clean and standardize the tag
			$display_tag = $tag; // Keep original for display
			$clean_tag   = ltrim( $tag, '#' );

			// Special handling for the priority tags
			if ( $clean_tag === 'frl-critical-css' ) {
				$results['tags'][ $display_tag ] = $this->extract_critical_css( $html_content );
				continue;
			} elseif ( $clean_tag === 'frl-schema' ) {
				$results['tags'][ $display_tag ] = $this->extract_schema_script( $html_content );
				continue;
			} elseif ( $clean_tag === 'frl-preload-img' ) {
				$results['tags'][ $display_tag ] = $this->extract_preload_images( $html_content );
				continue;
			}

			// For other tags
			$count                           = $this->count_tag_occurrences( $html_content, $clean_tag );
			$results['tags'][ $display_tag ] = array(
				'found'    => $count > 0,
				'count'    => $count,
				'examples' => $this->extract_tag_examples( $html_content, $clean_tag, 3 ),
			);
		}

		return $results;
	}

	/**
	 * Extract script tags with media='print' and onload="this.media='all'" attributes
	 * @param string $html HTML content
	 * @return array Status and examples of found scripts
	 */
	private function extract_print_media_scripts( $html ) {
		// Get all link elements with the fralenuvole plugin attribute
		$elements = $this->find_plugin_elements( $html, 'link' );

		// Filter for those with media='print' and onload attributes
		$examples   = array();
		$script_ids = array();

		foreach ( $elements as $element ) {
			if (
				preg_match( '/media\s*=\s*(["\'])print\s*\\1/i', $element ) &&
				preg_match( '/onload\s*=\s*(["\'])/i', $element )
			) {
				// Convert each element to a properly formatted HTML string
				$examples[] = trim( $element );

				// Extract the ID if present
				if ( preg_match( '/id\s*=\s*(["\'])([^"\']+)\\1/i', $element, $matches ) ) {
					$script_ids[] = $matches[2];
				}
			}
		}

		// Deduplicate examples
		$examples = array_unique( $examples );

		// Limit to first 5 examples
		$examples = array_slice( $examples, 0, 5 );

		// Return formatted results
		return array(
			'found'        => count( $examples ) > 0,
			'count'        => count( $examples ),
			'examples'     => $examples,
			'script_ids'   => $script_ids,
			'types'        => array( 'print-media' => true ),
			'display_name' => 'Deferred CSS',
		);
	}

	/**
	 * Find all elements with the fralenuvole plugin attribute
	 * @param string $html HTML content to search
	 * @param string $tag_type Optional tag type to filter (e.g., 'script', 'link', 'style')
	 * @return array Elements that match the criteria
	 */
	private function find_plugin_elements( $html, $tag_type = '' ) {
		// Base pattern that matches any tag with data-plugin="fralenuvole"
		$tag_pattern = $tag_type ? $tag_type : '[a-z]+';
		$pattern     = '/<' . $tag_pattern . '[^>]*data-plugin=["\']fralenuvole["\'][^>]*>.*?<\/' . $tag_pattern . '>/is';

		// For self-closing tags like <link> - with or without trailing slash
		$self_closing_pattern = '/<' . $tag_pattern . '[^>]*data-plugin=["\']fralenuvole["\'][^>]*\/?>/is';

		$matches_normal       = array();
		$matches_self_closing = array();
		preg_match_all( $pattern, $html, $matches_normal );
		preg_match_all( $self_closing_pattern, $html, $matches_self_closing );

		// Combine results
		$elements = array_merge( $matches_normal[0] ?? array(), $matches_self_closing[0] ?? array() );

		return $elements;
	}

	/**
	 * Count occurrences of a tag in HTML content
	 * @param string $html HTML content
	 * @param string $tag Tag to search for
	 * @return int Number of occurrences
	 */
	private function count_tag_occurrences( $html, $tag ) {
		// Check for HTML elements, attributes, class names, and IDs
		$count = 0;

		// Check for element IDs (e.g., id="frl-schema")
		if ( preg_match_all( '/id=(["\'])' . preg_quote( $tag, '/' ) . '\\1/i', $html, $matches ) ) {
			$count += count( $matches[0] );
		}

		// Check for HTML elements (e.g., <frl-tag>)
		if ( preg_match_all( '/<' . preg_quote( $tag, '/' ) . '[\s>]/i', $html, $matches ) ) {
			$count += count( $matches[0] );
		}

		// Check for data attributes (e.g., data-frl-tag)
		if ( preg_match_all( '/\s' . preg_quote( $tag, '/' ) . '[\s=]/i', $html, $matches ) ) {
			$count += count( $matches[0] );
		}

		// Check for class names (e.g., class="... frl-tag ...")
		if ( preg_match_all( '/class=(["\'])(?:[^"\']*\s)?' . preg_quote( $tag, '/' ) . '(?:\s[^"\']*)?\\1/i', $html, $matches ) ) {
			$count += count( $matches[0] );
		}

		return $count;
	}

	/**
	 * Extract examples of tag usage in HTML
	 * @param string $html HTML content
	 * @param string $tag Tag to search for
	 * @param int $limit Maximum number of examples
	 * @return array Examples of tag usage
	 */
	private function extract_tag_examples( $html, $tag, $limit = 3 ) {
		$examples = array();

		// Create regex patterns for different types of tags
		$patterns = array(
			// HTML element IDs
			'/<[^>]*id=(["\'])' . preg_quote( $tag, '/' ) . '\\1[^>]*>.*?<\/[^>]*>/is',
			'/<[^>]*id=(["\'])' . preg_quote( $tag, '/' ) . '\\1[^>]*\/>/is',

			// HTML elements
			'/<' . preg_quote( $tag, '/' ) . '[^>]*>.*?<\/' . preg_quote( $tag, '/' ) . '>/is',
			'/<' . preg_quote( $tag, '/' ) . '[^>]*\/>/is',

			// HTML attributes
			'/<[^>]*\s' . preg_quote( $tag, '/' ) . '(?:=["\'][^"\']*["\'])?[^>]*>/is',

			// CSS classes
			'/<[^>]*class=["\'][^"\']*' . preg_quote( $tag, '/' ) . '[^"\']*["\'][^>]*>/is',
		);

		// For script tags with IDs, try to capture the entire element
		if ( str_contains( $tag, 'script' ) ) {
			$script_patterns = array(
				'/<script[^>]*id=(["\'])' . preg_quote( $tag, '/' ) . '\\1[^>]*>.*?<\/script>/is',
				'/<script[^>]*id=(["\'])' . preg_quote( $tag, '/' ) . '\\1[^>]*\/>/is',
			);
			array_unshift( $patterns, ...$script_patterns );
		}

		foreach ( $patterns as $pattern ) {
			if ( count( $examples ) >= $limit ) {
				break;
			}

			if ( preg_match_all( $pattern, $html, $matches ) ) {
				foreach ( $matches[0] as $match ) {
					if ( count( $examples ) >= $limit ) {
						break;
					}

					// Add the example without truncation
					$example = trim( $match );

					// Add to examples if unique
					if ( ! in_array( $example, $examples, true ) ) {
						$examples[] = $example;
					}
				}
			}
		}

		return $examples;
	}

	/**
	 * Extract critical CSS content
	 * @param string $html HTML content
	 * @return array Results for critical CSS
	 */
	private function extract_critical_css( $html ) {
		$result = array(
			'found'                => false,
			'count'                => 0,
			'examples'             => array(),
			'lastmod'              => '',
			'language'             => 'css',
			'file_exists'          => false,
			'file_missing_warning' => false,
		);

		// Check if critical.css file exists in theme directory
		$css_file_path         = get_stylesheet_directory() . '/critical.css';
		$result['file_exists'] = is_readable( $css_file_path );

		// Get all style elements with the fralenuvole plugin attribute
		$elements = $this->find_plugin_elements( $html, 'style' );

		// Filter for critical CSS style tags
		foreach ( $elements as $element ) {
			if ( preg_match( '/id=["\']frl-critical-css["\']/i', $element ) ) {
				$result['found'] = true;
				++$result['count'];
				// Store the element as a properly formatted HTML string
				$result['examples'][] = trim( $element );

				// Extract the data-lastmod attribute if present
				if ( preg_match( '/data-lastmod=["\']([^"\']+)["\']/i', $element, $matches ) ) {
					$result['lastmod'] = $matches[1];
				}

				break; // Usually there's only one critical CSS tag
			}
		}

		// Set warning flag if file exists but tag is missing
		if ( $result['found'] === false && $result['file_exists'] ) {
			$result['file_missing_warning'] = true;
		}

		return $result;
	}

	/**
	 * Extract schema script from HTML content
	 * @param string $html HTML content to search
	 * @return array Schema information including validation results
	 */
	private function extract_schema_script( $html ) {
		$result = array(
			'found'      => false,
			'count'      => 0,
			'examples'   => array(),
			'lastmod'    => '',
			'language'   => 'js',
			'validation' => array(
				'status'      => 'unknown',
				'messages'    => array(),
				'schema_type' => 'Unknown',
			),
		);

		// Get all script elements with the fralenuvole plugin attribute
		$elements = $this->find_plugin_elements( $html, 'script' );

		// Find schema script by ID
		foreach ( $elements as $element ) {
			if ( preg_match( '/id=["\']frl-schema["\']/i', $element ) ) {
				$result['found'] = true;
				$result['count'] = 1;

				// Extract the data-lastmod attribute if present
				if ( preg_match( '/data-lastmod=["\']([^"\']+)["\']/i', $element, $matches ) ) {
					$result['lastmod'] = $matches[1];
				}

				// Extract the script content
				if ( preg_match( '/<script[^>]*>(.*?)<\/script>/is', $element, $match ) ) {
					$script_content = isset( $match[1] ) ? trim( $match[1] ) : '';

					if ( empty( $script_content ) ) {
						$result['validation']['status']     = 'error';
						$result['validation']['messages'][] = 'Empty schema script content';
						return $result;
					}

					// Validate the schema
					$validation           = $this->validate_schema_json( $script_content );
					$result['validation'] = $validation;

					// Try to format the JSON for display inside the script tag
					try {
						$json_data = json_decode( $script_content );
						if ( $json_data && json_last_error() === JSON_ERROR_NONE ) {
							// Apply formatting for better readability
							$formatted_json = json_encode( $json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
							if ( $formatted_json !== false ) {
								// Replace the JSON content inside the element with the formatted version
								$element = preg_replace( '/>.*?<\/script>/is', ">\n" . $formatted_json . "\n</script>", $element );
							}
						}
					} catch ( Exception $e ) {
						// If formatting fails, use original content
					}

					// Include the full script tag in the examples
					$result['examples']     = array( trim( $element ) );
					$result['full_element'] = trim( $element );
					break; // Found the schema, no need to continue
				}
			}
		}

		return $result;
	}

	/**
	 * Validate schema JSON-LD content
	 * @param string $json_content The JSON content to validate
	 * @return array Validation results
	 */
	private function validate_schema_json( $json_content ) {
		$validation = array(
			'status'      => 'unknown',
			'messages'    => array(),
			'schema_type' => 'Unknown',
		);

		// First, check if it's valid JSON
		$data = json_decode( $json_content );
		if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
			$validation['status']     = 'error';
			$validation['messages'][] = 'Invalid JSON: ' . json_last_error_msg();
			return $validation;
		}

		// Convert to array for easier handling
		$data = json_decode( $json_content, true );

		// Check if data is empty or not an array
		if ( empty( $data ) || ! is_array( $data ) ) {
			$validation['status']     = 'error';
			$validation['messages'][] = 'Schema data is empty or invalid';
			return $validation;
		}

		// Check for required schema.org @context
		if ( ! isset( $data['@context'] ) ) {
			$validation['status']     = 'error';
			$validation['messages'][] = 'Missing required property: @context';
		} elseif ( ! str_contains( $data['@context'], 'schema.org' ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = '@context should contain schema.org URL';
		}

		// Handle schemas with @graph array (multiple schema objects)
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			$validation['status']     = 'valid'; // Start with valid status
			$validation['messages'][] = 'Found @graph with ' . count( $data['@graph'] ) . ' schema items';

			// Track types found in the graph for reporting
			$found_types = array();

			// Validate each item in the graph
			foreach ( $data['@graph'] as $index => $item ) {
				if ( ! isset( $item['@type'] ) ) {
					$validation['status']     = 'error';
					$validation['messages'][] = 'Missing @type in @graph item ' . ( $index + 1 );
					continue;
				}

				// Add the type to our found types
				$found_types[] = $item['@type'];

				// We could add deeper validation here for each item if needed
				// For now just do basic checks on required properties
				$this->validate_graph_item( $item, $validation, $index );
			}

			// Report the types found
			if ( ! empty( $found_types ) ) {
				$types_string              = implode( ', ', array_unique( $found_types ) );
				$validation['schema_type'] = 'Graph: ' . $types_string;
			}

			return $validation;
		}

		// Regular schema without @graph - validate as before
		if ( ! isset( $data['@type'] ) ) {
			$validation['status']     = 'error';
			$validation['messages'][] = 'Missing required property: @type';
			return $validation; // Stop further validation if @type is missing
		}

		// Get schema type and validate required properties based on type
		$type                      = $data['@type'];
		$validation['schema_type'] = $type;

		// Check for common schema types and their required properties
		switch ( $type ) {
			case 'WebSite':
				$this->validate_website_schema( $data, $validation );
				break;
			case 'WebPage':
			case 'AboutPage':
			case 'ContactPage':
			case 'CollectionPage':
			case 'ItemPage':
			case 'ProfilePage':
			case 'SearchResultsPage':
				$this->validate_webpage_schema( $data, $validation );
				break;
			case 'Organization':
			case 'LocalBusiness':
				$this->validate_organization_schema( $data, $validation );
				break;
			case 'Person':
				$this->validate_person_schema( $data, $validation );
				break;
			case 'Product':
				$this->validate_product_schema( $data, $validation );
				break;
			case 'BreadcrumbList':
				$this->validate_breadcrumb_schema( $data, $validation );
				break;
			case 'Article':
			case 'NewsArticle':
			case 'BlogPosting':
				$this->validate_article_schema( $data, $validation );
				break;
			case 'Service':
				$this->validate_service_schema( $data, $validation );
				break;
			case 'CreativeWork':
			case 'Portfolio':
				$this->validate_portfolio_schema( $data, $validation );
				break;
			default:
				// For unknown schema types, add a note but don't mark as error
				$validation['status']     = isset( $validation['status'] ) && $validation['status'] === 'error'
					? 'error' : 'warning';
				$validation['messages'][] = "Schema type '{$type}' recognized but no specialized validation available";
				break;
		}

		// If we haven't set a status based on validation, set it to valid
		if ( $validation['status'] === 'unknown' ) {
			$validation['status'] = 'valid';
		}

		return $validation;
	}

	/**
	 * Perform basic validation on a graph item
	 * @param array $item The graph item to validate
	 * @param array &$validation Validation results
	 * @param int $index Item index for error reporting
	 */
	private function validate_graph_item( $item, &$validation, $index ) {
		if ( ! isset( $item['@type'] ) ) {
			return; // Already checked in the parent method
		}

		$type      = $item['@type'];
		$item_desc = 'item ' . ( $index + 1 ) . " ({$type})";

		// Basic required properties for most schema types
		$common_props = array( 'name' );

		foreach ( $common_props as $prop ) {
			if ( ! isset( $item[ $prop ] ) || empty( $item[ $prop ] ) ) {
				if ( $validation['status'] !== 'error' ) {
					$validation['status'] = 'warning';
				}
				$validation['messages'][] = "Missing recommended property '{$prop}' in {$item_desc}";
			}
		}

		// Specific validations based on type can be added here
		// For example, check for URL in WebPage, WebSite
		if ( in_array( $type, array( 'WebSite', 'WebPage', 'Organization' ), true ) ) {
			if ( ! isset( $item['url'] ) || empty( $item['url'] ) ) {
				if ( $validation['status'] !== 'error' ) {
					$validation['status'] = 'warning';
				}
				$validation['messages'][] = "Missing URL property in {$item_desc}";
			}
		}

		// For breadcrumbs, check itemListElement
		if ( $type === 'BreadcrumbList' ) {
			if ( ! frl_is_array_not_empty( $item, 'itemListElement' ) ) {
				$validation['status']     = 'error';
				$validation['messages'][] = 'BreadcrumbList is missing itemListElement array';
			}
		}
	}

	/**
	 * Validate Website schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_website_schema( $data, &$validation ) {
		if ( ! isset( $data['name'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'WebSite schema should include name property';
		}

		if ( ! isset( $data['url'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'WebSite schema should include url property';
		}
	}

	/**
	 * Validate WebPage schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_webpage_schema( $data, &$validation ) {
		if ( ! isset( $data['name'] ) && ! isset( $data['headline'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'WebPage schema should include name or headline property';
		}

		if ( ! isset( $data['description'] ) ) {
			$validation['messages'][] = 'WebPage schema should include description for better SEO';
		}
	}

	/**
	 * Validate Organization schema data
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_organization_schema( $data, &$validation ) {
		// Required properties for Organization
		$required_props = array( 'name', 'url' );

		foreach ( $required_props as $prop ) {
			if ( ! isset( $data[ $prop ] ) || empty( $data[ $prop ] ) ) {
				$validation['status']     = 'error';
				$validation['messages'][] = "Missing required property for Organization: {$prop}";
			}
		}

		// Recommended properties for Organization
		$recommended_props = array( 'logo', 'address', 'contactPoint' );
		$has_recommended   = false;

		foreach ( $recommended_props as $prop ) {
			if ( isset( $data[ $prop ] ) && ! empty( $data[ $prop ] ) ) {
				$has_recommended = true;
			}
		}

		if ( ! $has_recommended ) {
			if ( $validation['status'] !== 'error' ) {
				$validation['status'] = 'warning';
			}
			$validation['messages'][] = 'Organization schema should include at least one of: ' . implode( ', ', $recommended_props );
		}

		// Check if address is properly formatted
		if ( isset( $data['address'] ) && is_array( $data['address'] ) ) {
			if ( ! isset( $data['address']['@type'] ) || $data['address']['@type'] !== 'PostalAddress' ) {
				if ( $validation['status'] !== 'error' ) {
					$validation['status'] = 'warning';
				}
				$validation['messages'][] = 'Organization address should have @type: PostalAddress';
			}
		}
	}

	/**
	 * Validate Person schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_person_schema( $data, &$validation ) {
		if ( ! isset( $data['name'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'Person schema should include name property';
		}
	}

	/**
	 * Validate Product schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_product_schema( $data, &$validation ) {
		if ( ! isset( $data['name'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'Product schema should include name property';
		}

		if ( ! isset( $data['offers'] ) ) {
			$validation['messages'][] = 'Product schema should include offers property';
		} else {
			// Check offers price
			if ( is_array( $data['offers'] ) ) {
				if ( ! isset( $data['offers']['price'] ) ) {
					$validation['messages'][] = 'Product offers should include price property';
				}
			}
		}
	}

	/**
	 * Validate BreadcrumbList schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_breadcrumb_schema( $data, &$validation ) {
		if ( ! isset( $data['itemListElement'] ) || ! is_array( $data['itemListElement'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'BreadcrumbList schema should include itemListElement array';
		} else {
			$items = $data['itemListElement'];
			if ( empty( $items ) ) {
				$validation['messages'][] = 'BreadcrumbList itemListElement should not be empty';
			}
		}
	}

	/**
	 * Validate Article schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_article_schema( $data, &$validation ) {
		if ( ! isset( $data['headline'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'Article schema should include headline property';
		}

		if ( ! isset( $data['datePublished'] ) ) {
			$validation['messages'][] = 'Article schema should include datePublished property';
		}

		if ( ! isset( $data['author'] ) ) {
			$validation['messages'][] = 'Article schema should include author property';
		}
	}

	/**
	 * Validate Service schema properties
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_service_schema( $data, &$validation ) {
		// Required properties
		if ( ! isset( $data['name'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'Service schema should include name property';
		}

		// Recommended properties
		if ( ! isset( $data['description'] ) ) {
			$validation['messages'][] = 'Service schema should include description property';
		}

		if ( ! isset( $data['provider'] ) ) {
			$validation['messages'][] = 'Service schema should include provider property';
		}

		if ( ! isset( $data['offers'] ) && ! isset( $data['areaServed'] ) ) {
			$validation['messages'][] = 'Service schema should include offers or areaServed property';
		}
	}

	/**
	 * Validate Portfolio schema properties (using ItemList or Collection)
	 * @param array $data Schema data
	 * @param array &$validation Validation results (passed by reference)
	 */
	private function validate_portfolio_schema( $data, &$validation ) {
		// For portfolio-type schemas (ItemList or custom Collection)
		if ( ! isset( $data['itemListElement'] ) && ! isset( $data['items'] ) ) {
			$validation['status']     = 'warning';
			$validation['messages'][] = 'Portfolio schema should include itemListElement or items property';
		} else {
			// Check if items exist and are properly formatted
			$items = isset( $data['itemListElement'] ) ? $data['itemListElement'] : $data['items'];

			if ( empty( $items ) ) {
				$validation['messages'][] = 'Portfolio should contain at least one item';
			} elseif ( ! is_array( $items ) ) {
				$validation['status']     = 'error';
				$validation['messages'][] = 'Portfolio items must be an array';
			}
		}

		// Recommended portfolio properties
		if ( ! isset( $data['name'] ) && ! isset( $data['headline'] ) ) {
			$validation['messages'][] = 'Portfolio schema should include name or headline property';
		}

		if ( ! isset( $data['description'] ) ) {
			$validation['messages'][] = 'Portfolio schema should include description property';
		}
	}

	/**
	 * Extract preloaded image information
	 * @param string $html HTML content
	 * @return array Results for preloaded images
	 */
	private function extract_preload_images( $html ) {
		$result = array(
			'found'    => false,
			'count'    => 0,
			'examples' => array(),
		);

		// Get all link elements with the fralenuvole plugin attribute
		$elements = $this->find_plugin_elements( $html, 'link' );

		// Filter for preload image link tags
		$preload_links = array();
		foreach ( $elements as $element ) {
			if ( preg_match( '/id=["\']frl-preload-img["\']/i', $element ) ) {
				// Store properly formatted HTML elements
				$preload_links[] = trim( $element );
			}
		}

		if ( ! empty( $preload_links ) ) {
			$result['found']    = true;
			$result['count']    = count( $preload_links );
			$result['examples'] = array_slice( $preload_links, 0, 3 );
		}

		return $result;
	}

	/**
	 * Render validation results
	 * @param array $results Validation results
	 * @param string $url URL that was validated
	 * @param string $original_url Optional original URL if different from validated
	 * @return string Results HTML
	 */
	protected function _render_results_implementation( $results, $url, $original_url = '' ) {
		// Handle error case
		if ( isset( $results['error'] ) ) {
			return $this->render_validation_error( $results );
		}

		$content = $this->render_validation_header( $results );

		// Initialize table content (without header - it's added in render_priority_tags)
		$table_content = '';

		// Generate unique ID base for toggle functionality
		$toggle_id_base = 'tag-example-' . md5( $url . microtime( true ) );
		$toggle_count   = 0;

		// Order and process priority tags
		$table_content .= $this->render_priority_tags( $results, $url, $toggle_id_base, $toggle_count );

		// Wrap in table and add to content
		$content .= frl_ui_render_table(
			'tag-validator-priority-tags',
			$table_content,
			'tag-validator-priority-tags',
			0,
			true
		);

		// Return the widget
		return $content;
	}

	/**
	 * Renders tag validation results in a formatted widget
	 * Public API method for rendering validation results
	 *
	 * @param array $results Validation results
	 * @param string $url URL that was validated
	 * @return string HTML content
	 */
	public function render_tag_validation_results( $results, $url ) {
		// Call the implementation method
		return $this->_render_results_implementation( $results, $url );
	}

	/**
	 * Render validation header with URL and warnings
	 * @param array $results Validation results
	 * @return string Header HTML
	 */
	public function render_validation_header( $results ) {
		$content = '';

		// Display warning if present
		if ( isset( $results['warning'] ) ) {
			$content .= frl_ui_render_validation_message( $results['warning'], 'warning' );
		}

		// Create a simple table for metadata
		$metadata_rows = frl_ui_render_metadata_row(
			'Validated:',
			'<code>' . esc_html( $results['checked_url'] ) . '</code>',
			'',
			false,
			'validation-metadata'
		);

		$metadata_table = frl_ui_render_table(
			'validation-metadata',
			$metadata_rows,
			'validation-metadata-table',
			HOUR_IN_SECONDS,
			true
		);

		$content .= '<div class="validation-summary">' . $metadata_table . '</div>';

		return $content;
	}

	/**
	 * Render validation error message
	 * @param array $results Validation results containing error
	 * @return string Error HTML widget
	 */
	public function render_validation_error( $results ) {
		$error_output = frl_ui_render_validation_message( $results['error'], 'error' );

		if ( isset( $results['checked_url'] ) ) {
			// Create a simple table for metadata
			$metadata_rows = frl_ui_render_metadata_row(
				'Attempted URL',
				'<code>' . esc_html( $results['checked_url'] ) . '</code>',
				'',
				false,
				'validation-metadata'
			);

			$metadata_table = frl_ui_render_table(
				'validation-summary',
				$metadata_rows,
				'validation-metadata-table'
			);
			$error_output  .= '<div class="validation-summary">' . $metadata_table . '</div>';
		}

		return frl_ui_render_widget(
			'tag-validator-error',
			$error_output,
			'Validation Error',
			'tag-validator-error'
		);
	}

	/**
	 * Render priority tags section
	 * @param array $results Validation results
	 * @param string $url URL that was validated
	 * @param string $toggle_id_base Base ID for toggle functionality
	 * @param int &$toggle_count Counter for toggle IDs (passed by reference)
	 * @return string Priority tags HTML
	 */
	public function render_priority_tags( $results, $url, $toggle_id_base, &$toggle_count ) {
		$table_content = '';

		// Define the order of priority tags
		$priority_tags_order = array( '#frl-schema', '#frl-preload-img', '#frl-critical-css', 'print-media-scripts' );

		// Extract priority results in correct order
		$priority_results = array();
		foreach ( $priority_tags_order as $priority_tag ) {
			if ( isset( $results['tags'][ $priority_tag ] ) ) {
				$priority_results[ $priority_tag ] = $results['tags'][ $priority_tag ];
			}
		}

		// Add section header row
		$table_content .= frl_ui_render_table_row( 'Tag', 'Status', true );

		// Render each priority tag
		foreach ( $priority_results as $tag => $data ) {
			++$toggle_count;
			// Make toggle IDs more unique by adding microseconds and a random number
			$toggle_id    = $toggle_id_base . '-' . $toggle_count . '-' . uniqid();
			$has_examples = $data['found'] && ! empty( $data['examples'] );

			// Determine tag type (schema, css, etc.)
			$current_tag_type = $this->get_tag_type( $tag );

			// Generate status HTML
			$status_html = $this->generate_tag_status_html( $tag, $data, $toggle_id, $has_examples );

			// Use display name if available, otherwise use tag
			$display_tag = isset( $data['display_name'] ) ? $data['display_name'] : $tag;

			// Add the row with tag name and status
			$table_content .= frl_ui_render_table_row(
				$display_tag,
				$status_html,
				false,
				str_replace( '#', '', $tag )
			);

			// Add examples if available
			if ( $has_examples ) {
				$table_content .= $this->render_tag_examples( $tag, $data, $toggle_id );
			}
		}

		return $table_content;
	}

	/**
	 * Helper method to determine tag type for grouping
	 *
	 * @param string $tag The tag identifier
	 * @return string The general type of the tag
	 */
	private function get_tag_type( $tag ) {
		if ( $tag === '#frl-schema' ) {
			return 'schema';
		} elseif ( $tag === '#frl-critical-css' || $tag === 'print-media-scripts' ) {
			return 'css';
		} elseif ( $tag === '#frl-preload-img' ) {
			return 'image';
		} else {
			return 'other';
		}
	}

	/**
	 * Generate HTML for tag status including status dots and metadata
	 * @param string $tag Tag identifier
	 * @param array $data Tag data
	 * @param string $toggle_id Toggle ID for examples
	 * @param bool $has_examples Whether this tag has examples
	 * @return string Status HTML
	 */
	public function generate_tag_status_html( $tag, $data, $toggle_id, $has_examples ) {
		// Create status dot for found/not found
		$status_html = '<span class="status-dot-container">';

		// Prepare the status text and dot status
		$found_text = 'Found';
		$dot_status = 'enabled';

		if ( $data['found'] ) {
			// Add count for print-media-scripts
			if ( $tag === 'print-media-scripts' && isset( $data['count'] ) ) {
				$found_text .= ' (' . $data['count'] . ')';
			}
		} else {
			// Handle specific tags when not found
			if ( $tag === 'print-media-scripts' ) {
				// Deferred CSS: "Not configured" with warning dot
				$found_text = 'Not configured';
				$dot_status = 'warning';
			} elseif ( $tag === '#frl-critical-css' ) {
				// Critical CSS: distinguish between file present/missing
				if ( ! empty( $data['file_exists'] ) && ! empty( $data['file_missing_warning'] ) ) {
					// File exists but tag missing - error status
					$found_text = 'Tag missing';
					$dot_status = 'disabled';
				} else {
					// File not found - warning status
					$found_text = 'Critical CSS not found';
					$dot_status = 'warning';
				}
			} else {
				$found_text = 'Not found';
			}
		}
		$status_html .= frl_ui_render_status_dot( $dot_status, $found_text, true );

		// Tag-specific status information
		if ( $data['found'] ) {
			// For schema validation
			if ( $tag === '#frl-schema' && isset( $data['validation'] ) ) {
				// Add schema validation status indicators

				// Add schema type status with cleaner implementation
				$type_status = ! empty( $data['validation']['schema_type'] ) ? 'enabled' : 'disabled';
				$type_text   = ! empty( $data['validation']['schema_type'] )
					? '<b>' . esc_html( $data['validation']['schema_type'] ) . '</b>'
					: ' missing';

				$status_html .= ' ' . frl_ui_render_status_dot( $type_status, $type_text, true );

				// Add schema validation status with cleaner implementation
				$validation_status = 'disabled';
				$validation_text   = 'Schema';

				switch ( $data['validation']['status'] ?? '' ) {
					case 'valid':
						$validation_status = 'enabled';
						$validation_text  .= ' valid';
						break;
					case 'warning':
						$validation_status = 'warning';
						$validation_text  .= ' warning';
						break;
					case 'error':
						$validation_status = 'disabled';
						$validation_text  .= ' error';
						break;
				}

				$status_html .= ' ' . frl_ui_render_status_dot( $validation_status, $validation_text, true );
			}

			// Add last mod date for schema and critical CSS
			if ( in_array( $tag, array( '#frl-schema', '#frl-critical-css' ), true ) && ! empty( $data['lastmod'] ) ) {
				// Use the renderer method with exact signature match
				$status_html .= frl_ui_render_metadata_field( 'Last mod', $data['lastmod'], 'metadata-field' );
			}

			// Show script IDs for print-media-scripts
			if ( $tag === 'print-media-scripts' && ! empty( $data['script_ids'] ) ) {
				// Use the renderer method with exact signature match
				$status_html .= frl_ui_render_items_list( $data['script_ids'], '', 'items-list' );
			}
		}

		$status_html .= '</span>';

		// Add toggle button if we have examples
		if ( $has_examples && ! empty( $toggle_id ) ) {
			$status_html .= ' ' . frl_ui_render_toggle_button( 'Show Code', $toggle_id, 'button-small' );
		}

		return $status_html;
	}

	/**
	 * Render tag examples with validation messages and code highlighting
	 * @param string $tag Tag identifier
	 * @param array $data Tag data
	 * @param string $toggle_id Toggle ID for this example
	 * @return string Examples HTML
	 */
	public function render_tag_examples( $tag, $data, $toggle_id ) {
		// Check if we have anything to display
		if ( empty( $data['examples'] ) && ( ! isset( $data['validation'] ) || empty( $data['validation'] ) ) ) {
			return '';
		}

		// Extract language from the data (defaults to markup)
		$language = isset( $data['language'] ) ? $data['language'] : 'markup';

		// Create output content
		$output = '';

		// Render validation messages separately from code examples
		if ( isset( $data['validation'] ) && ! empty( $data['validation']['messages'] ) ) {
			// Add validation messages without hiding them
			$output .= frl_ui_render_validation_messages(
				$data['validation'],
				'', // No ID needed
				false, // Not initially hidden
				true  // In table row
			);
		}

		// Add code examples if available
		if ( ! empty( $data['examples'] ) ) {
			$output .= frl_ui_render_code_block(
				$data['examples'],
				$language,
				$toggle_id,
				true,   // Initially hidden
				true    // In table row
			);
		}

		return $output;
	}

	/**
	 * Render the tag validator form and results
	 *
	 * This method handles both rendering the form and displaying validation results.
	 * AJAX functionality is provided by the external tag-validator.js file.
	 *
	 * @return string HTML content
	 */
	public function render() {
		// Check if we have validator parameters in either POST or GET
		$post_url = isset( $_POST['frl_tag_validator_url'] ) ? $_POST['frl_tag_validator_url'] : '';
		$get_url  = isset( $_GET['frl_tag_validator_url'] ) ? $_GET['frl_tag_validator_url'] : '';

		// Determine the URL to validate - prioritize POST since that will be our form submission.
		// No default fallback to home_url('/'): skip expensive cURL on passive dashboard loads.
		$url_to_validate = ! empty( $post_url ) ? $post_url : ( ! empty( $get_url ) ? $get_url : '' );

		// Fixed set of predefined tags to check
		$tags_to_check = '#frl-critical-css,#frl-preload-img,#frl-schema';

		// Start building the output
		$output = '';

		// 1. Add the form inputs using UI renderer
		$form_content = $this->get_form_inputs_html( $url_to_validate );
		$output      .= frl_ui_render_widget(
			'tag-validator-form',
			$form_content,
			'Website Tags',
			'tag-validator-form',
			0,
			true
		);

		$output .= '<div id="tag-validator-results-container">';

		// 2. Run validation only when user explicitly provided a URL.
		// Passive dashboard loads skip the expensive cURL request entirely.
		if ( ! empty( $url_to_validate ) ) {
			$tag_validator_cache_key = 'tag_validator_' . md5( $url_to_validate . '|' . $tags_to_check );
			$validation_results      = frl_cache_remember(
				'adminui',
				$tag_validator_cache_key,
				function () use ( $url_to_validate, $tags_to_check ) {
					return $this->validate_url( $url_to_validate, $tags_to_check );
				},
				5 * MINUTE_IN_SECONDS
			);
			$output                 .= $this->render_tag_validation_results( $validation_results, $url_to_validate );
		} else {
			$output .= '<p>Enter a URL and click <strong>Validate Tags</strong> to check for the presence of predefined HTML tags.</p>';
		}

		$output .= '</div>'; // End results container

		return $output;
	}
}
