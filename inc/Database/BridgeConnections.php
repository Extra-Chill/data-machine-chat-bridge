<?php
/**
 * Bridge Connections Database
 *
 * Manages bridge registrations and the outbound message queue.
 * Bridges register themselves with a callback URL; agent responses
 * are enqueued for delivery and can be retrieved via polling or webhook.
 *
 * V1: simple option-based storage for registrations, custom table for messages.
 *
 * @package DataMachineChatBridge\Database
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Database;

use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BridgeConnections {

	/**
	 * Option key for bridge registrations.
	 */
	private const REGISTRATION_OPTION = 'datamachine_bridge_registrations';

	/**
	 * Register or update a bridge connection for an agent.
	 *
	 * @param int    $agent_id     Agent ID.
	 * @param string $callback_url Webhook callback URL.
	 * @param string $bridge_id    Optional bridge instance identifier.
	 * @return string|WP_Error Registration ID on success.
	 */
	public function register_bridge( int $agent_id, string $callback_url, string $bridge_id = '' ): string|WP_Error {
		if ( $agent_id <= 0 ) {
			return new WP_Error( 'invalid_agent', 'Invalid agent ID.' );
		}

		if ( empty( $callback_url ) || ! wp_http_validate_url( $callback_url ) ) {
			return new WP_Error( 'invalid_url', 'Invalid callback URL.' );
		}

		$registrations   = $this->get_all_registrations();
		$registration_id = wp_generate_uuid4();

		// Update existing registration for this agent+bridge_id combo.
		foreach ( $registrations as $key => $reg ) {
			if ( (int) $reg['agent_id'] === $agent_id && $reg['bridge_id'] === $bridge_id ) {
				$registration_id            = $reg['registration_id'];
				$registrations[ $key ]['callback_url'] = $callback_url;
				$registrations[ $key ]['last_seen']    = current_time( 'mysql', true );
				$this->save_registrations( $registrations );
				return $registration_id;
			}
		}

		$registrations[] = array(
			'registration_id' => $registration_id,
			'agent_id'        => $agent_id,
			'callback_url'    => $callback_url,
			'bridge_id'       => $bridge_id,
			'registered_at'   => current_time( 'mysql', true ),
			'last_seen'       => current_time( 'mysql', true ),
		);

		$this->save_registrations( $registrations );

		return $registration_id;
	}

	/**
	 * Get all registered bridges for a specific agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array List of bridge registrations.
	 */
	public function get_bridges_for_agent( int $agent_id ): array {
		$registrations = $this->get_all_registrations();

		return array_values( array_filter( $registrations, function ( $reg ) use ( $agent_id ) {
			return (int) $reg['agent_id'] === $agent_id;
		} ) );
	}

	/**
	 * Enqueue a message for bridge delivery.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $message  Message payload.
	 * @return string|WP_Error Queue ID on success.
	 */
	public function enqueue_message( int $agent_id, array $message ): string|WP_Error {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';
		$queue_id   = wp_generate_uuid4();

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'queue_id'   => $queue_id,
				'agent_id'   => $agent_id,
				'session_id' => $message['session_id'] ?? '',
				'content'    => wp_json_encode( $message ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'enqueue_failed', 'Failed to enqueue message.' );
		}

		return $queue_id;
	}

	/**
	 * Get pending (undelivered) messages for an agent.
	 *
	 * @param int $agent_id Agent ID.
	 * @param int $limit    Maximum messages to return.
	 * @return array List of pending messages.
	 */
	public function get_pending_messages( int $agent_id, int $limit = 50 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT queue_id, session_id, content, created_at FROM %i WHERE agent_id = %d AND status = 'pending' ORDER BY created_at ASC LIMIT %d",
				$table_name,
				$agent_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map( function ( $row ) {
			$message       = json_decode( $row['content'], true ) ?? array();
			$message['queue_id']   = $row['queue_id'];
			$message['created_at'] = $row['created_at'];
			return $message;
		}, $rows );
	}

	/**
	 * Acknowledge messages as delivered.
	 *
	 * @param array $queue_ids Queue IDs to mark as delivered.
	 * @return int Number of messages acknowledged.
	 */
	public function acknowledge_messages( array $queue_ids ): int {
		global $wpdb;

		if ( empty( $queue_ids ) ) {
			return 0;
		}

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';
		$placeholders = implode( ',', array_fill( 0, count( $queue_ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'delivered', delivered_at = %s WHERE queue_id IN ({$placeholders}) AND status = 'pending'",
				array_merge( array( $table_name, current_time( 'mysql', true ) ), $queue_ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $updated;
	}

	/**
	 * Get all registrations from options.
	 */
	private function get_all_registrations(): array {
		$registrations = get_option( self::REGISTRATION_OPTION, array() );
		return is_array( $registrations ) ? $registrations : array();
	}

	/**
	 * Save registrations to options.
	 */
	private function save_registrations( array $registrations ): void {
		update_option( self::REGISTRATION_OPTION, $registrations, false );
	}
}
