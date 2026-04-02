<?php
/**
 * Response Listener
 *
	 * Hooks into datamachine_chat_response_complete and delivers agent responses
	 * to registered bridge callback URLs via wp_remote_post().
 *
	 * Messages are always queued first; webhook delivery is best-effort and the
	 * bridge client must explicitly acknowledge queue items after receiving them.
 *
 * @package DataMachineChatBridge\Queue
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Queue;

use DataMachineChatBridge\Database\BridgeConnections;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class ResponseListener {

	/**
	 * Register the chat response hook listener.
	 */
	public static function register(): void {
		add_action( 'datamachine_chat_response_complete', array( self::class, 'on_response_complete' ), 10, 4 );
	}

	/**
	 * Handle chat response completion.
	 *
	 * Attempts webhook delivery to all registered bridges for this agent.
	 * Falls back to storing in the pending queue for poll-based retrieval.
	 *
	 * @param string $session_id    Chat session ID.
	 * @param array  $response_data Complete response data from ChatOrchestrator.
	 * @param int    $agent_id      Agent ID that produced the response.
	 * @param int    $user_id       WordPress user ID who owns the session.
	 */
	public static function on_response_complete( string $session_id, array $response_data, int $agent_id, int $user_id ): void {
		if ( empty( $agent_id ) ) {
			return;
		}

		$connections = new BridgeConnections();
		$bridges     = $connections->get_bridges_for_agent( $agent_id );

		if ( empty( $bridges ) ) {
			return;
		}

		// Build the outbound message payload.
		$message = array(
			'session_id'  => $session_id,
			'agent_id'    => $agent_id,
			'user_id'     => $user_id,
			'role'        => 'assistant',
			'content'     => $response_data['response'] ?? $response_data['final_content'] ?? '',
			'completed'   => $response_data['completed'] ?? true,
			'timestamp'   => gmdate( 'c' ),
			'metadata'    => array(
				'turn_number' => $response_data['turn_number'] ?? null,
				'max_turns'   => $response_data['max_turns'] ?? null,
			),
		);

		// Store in pending queue (always — this is the source of truth for polling).
		$queue_id = $connections->enqueue_message( $agent_id, $message );

		if ( is_wp_error( $queue_id ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Chat bridge: failed to enqueue message',
				array(
					'session_id' => $session_id,
					'agent_id'   => $agent_id,
					'error'      => $queue_id->get_error_message(),
				)
			);
			return;
		}

		// Attempt webhook delivery to each registered bridge.
		foreach ( $bridges as $bridge ) {
			if ( empty( $bridge['callback_url'] ) ) {
				continue;
			}

			$message['queue_id'] = $queue_id;

			$response = wp_remote_post( $bridge['callback_url'], array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $message ),
				'timeout' => 10,
			) );

			do_action(
				'datamachine_log',
				is_wp_error( $response ) ? 'warning' : 'info',
				is_wp_error( $response ) ? 'Chat bridge: webhook delivery failed' : 'Chat bridge: webhook delivered; awaiting bridge ack',
				array(
					'session_id' => $session_id,
					'agent_id'   => $agent_id,
					'bridge_id'  => $bridge['bridge_id'] ?? '',
					'status'     => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ),
				)
			);
		}
	}
}
