<?php
/**
 * Database Schema
 *
 * Creates the bridge_messages table on plugin activation.
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
	 * Create the bridge messages table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'datamachine_bridge_messages';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
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
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
