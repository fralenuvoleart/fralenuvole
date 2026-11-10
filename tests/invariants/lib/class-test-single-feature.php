<?php
/**
 * Single-CPT feature test double: enabled without options, seedable mappings.
 *
 * @package Fralenuvole
 */

/**
 * Single CPT base translation test double.
 */
class Frl_Test_Single_Feature extends Frl_CPT_Single_Base_Translation_Feature {

	/**
	 * Enabled without loading options.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * Seed the language => base mappings.
	 *
	 * @param array $mappings Mappings.
	 * @return void
	 */
	public function seed_mappings( array $mappings ): void {
		$property = new ReflectionProperty( Frl_CPT_Single_Base_Translation_Feature::class, 'mappings' );
		$property->setAccessible( true );
		$property->setValue( $this, $mappings );
	}
}
