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
	 * @param int      $agent_id     Agent ID.
	 * @param int|null $token_id     Token ID for login-level scoping.
	 * @param string   $callback_url Webhook callback URL.
	 * @param string   $bridge_id    Optional bridge instance identifier.
	 * @return string|WP_Error Registration ID on success.
	 */
	public function register_bridge( int $agent_id, ?int $token_id, string $callback_url, string $bridge_id = '' ): string|WP_Error {
		if ( $agent_id <= 0 ) {
			return new WP_Error( 'invalid_agent', 'Invalid agent ID.' );
		}

		if ( empty( $callback_url ) || ! wp_http_validate_url( $callback_url ) ) {
			return new WP_Error( 'invalid_url', 'Invalid callback URL.' );
		}

		$registrations   = $this->get_all_registrations();
		$registration_id = wp_generate_uuid4();

		// Update existing registration for this agent+token+bridge_id combo.
		foreach ( $registrations as $key => $reg ) {
			if ( (int) $reg['agent_id'] === $agent_id && (int) ( $reg['token_id'] ?? 0 ) === (int) $token_id && $reg['bridge_id'] === $bridge_id ) {
				$registration_id            = $reg['registration_id'];
				$registrations[ $key ]['callback_url'] = $callback_url;
				$registrations[ $key ]['token_id']     = $token_id;
				$registrations[ $key ]['last_seen']    = current_time( 'mysql', true );
				$this->save_registrations( $registrations );
				return $registration_id;
			}
		}

		$registrations[] = array(
			'registration_id' => $registration_id,
			'agent_id'        => $agent_id,
			'token_id'        => $token_id,
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
	 * @param int      $agent_id Agent ID.
	 * @param int|null $token_id Optional token ID.
	 * @return array List of bridge registrations.
	 */
	public function get_bridges_for_agent( int $agent_id, ?int $token_id = null ): array {
		$registrations = $this->get_all_registrations();

		return array_values( array_filter( $registrations, function ( $reg ) use ( $agent_id, $token_id ) {
			if ( (int) $reg['agent_id'] !== $agent_id ) {
				return false;
			}

			if ( null === $token_id ) {
				return true;
			}

			return (int) ( $reg['token_id'] ?? 0 ) === (int) $token_id;
		} ) );
	}

	/**
	 * Get all registered bridges for a specific token.
	 *
	 * Falls back to agent-wide registrations with no token_id for backwards
	 * compatibility, but prefers exact token matches when available.
	 *
	 * @param int      $agent_id Agent ID.
	 * @param int|null $token_id Token ID.
	 * @return array List of bridge registrations.
	 */
	public function get_bridges_for_token( int $agent_id, ?int $token_id ): array {
		$registrations = $this->get_all_registrations();

		$exact = array_values( array_filter( $registrations, function ( $reg ) use ( $agent_id, $token_id ) {
			return (int) $reg['agent_id'] === $agent_id && (int) ( $reg['token_id'] ?? 0 ) === (int) $token_id;
		} ) );

		if ( ! empty( $exact ) ) {
			return $exact;
		}

		return array_values( array_filter( $registrations, function ( $reg ) use ( $agent_id ) {
			return (int) $reg['agent_id'] === $agent_id && empty( $reg['token_id'] );
		} ) );
	}

	/**
	 * Enqueue a message for bridge delivery.
	 *
	 * @param int      $agent_id Agent ID.
	 * @param int|null $token_id Token ID for login-level routing.
	 * @param array    $message  Message payload.
	 * @return string|WP_Error Queue ID on success.
	 */
	public function enqueue_message( int $agent_id, ?int $token_id, array $message ): string|WP_Error {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';
		$queue_id   = wp_generate_uuid4();

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'queue_id'   => $queue_id,
				'agent_id'   => $agent_id,
				'token_id'   => $token_id,
				'session_id' => $message['session_id'] ?? '',
				'content'    => wp_json_encode( $message ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'enqueue_failed', 'Failed to enqueue message.' );
		}

		return $queue_id;
	}

	/**
	 * Get pending (undelivered) messages for an agent.
	 *
	 * @param int        $agent_id    Agent ID.
	 * @param int|null   $token_id    Optional token ID.
	 * @param int        $limit       Maximum messages to return.
	 * @param string[]   $session_ids Optional session IDs to scope the queue.
	 * @return array List of pending messages.
	 */
	public function get_pending_messages( int $agent_id, ?int $token_id = null, int $limit = 50, array $session_ids = array() ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';
		$query      = "SELECT queue_id, session_id, content, created_at FROM %i WHERE agent_id = %d AND status = 'pending'";
		$params     = array( $table_name, $agent_id );

		if ( null !== $token_id ) {
			$query   .= ' AND token_id = %d';
			$params[] = $token_id;
		}

		$session_ids = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $session_ids )
			)
		);

		if ( ! empty( $session_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
			$query       .= " AND session_id IN ({$placeholders})";
			$params       = array_merge( $params, $session_ids );
		}

		$query  .= ' ORDER BY created_at ASC LIMIT %d';
		$params[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, $params ),
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
	 * Acknowledge messages as delivered, scoped to a specific agent.
	 *
	 * Unlike acknowledge_messages(), this only marks messages WHERE agent_id
	 * matches — a compromised token cannot ack other agents' messages.
	 *
	 * @param array    $queue_ids Queue IDs to mark as delivered.
	 * @param int      $agent_id  Agent ID to scope the update to.
	 * @param int|null $token_id  Token ID to scope the update to.
	 * @return int Number of messages acknowledged.
	 */
	public function acknowledge_messages_for_agent( array $queue_ids, int $agent_id, ?int $token_id = null ): int {
		global $wpdb;

		if ( empty( $queue_ids ) || $agent_id <= 0 ) {
			return 0;
		}

		$table_name    = $wpdb->prefix . 'datamachine_bridge_messages';
		$placeholders  = implode( ',', array_fill( 0, count( $queue_ids ), '%s' ) );
		$query         = "UPDATE %i SET status = 'delivered', delivered_at = %s WHERE queue_id IN ({$placeholders}) AND agent_id = %d";
		$params        = array_merge( array( $table_name, current_time( 'mysql', true ) ), $queue_ids, array( $agent_id ) );

		if ( null !== $token_id ) {
			$query   .= ' AND token_id = %d';
			$params[] = $token_id;
		}

		$query .= " AND status = 'pending'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare( $query, $params )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $updated;
	}

	/**
	 * Delete delivered messages older than a given number of days.
	 *
	 * @param int $days Age threshold in days. Default 7.
	 * @return int Number of messages deleted.
	 */
	public function cleanup_delivered_messages( int $days = 7 ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_messages';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'delivered' AND delivered_at < DATE_SUB(%s, INTERVAL %d DAY)",
				$table_name,
				current_time( 'mysql', true ),
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		return (int) $deleted;
	}

	/**
	 * Remove stale bridge registrations with no heartbeat for over 24 hours.
	 *
	 * @return int Number of registrations removed.
	 */
	public function cleanup_stale_registrations(): int {
		$registrations = $this->get_all_registrations();
		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$initial_count = count( $registrations );

		$registrations = array_filter( $registrations, function ( $reg ) use ( $cutoff ) {
			return $reg['last_seen'] >= $cutoff;
		} );

		$this->save_registrations( array_values( $registrations ) );

		return $initial_count - count( $registrations );
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
