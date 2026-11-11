<?php
/**
 * Minimal WP-CLI stub, so the command can be tested without WordPress.
 *
 * @package HM\EP_Verify_Index
 */

// phpcs:disable

/**
 * Records log lines and turns errors into exceptions.
 */
class WP_CLI {
	public static array $logs = [];

	public static function error( $message ) {
		throw new RuntimeException( $message );
	}

	public static function log( $message ) {
		self::$logs[] = $message;
	}

	public static function warning( $message ) {
		self::$logs[] = 'Warning: ' . $message;
	}

	public static function success( $message ) {
		self::$logs[] = 'Success: ' . $message;
	}
}
