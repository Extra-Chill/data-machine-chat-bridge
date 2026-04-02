<?php
/**
 * Bridge REST Endpoints
 *
 * Registers /chat-bridge/v1 routes for external bridge clients:
 * - POST /register  — register a bridge with a callback URL
 * - GET  /pending   — poll for undelivered agent responses
 * - POST /ack       — acknowledge delivered messages
 * - GET  /identity  — get agent identity for this token
 *
 * All endpoints require agent token auth (handled by core's AgentAuthMiddleware).
 *
 * @package DataMachineChatBridge\Api
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachineChatBridge\Database\BridgeConnections;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BridgeEndpoints {

	/**
	 * Register REST routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /chat-bridge/v1 routes.
	 */
	public static function register_routes(): void {
		$token_auth = function () {
			return PermissionHelper::in_agent_context();
		};

		// POST /register — bridge registers itself with a callback URL.
		register_rest_route(
			'chat-bridge/v1',
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_register' ),
				'permission_callback' => $token_auth,
				'args'                => array(
					'callback_url' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => 'URL for the bridge to receive webhook callbacks.',
						'sanitize_callback' => 'esc_url_raw',
					),
					'bridge_id'    => array(
						'type'              => 'string',
						'required'          => false,
						'description'       => 'Unique identifier for this bridge instance.',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /pending — poll for undelivered messages.
		register_rest_route(
			'chat-bridge/v1',
			'/pending',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_pending' ),
				'permission_callback' => $token_auth,
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /ack — acknowledge delivered messages.
		register_rest_route(
			'chat-bridge/v1',
			'/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_ack' ),
				'permission_callback' => $token_auth,
				'args'                => array(
					'message_ids' => array(
						'type'              => 'array',
						'required'          => true,
						'description'       => 'Array of message queue IDs to acknowledge.',
						'validate_callback' => function ( $param ) {
							return is_array( $param ) && ! empty( $param );
						},
					),
				),
			)
		);

		// GET /identity — agent identity for this token.
		register_rest_route(
			'chat-bridge/v1',
			'/identity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_identity' ),
				'permission_callback' => $token_auth,
			)
		);
	}

	/**
	 * Handle POST /register — register or update a bridge connection.
	 */
	public static function handle_register( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$agent_id      = PermissionHelper::get_acting_agent_id();
		$callback_url  = $request->get_param( 'callback_url' );
		$bridge_id     = $request->get_param( 'bridge_id' ) ?? '';
		$token_caps    = PermissionHelper::get_agent_token_capabilities();

		$connections = new BridgeConnections();
		$result      = $connections->register_bridge( $agent_id, $callback_url, $bridge_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'success'         => true,
			'registration_id' => $result,
			'agent_id'        => $agent_id,
			'poll_endpoint'   => rest_url( 'chat-bridge/v1/pending' ),
		) );
	}

	/**
	 * Handle GET /pending — return undelivered messages for this agent.
	 */
	public static function handle_pending( WP_REST_Request $request ): \WP_REST_Response {
		$agent_id = PermissionHelper::get_acting_agent_id();
		$limit    = $request->get_param( 'limit' ) ?: 50;

		$connections = new BridgeConnections();
		$messages    = $connections->get_pending_messages( $agent_id, $limit );

		return rest_ensure_response( array(
			'success'  => true,
			'messages' => $messages,
			'count'    => count( $messages ),
		) );
	}

	/**
	 * Handle POST /ack — mark messages as delivered.
	 */
	public static function handle_ack( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$message_ids = $request->get_param( 'message_ids' );

		if ( empty( $message_ids ) || ! is_array( $message_ids ) ) {
			return new WP_Error(
				'invalid_message_ids',
				'message_ids must be a non-empty array.',
				array( 'status' => 400 )
			);
		}

		$connections  = new BridgeConnections();
		$acknowledged = $connections->acknowledge_messages( $message_ids );

		return rest_ensure_response( array(
			'success'      => true,
			'acknowledged' => $acknowledged,
		) );
	}

	/**
	 * Handle GET /identity — return agent info for the authenticated token.
	 */
	public static function handle_identity( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$agent_id = PermissionHelper::get_acting_agent_id();

		if ( ! $agent_id ) {
			return new WP_Error( 'no_agent', 'No agent context found.', array( 'status' => 400 ) );
		}

		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new WP_Error( 'agent_not_found', 'Agent not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'agent_id'   => (int) $agent['agent_id'],
			'agent_slug' => $agent['agent_slug'],
			'agent_name' => $agent['agent_name'],
			'status'     => $agent['status'] ?? 'active',
			'site_url'   => get_site_url(),
			'site_name'  => get_bloginfo( 'name' ),
		) );
	}
}
