<?php
/**
 * Audit log for destructive command executions.
 *
 * Every destructive command attempt is logged — who, which command, which
 * targets, when, and the outcome — regardless of whether it succeeded, so
 * there is a trail independent of WordPress's own logging. Entries are
 * written with status "pending" *before* execution starts and updated
 * afterwards, so even a fatal mid-run leaves a record.
 *
 * Also provides the per-user rate limiter for destructive REST endpoints.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_Audit_Log {

	const TABLE = 'awps_audit_log';

	/** Max destructive executions per user per window. */
	const RATE_LIMIT       = 5;
	const RATE_WINDOW_SECS = 60;

	/**
	 * Full table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create (or upgrade) the audit table.
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			command_id VARCHAR(191) NOT NULL,
			targets TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY command_id (command_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Write a new entry (typically with status "pending" before execution).
	 *
	 * @param string $command_id Command id.
	 * @param mixed  $targets    Target description (args array, ids, labels…).
	 * @param string $status     pending|success|failed|forbidden|rate_limited.
	 * @param string $message    Optional human-readable detail.
	 * @return int Entry id (0 on failure).
	 */
	public static function log( $command_id, $targets = null, $status = 'pending', $message = '' ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'    => get_current_user_id(),
				'command_id' => sanitize_text_field( $command_id ),
				'targets'    => null === $targets ? null : wp_json_encode( $targets ),
				'status'     => sanitize_key( $status ),
				'message'    => sanitize_text_field( $message ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update the outcome of an existing entry.
	 *
	 * @param int    $entry_id Entry id from log().
	 * @param string $status   success|failed.
	 * @param string $message  Outcome detail.
	 */
	public static function resolve( $entry_id, $status, $message = '' ) {
		global $wpdb;

		if ( ! $entry_id ) {
			return;
		}

		$wpdb->update(
			self::table(),
			array(
				'status'  => sanitize_key( $status ),
				'message' => sanitize_text_field( $message ),
			),
			array( 'id' => (int) $entry_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rate limiter: has the current user exceeded the destructive-execution
	 * budget for the current window?
	 *
	 * Counts only real execution attempts (pending / success / failed).
	 * Forbidden and rate_limited rows are still logged for the audit trail
	 * but do not extend the lockout window on every retry.
	 *
	 * @return bool True when the user is over the limit.
	 */
	public static function is_rate_limited() {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - self::RATE_WINDOW_SECS );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND created_at >= %s AND status IN ('pending','success','failed')",
				get_current_user_id(),
				$since
			)
		);

		return $count >= self::RATE_LIMIT;
	}
}
