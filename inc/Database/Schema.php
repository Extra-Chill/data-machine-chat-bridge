<?php
/**
 * Database Schema
 *
 * Creates bridge tables on plugin activation:
 * - bridge_messages: outbound message queue for webhook/poll delivery
 * - bridge_auth_codes: PKCE authorization codes for OAuth login flow
 *
 * @package DataMachineChatBridge\Database
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Database;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Schema {

	/**
	 * Create all bridge tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$messages_table = $wpdb->prefix . 'datamachine_bridge_messages';
		$auth_codes_table = $wpdb->prefix . 'datamachine_bridge_auth_codes';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Outbound message queue.
		dbDelta( "CREATE TABLE {$messages_table} (
			queue_id VARCHAR(50) NOT NULL,
			agent_id BIGINT UNSIGNED NOT NULL,
			session_id VARCHAR(50) NOT NULL,
			content LONGTEXT NOT NULL,
			status ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			delivered_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (queue_id),
			KEY idx_agent_status (agent_id, status),
			KEY idx_created (created_at)
		) {$charset_collate};" );

		// PKCE authorization codes for OAuth login flow.
		dbDelta( "CREATE TABLE {$auth_codes_table} (
			code VARCHAR(64) NOT NULL,
			agent_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			code_challenge VARCHAR(64) NOT NULL,
			redirect_uri VARCHAR(500) NOT NULL,
			label VARCHAR(100) NOT NULL DEFAULT '',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (code),
			KEY idx_expires (expires_at)
		) {$charset_collate};" );
	}
}
