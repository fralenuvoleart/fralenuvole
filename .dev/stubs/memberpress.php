<?php
/**
 * PHPStan stubs for MemberPress plugin classes
 *
 * These stubs provide type definitions for MemberPress classes
 * that are not included in wordpress-stubs.
 */

if (!class_exists('MeprTransaction')) {
    /**
     * @property int $user_id
     * @property int $product_id
     * @property string $status
     * @property static $complete_str
     */
    class MeprTransaction {
        /** @param int|null $id */
        public function __construct($id = null) {}

        public function store(): void {}

        /** @return int */
        public function get_user_id() {
            return 0;
        }

        /** @return int */
        public function get_product_id() {
            return 0;
        }

        /** @return string */
        public function get_status() {
            return '';
        }
    }
}

if (!class_exists('MeprProduct')) {
    class MeprProduct {
        /** @param int|null $id */
        public function __construct($id = null) {}
    }
}

if (!class_exists('MeprUser')) {
    class MeprUser {
        /** @param int|null $id */
        public function __construct($id = null) {}
    }
}
