<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole Tab Renderer
 *
 * Handles HTML rendering of tab navigation and containers.
 * This is an internal helper class used by Frl_Tab_Manager.
 *
 * @internal Not intended for direct use - use Frl_Tab_Manager facade
 */
class Frl_Tab_Renderer {

	/**
	 * Generate the HTML for the tab navigation menu.
	 *
	 * @param array[] $all_tabs Sorted list of tabs with shape {id: string, type: string, config: array, position: int}.
	 * @return string The generated HTML for the navigation.
	 */
	public function generate_navigation( array $all_tabs ) {
		static $tab_nav_html = null;

		if ( $tab_nav_html !== null ) {
			return $tab_nav_html;
		}

		ob_start();

		foreach ( $all_tabs as $tab ) {
			$tab_id = $tab['id'];
			$config = $tab['config'];

			$is_restricted = apply_filters( 'frl_is_section_restricted', false, $tab_id );

			if ( $is_restricted ) {
				continue;
			}

			echo '<li class="tab-' . esc_attr( $tab_id ) . '"><a href="#tabs-' . esc_attr( $tab_id ) . '">' . esc_html( $config['title'] ) . '</a></li>';
		}

		$tab_nav_html = ob_get_clean();
		return $tab_nav_html;
	}

	/**
	* Render all registered custom tabs in sorted order.
	*
	* @param array $custom_tabs Array of custom tab configurations.
	* @return void
	*/
	public function render_all_custom( array $custom_tabs ) {
		uasort(
			$custom_tabs,
			function ( $a, $b ) {
				$a_pos = isset( $a['position'] ) ? (int) $a['position'] : 0;
				$b_pos = isset( $b['position'] ) ? (int) $b['position'] : 0;
				return $a_pos - $b_pos;
			}
		);

		foreach ( $custom_tabs as $tab_id => $config ) {
			$is_restricted = apply_filters( 'frl_is_section_restricted', false, $tab_id );
			if ( $is_restricted ) {
				continue;
			}

			$action_hook = FRL_PREFIX . '_' . $tab_id . '_content';
			$this->render_custom_tab( $tab_id, $action_hook );
		}
	}

	/**
	* Render the HTML container for a specific custom tab and trigger its content hook.
	*
	* @param string $tab_id The tab identifier.
	* @param string $action_hook The action hook to trigger for tab content.
	* @return void
	*/
	public function render_custom_tab( $tab_id, $action_hook ) {
		?>
		<div id="tabs-<?php echo esc_attr( $tab_id ); ?>" class="frl-section custom-tab-container">
			<?php
			echo apply_filters( FRL_PREFIX . '_before_' . $tab_id . '_content', '' );
			do_action( $action_hook );
			echo apply_filters( FRL_PREFIX . '_after_' . $tab_id . '_content', '' );
			?>
		</div>
		<?php
	}

	/**
	* Render the opening HTML for the tab container.
	*
	* @param bool $vertical Whether the layout is vertical.
	* @param string $additional_class Extra CSS classes for the container.
	* @param int|null $active_tab The active tab index.
	* @return void
	*/
	public function render_container_start( $vertical = true, $additional_class = '', $active_tab = null ) {
		$tab_class = $vertical ? 'frl-tabs vertical-tabs' : 'frl-tabs';
		if ( ! empty( $additional_class ) ) {
			$tab_class .= ' ' . $additional_class;
		}

		echo '<div id="tabs" class="wrap frl-wrap ' . esc_attr( $tab_class ) . '" data-active-tab="' . esc_attr( (string) $active_tab ) . '">';
	}

	/**
	* Render the closing HTML for the tab container.
	*
	* @return void
	*/
	public function render_container_end() {
		echo '</div>';
	}

	/**
	* Register tabs based on sections and render the navigation menu.
	*
	* @param array $sections Array of section configurations.
	* @param int $position_start Starting position for the tabs.
	* @param callable $register_callback Callback to register each tab.
	* @param callable $get_navigation Callback to retrieve the navigation HTML.
	* @return void
	*/
	public function render_tabs_from_sections( $sections, $position_start, $register_callback, $get_navigation ) {
		// Register tabs via callback
		$position      = $position_start;
		$section_names = frl_get_default_fields_sections();

		foreach ( $sections as $key => $value ) {
			$section_id = is_int( $key ) ? $value : $key;

			if ( ! isset( $section_names[ $section_id ] ) ) {
				continue;
			}

			$title = $section_names[ $section_id ];

			$register_callback(
				$section_id,
				array(
					'title'    => $title,
					'position' => $position,
				),
				'form'
			);

			$position += 10;
		}

		// Generate navigation
		echo '<ul id="frl-tabs-nav">';
		echo $get_navigation();
		echo '</ul>';
	}

	/**
	* Render field groups for a specific tab using a provided field rendering callback.
	*
	* @param array $field_groups List of field group configurations.
	* @param callable $field_callback Callback function to render each individual field.
	* @param array $args Additional arguments passed to the field callback.
	* @return void
	*/
	public function render_field_groups( $field_groups, $field_callback, $args = array() ) {
		if ( ! $field_callback || empty( $field_groups ) ) {
			return;
		}

		foreach ( $field_groups as $group_id => $group ) {
			if ( ! empty( $group['title'] ) ) {
				echo '<div class="frl-field-group" id="field-group-' . esc_attr( $group_id ) . '">';
				echo '<h3>' . esc_html( $group['title'] ) . '</h3>';

				if ( ! empty( $group['description'] ) ) {
					echo '<p class="description">' . esc_html( $group['description'] ) . '</p>';
				}

				echo '<table class="form-table">';
			}

			foreach ( $group['fields'] as $field ) {
				echo '<tr valign="top">';
				echo '<th scope="row">' . esc_html( $field['label'] ?? '' ) . '</th>';
				echo '<td>';
				call_user_func( $field_callback, array_merge( $field, $args ) );
				echo '</td>';
				echo '</tr>';
			}

			if ( ! empty( $group['title'] ) ) {
				echo '</table>';
				echo '</div>';
			}
		}
	}
}
