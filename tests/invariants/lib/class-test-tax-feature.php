<?php
/**
 * Taxonomy feature test double: enabled without options, seedable slugs.
 *
 * @package Fralenuvole
 */

/**
 * Taxonomy base removal test double.
 */
class Frl_Test_Tax_Feature extends Frl_Taxonomy_Base_Removal_Feature {

	/**
	 * Enabled without loading options.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * Seed the configured taxonomies.
	 *
	 * @param array $slugs Taxonomy slugs.
	 * @return void
	 */
	public function seed_slugs( array $slugs ): void {
		$property = new ReflectionProperty( Frl_Taxonomy_Base_Removal_Feature::class, 'taxonomy_slugs' );
		$property->setAccessible( true );
		$property->setValue( $this, $slugs );
	}

	/**
	 * Expose the protected exclusion list.
	 *
	 * @return array
	 */
	public function exclusions(): array {
		return $this->get_catch_all_exclusions();
	}
}
