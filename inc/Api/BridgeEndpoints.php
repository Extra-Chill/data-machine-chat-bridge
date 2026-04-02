<?php
/**
 * Bridge REST Endpoints
 *
 * Registers /chat-bridge/v1 routes for external bridge clients:
 * - POST /register  — register a bridge with a callback URL
 * - GET  /pending   — poll for undelivered agent responses
 * - POST /ack       — acknowledge delivered messages
 * - GET  /identity  — get agent identity for this token
 * - GET  /authorize — PKCE-aware OAuth authorize (proxies to core or returns auth code)
 * - POST /token     — exchange PKCE auth code for bearer token
 *
 * All /register, /pending, /ack, /identity endpoints require agent token auth
 * (handled by core's AgentAuthMiddleware).
 *
 * /authorize and /token are unauthenticated (login flow endpoints).
 *
 * @package DataMachineChatBridge\Api
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentTokens;
use DataMachineChatBridge\Database\AuthCodes;
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

		// --- Authenticated endpoints (agent token required) ---

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
					'session_ids' => array(
						'required'          => false,
						'description'       => 'Optional array or comma-separated list of session IDs to scope polling.',
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
				'args'                => array(),
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

		// --- Unauthenticated endpoints (login flow) ---

		// POST /token — exchange PKCE auth code for bearer token.
		register_rest_route(
			'chat-bridge/v1',
			'/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'          => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => 'The authorization code received from the authorize callback.',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code_verifier' => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => 'The PKCE code verifier.',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'redirect_uri'  => array(
						'type'              => 'string',
						'required'          => true,
						'description'       => 'The redirect_uri from the original authorize request.',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		// POST /authorize — PKCE-aware authorize that stores code_challenge and returns auth code via redirect.
		// This is called BY the core AgentAuthorize consent screen form submission.
		// The bridge plugin hooks into the authorize flow to intercept PKCE requests.
	}

	/**
	 * Handle POST /register — register or update a bridge connection.
	 */
	public static function handle_register( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$agent_id     = PermissionHelper::get_acting_agent_id();
		$token_id     = PermissionHelper::get_acting_token_id();
		$callback_url = $request->get_param( 'callback_url' );
		$bridge_id    = $request->get_param( 'bridge_id' ) ?? '';

		if ( ! $agent_id ) {
			return new WP_Error( 'no_agent', 'No agent context found.', array( 'status' => 400 ) );
		}

		$connections = new BridgeConnections();
		$result      = $connections->register_bridge( $agent_id, $token_id, $callback_url, $bridge_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'success'         => true,
			'registration_id' => $result,
			'agent_id'        => $agent_id,
			'token_id'        => $token_id,
			'bridge_id'       => $bridge_id,
			'callback_url'    => $callback_url,
			'poll_endpoint'   => rest_url( 'chat-bridge/v1/pending' ),
		) );
	}

	/**
	 * Handle GET /pending — return undelivered messages for this agent.
	 */
	public static function handle_pending( WP_REST_Request $request ): \WP_REST_Response {
		$agent_id    = PermissionHelper::get_acting_agent_id();
		$token_id    = PermissionHelper::get_acting_token_id();
		$limit       = $request->get_param( 'limit' ) ?: 50;
		$session_ids = self::normalize_string_list( $request->get_param( 'session_ids' ) );

		$connections = new BridgeConnections();
		$messages    = $connections->get_pending_messages( $agent_id, $token_id, $limit, $session_ids );

		return rest_ensure_response( array(
			'success'  => true,
			'messages' => $messages,
			'count'    => count( $messages ),
		) );
	}

	/**
	 * Handle POST /ack — mark messages as delivered.
	 *
	 * Only acknowledges messages belonging to the authenticated agent's token.
	 */
	public static function handle_ack( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$agent_id    = PermissionHelper::get_acting_agent_id();
		$token_id    = PermissionHelper::get_acting_token_id();
		$message_ids = self::resolve_message_ids( $request );

		if ( empty( $message_ids ) ) {
			return new WP_Error(
				'invalid_message_ids',
				'message_ids must be a non-empty array. Legacy ids is also accepted.',
				array( 'status' => 400 )
			);
		}

		$connections = new BridgeConnections();

		// Scope: only ack messages that belong to this agent.
		$acknowledged = $connections->acknowledge_messages_for_agent( $message_ids, (int) $agent_id, $token_id );

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

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new WP_Error( 'agent_not_found', 'Agent not found.', array( 'status' => 404 ) );
		}

		$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );

		$data = array(
			'agent_id'   => (int) $agent['agent_id'],
			'token_id'   => PermissionHelper::get_acting_token_id(),
			'agent_slug' => $agent['agent_slug'],
			'agent_name' => $agent['agent_name'],
			'status'     => $agent['status'] ?? 'active',
			'site_url'   => get_site_url(),
			'site_name'  => get_bloginfo( 'name' ),
			'site_host'  => $site_host,
		);

		return rest_ensure_response(
			array_merge(
				array(
					'success' => true,
					'data'    => $data,
				),
				$data
			)
		);
	}

	/**
	 * Handle POST /token — exchange PKCE auth code for bearer token.
	 *
	 * This is the OAuth token endpoint. The Go bridge calls this after
	 * receiving an auth code via the authorize callback redirect.
	 *
	 * Flow:
	 * 1. Bridge sends code + code_verifier + redirect_uri
	 * 2. We validate PKCE (hash verifier → compare to stored challenge)
	 * 3. On match: mint a new agent token and return it
	 * 4. Delete the auth code (single-use)
	 */
	public static function handle_token( WP_REST_Request $request ): \WP_REST_Response|WP_Error {
		$code          = $request->get_param( 'code' );
		$code_verifier = $request->get_param( 'code_verifier' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );

		$auth_codes = new AuthCodes();
		$result     = $auth_codes->exchange_code( $code, $code_verifier, $redirect_uri );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mint a new agent token.
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $result['agent_id'] );

		if ( ! $agent ) {
			return new WP_Error( 'agent_not_found', 'Agent not found.', array( 'status' => 404 ) );
		}

		$tokens_repo = new AgentTokens();
		$token_label = ! empty( $result['label'] )
			? $result['label']
			: 'bridge-' . gmdate( 'Y-m-d' );

		$token_result = $tokens_repo->create_token(
			(int) $agent['agent_id'],
			$agent['agent_slug'],
			$token_label,
			null, // All capabilities — the bridge needs full chat access.
			null  // No expiry.
		);

		if ( ! $token_result ) {
			return new WP_Error(
				'token_creation_failed',
				'Failed to create agent token.',
				array( 'status' => 500 )
			);
		}

		$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );

		return rest_ensure_response( array(
			'success'      => true,
			'access_token' => $token_result['raw_token'],
			'token_type'   => 'Bearer',
			'token_id'     => (int) $token_result['token_id'],
			'token_label'  => $token_label,
			'agent_id'     => (int) $agent['agent_id'],
			'agent_slug'   => $agent['agent_slug'],
			'agent_name'   => $agent['agent_name'],
			'site_url'     => get_site_url(),
			'site_host'    => $site_host,
		) );
	}

	// -------------------------------------------------------------------------
	// PKCE Authorize Hook
	// -------------------------------------------------------------------------

	/**
	 * Hook into core's AgentAuthorize to intercept PKCE authorize requests.
	 *
	 * When code_challenge is present in the authorize request, we intercept
	 * the flow: instead of minting a token directly, we store the PKCE
	 * challenge and return an auth code via the redirect.
	 *
	 * Hooks the datamachine_agent_authorize_pre_token filter in core's
	 * AgentAuthorize::handle_authorize_post() before the token is created.
	 */
	public static function register_pkce_hook(): void {
		add_filter( 'datamachine_agent_authorize_pre_token', array( self::class, 'handle_pkce_authorize' ), 10, 6 );
	}

	/**
	 * Intercept the authorize flow when PKCE params are present.
	 *
	 * Returns a redirect URL with auth code + state for the bridge callback,
	 * or null to let core proceed with default token minting.
	 *
	 * @param string|null      $redirect_url null to proceed, or URL to redirect to.
	 * @param array            $agent        Agent row (agent_id, agent_slug, agent_name, owner_id, agent_config).
	 * @param int              $user_id      Authorizing WordPress user ID.
	 * @param string           $redirect_uri The redirect_uri from the request.
	 * @param string           $label        Token label.
	 * @param \WP_REST_Request $request      Full request object.
	 * @return string|null Redirect URL for PKCE flow, or null for default.
	 */
	public static function handle_pkce_authorize( ?string $redirect_url, array $agent, int $user_id, string $redirect_uri, string $label, \WP_REST_Request $request ): ?string {
		$code_challenge        = sanitize_text_field( $request->get_param( 'code_challenge' ) );
		$code_challenge_method = sanitize_text_field( $request->get_param( 'code_challenge_method' ) );
		$state                 = sanitize_text_field( $request->get_param( 'state' ) );

		if ( empty( $code_challenge ) || 'S256' !== $code_challenge_method ) {
			// No PKCE — let core handle it (direct token minting).
			return $redirect_url;
		}

		// Store the auth code with the PKCE challenge.
		$auth_codes = new AuthCodes();
		$code       = $auth_codes->create_code(
			(int) $agent['agent_id'],
			$user_id,
			$code_challenge,
			$redirect_uri,
			$label
		);

		// Build the callback URL with code + state.
		return add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);
	}

	/**
	 * Normalize a request value into a list of strings.
	 *
	 * Accepts arrays, comma-separated strings, or repeated values passed through
	 * WP_REST_Request.
	 *
	 * @param mixed $value Raw request value.
	 * @return string[]
	 */
	private static function normalize_string_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			$values = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$values = explode( ',', $value );
		} else {
			$values = array();
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', $values )
			)
		);
	}

	/**
	 * Resolve ack message IDs from canonical or legacy field names.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string[]
	 */
	private static function resolve_message_ids( WP_REST_Request $request ): array {
		$message_ids = $request->get_param( 'message_ids' );

		if ( empty( $message_ids ) ) {
			$message_ids = $request->get_param( 'ids' );
		}

		return self::normalize_string_list( $message_ids );
	}
}
