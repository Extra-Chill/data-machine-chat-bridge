<?php
/**
 * Smoke tests for the bridge /send → canonical agents/chat migration.
 *
 * Guards against regressing the Data Machine 0.134+ upgrade blocker fixed in
 * data-machine-chat-bridge#14: BridgeEndpoints::handle_send() must dispatch
 * through the canonical `agents/chat` ability (the legacy
 * `datamachine/send-message` shim was removed in Data Machine core PR #2348).
 *
 * Run: php tests/bridge-send-agents-chat-smoke.php
 *
 * Two layers of coverage:
 *  1. Source introspection — the live ability slug and removed slug.
 *  2. Behavioral — stub WP_REST_Request + wp_get_ability + PermissionHelper
 *     and assert the canonical input mapping (agent identity, client_context)
 *     and the output mapping (reply → response).
 *
 * @package DataMachineChatBridge\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI smoke output.

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'in_array' ) ) {
	// no-op: rely on PHP built-in.
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data = null ) {}
		public function get_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $response ): WP_REST_Response {
		return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
	}
}

/**
 * Minimal WP_REST_Request stub backed by a params array.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private array $params = array() ) {}
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

/**
 * Captures the input handed to the ability so we can assert the mapping.
 */
class Bridge_Smoke_Fake_Ability {
	public ?array $captured_input = null;

	/** @var callable */
	private $handler;

	public function __construct( callable $handler ) {
		$this->handler = $handler;
	}

	public function execute( array $input ): array|WP_Error {
		$this->captured_input = $input;
		return ( $this->handler )( $input );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $slug ): ?object {
		$GLOBALS['bridge_smoke_last_slug'] = $slug;
		return 'agents/chat' === $slug ? ( $GLOBALS['bridge_smoke_fake_ability'] ?? null ) : null;
	}
}

// Stub the PermissionHelper the endpoint resolves agent/token context from.
if ( ! class_exists( 'DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval(
		'namespace DataMachine\\Abilities;
		class PermissionHelper {
			public static int $agent_id = 0;
			public static int $token_id = 0;
			public static function get_acting_agent_id(): int { return self::$agent_id; }
			public static function get_acting_token_id(): int { return self::$token_id; }
		}'
	);
}

// Stub the Chat dependency referenced by sanitize_attachments() so the class loads.
if ( ! class_exists( 'DataMachine\\Api\\Chat\\Chat' ) ) {
	eval(
		'namespace DataMachine\\Api\\Chat;
		class Chat {
			public static function sanitize_attachments( $attachments ): array {
				return is_array( $attachments ) ? $attachments : array();
			}
		}'
	);
}

// Stub remaining classes the endpoint file imports so the require below succeeds.
if ( ! class_exists( 'DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\Agents; class Agents {}' );
}
if ( ! class_exists( 'DataMachine\\Core\\Database\\Agents\\AgentTokens' ) ) {
	eval( 'namespace DataMachine\\Core\\Database\\Agents; class AgentTokens {}' );
}
if ( ! class_exists( 'DataMachineChatBridge\\Database\\AuthCodes' ) ) {
	eval( 'namespace DataMachineChatBridge\\Database; class AuthCodes {}' );
}
if ( ! class_exists( 'DataMachineChatBridge\\Database\\BridgeConnections' ) ) {
	eval( 'namespace DataMachineChatBridge\\Database; class BridgeConnections {}' );
}

$passes = 0;
$fails  = 0;

$assert = static function ( bool $condition, string $label ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}
	++$fails;
	echo "FAIL: {$label}\n";
};

echo "bridge-send-agents-chat-smoke\n";

$root           = dirname( __DIR__ );
$endpoint_path  = $root . '/inc/Api/BridgeEndpoints.php';
$endpoint_source = (string) file_get_contents( $endpoint_path );

// --- Layer 1: source introspection ---------------------------------------
$assert(
	str_contains( $endpoint_source, "wp_get_ability( 'agents/chat' )" ),
	'handle_send dispatches through the canonical agents/chat ability'
);
$assert(
	! str_contains( $endpoint_source, "wp_get_ability( 'datamachine/send-message' )" ),
	'handle_send no longer resolves the removed datamachine/send-message ability'
);
$assert(
	! str_contains( $endpoint_source, "'datamachine/send-message'" ),
	'no datamachine/send-message ability slug literal remains in the endpoint source'
);

// --- Layer 2: behavioral mapping ------------------------------------------
require_once $endpoint_path;

\DataMachine\Abilities\PermissionHelper::$agent_id = 42;
\DataMachine\Abilities\PermissionHelper::$token_id = 7;

$GLOBALS['bridge_smoke_fake_ability'] = new Bridge_Smoke_Fake_Ability(
	static fn( array $input ): array => array(
		'session_id' => 'sess-123',
		'reply'      => 'hello from agent',
		'completed'  => true,
		'metadata'   => array(),
	)
);

$request = new WP_REST_Request(
	array(
		'message'          => '  ping  ',
		'session_id'       => 'sess-123',
		'bridge_app'       => 'discord',
		'bridge_room'      => 'room-1',
		'bridge_room_kind' => 'channel',
		'attachments'      => array( array( 'url' => 'https://example.com/a.png' ) ),
	)
);

$response = \DataMachineChatBridge\Api\BridgeEndpoints::handle_send( $request );
$assert( $response instanceof WP_REST_Response, 'handle_send returns a REST response on success' );
$captured = $GLOBALS['bridge_smoke_fake_ability']->captured_input;
$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();

$assert( 'agents/chat' === ( $GLOBALS['bridge_smoke_last_slug'] ?? '' ), 'resolved the agents/chat ability slug at runtime' );

// Input mapping: agent identity is forwarded as the canonical string `agent`.
$assert( '42' === ( $captured['agent'] ?? null ), 'acting agent id is forwarded as the canonical agent field (string)' );
$assert( ! array_key_exists( 'agent_id', (array) $captured ), 'legacy agent_id input key is no longer sent' );
$assert( 'ping' === ( $captured['message'] ?? null ), 'message is trimmed before dispatch' );
$assert( 'sess-123' === ( $captured['session_id'] ?? null ), 'session_id is forwarded' );

// Capability ceiling / transport context flows through client_context.
$assert( 'bridge' === ( $captured['client_context']['source'] ?? null ), 'client_context carries the bridge source' );
$assert( 7 === ( $captured['client_context']['token_id'] ?? null ), 'client_context carries the acting token id (capability ceiling)' );
$assert( 'discord' === ( $captured['client_context']['bridge_app'] ?? null ), 'client_context carries bridge_app' );
$assert( 'room-1' === ( $captured['client_context']['bridge_room'] ?? null ), 'client_context carries bridge_room' );
$assert( 'channel' === ( $captured['client_context']['bridge_room_kind'] ?? null ), 'client_context carries bridge_room_kind' );
$assert( ! empty( $captured['attachments'] ), 'attachments are forwarded' );

// Output mapping: canonical `reply` → bridge `response`.
$assert( true === ( $data['success'] ?? null ), 'response is marked successful' );
$assert( 'sess-123' === ( $data['session_id'] ?? null ), 'session_id is returned to the bridge client' );
$assert( 'hello from agent' === ( $data['response'] ?? null ), 'canonical reply is mapped to the response field' );
$assert( true === ( $data['completed'] ?? null ), 'completed flag is returned' );

// Guard rails: agent + empty-message validation.
\DataMachine\Abilities\PermissionHelper::$agent_id = 0;
$no_agent = \DataMachineChatBridge\Api\BridgeEndpoints::handle_send( new WP_REST_Request( array( 'message' => 'hi' ) ) );
$assert( $no_agent instanceof WP_Error && 'no_agent' === $no_agent->code, 'missing agent context returns no_agent error' );

\DataMachine\Abilities\PermissionHelper::$agent_id = 42;
$empty = \DataMachineChatBridge\Api\BridgeEndpoints::handle_send( new WP_REST_Request( array( 'message' => '   ' ) ) );
$assert( $empty instanceof WP_Error && 'empty_message' === $empty->code, 'blank message returns empty_message error' );

// Missing ability surfaces a clear error rather than fataling.
$GLOBALS['bridge_smoke_fake_ability'] = null;
$missing = \DataMachineChatBridge\Api\BridgeEndpoints::handle_send( new WP_REST_Request( array( 'message' => 'hi' ) ) );
$assert( $missing instanceof WP_Error && 'ability_not_found' === $missing->code, 'missing agents/chat ability returns ability_not_found error' );

echo "\n{$passes} passed, {$fails} failed\n";
if ( $fails > 0 ) {
	exit( 1 );
}
