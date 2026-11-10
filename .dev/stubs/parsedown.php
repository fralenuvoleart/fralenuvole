<?php
/**
 * Minimal ParsedownExtra stub so the docs renderer type-checks without the vendored lib.
 */
if ( ! class_exists( 'ParsedownExtra' ) ) {
	class ParsedownExtra {
		/**
		 * @param bool $enable Safe mode flag.
		 * @return void
		 */
		public function setSafeMode( $enable ) {}

		/**
		 * @param string $text Markdown source.
		 * @return string HTML.
		 */
		public function text( $text ) { return (string) $text; }
	}
}
