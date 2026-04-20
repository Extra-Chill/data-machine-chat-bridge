<?php
/**
 * Bridge Execution Mode
 *
 * Registers the `bridge` execution mode with Data Machine's AgentModeRegistry
 * and composes app-aware mode guidance via the `datamachine_agent_mode_bridge`
 * filter.
 *
 * The guidance is **not** platform knowledge about any specific upstream chat
 * app — it's a generic description of the bridge environment (external network,
 * no admin tools, plain-text conversational context) plus app-aware nudges
 * assembled from the `bridge_app` slug sent in the `/bridge/send` request.
 *
 * Data Machine core ships with no default for this mode; if the chat-bridge
 * plugin isn't active, `bridge` mode emits nothing.
 *
 * @package DataMachineChatBridge
 * @since 0.1.0
 * @since 0.2.0 Migrated from ContextRegistry to AgentModeRegistry.
 * @since 0.2.0 Adds app-aware guidance via datamachine_agent_mode_bridge filter.
 */

namespace DataMachineChatBridge;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BridgeContext {

	/**
	 * Register the bridge execution mode.
	 *
	 * Called via the `datamachine_agent_modes` action.
	 */
	public static function register(): void {
		if ( ! class_exists( '\\DataMachine\\Engine\\AI\\AgentModeRegistry' ) ) {
			return;
		}

		\DataMachine\Engine\AI\AgentModeRegistry::register( 'bridge', 50, array(
			'label'       => __( 'Bridge', 'data-machine-chat-bridge' ),
			'description' => __( 'External chat bridge connections (Beeper, Matrix, iMessage, etc.)', 'data-machine-chat-bridge' ),
		) );
	}

	/**
	 * Register the datamachine_agent_mode_bridge filter.
	 *
	 * Called once at plugin bootstrap. Composes guidance for the `bridge`
	 * execution mode whenever the AgentModeDirective fires in bridge mode.
	 */
	public static function register_guidance_filter(): void {
		add_filter( 'datamachine_agent_mode_bridge', array( self::class, 'compose_guidance' ), 10, 2 );
	}

	/**
	 * Compose bridge-mode guidance from the runtime payload.
	 *
	 * Reads `$payload['client_context']` for `bridge_app`, `bridge_room_kind`,
	 * etc. (set by BridgeEndpoints::handle_send) and assembles guidance that
	 * reflects the actual upstream environment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Current guidance (defaults to empty — DM core has no bridge default).
	 * @param array  $payload Full AI request payload.
	 * @return string Final bridge-mode guidance.
	 */
	public static function compose_guidance( string $content, array $payload ): string {
		$client = is_array( $payload['client_context'] ?? null ) ? $payload['client_context'] : array();

		$app       = sanitize_key( (string) ( $client['bridge_app'] ?? '' ) );
		$room_kind = sanitize_key( (string) ( $client['bridge_room_kind'] ?? '' ) );

		$sections = array();

		// Base guidance — always present in bridge mode.
		$sections[] = self::base_section();

		// App-aware nudge, if the upstream app sent one we know how to describe.
		$app_section = self::app_section( $app );
		if ( '' !== $app_section ) {
			$sections[] = $app_section;
		}

		// Room-kind nudge (dm / group / channel).
		$room_section = self::room_section( $room_kind );
		if ( '' !== $room_section ) {
			$sections[] = $room_section;
		}

		$composed = implode( "\n\n", $sections );

		return '' === $content ? $composed : trim( $content ) . "\n\n" . $composed;
	}

	/**
	 * Base bridge guidance — generic, app-agnostic.
	 */
	private static function base_section(): string {
		return <<<'MD'
# Bridge Session Context

This is a live chat session delivered through an external chat network — not the Data Machine admin UI. The user is messaging you through a third-party app, relayed into Data Machine by the chat bridge.

## Environment

- You do **not** have access to Data Machine admin tools (pipelines, flows, schedulers, handler configuration).
- You are a conversational assistant here. Stay focused on the user's message.
- Formatting should be plain text or light Markdown. Do not emit HTML blocks, Gutenberg block comments, `datamachine/*` diff blocks, or admin-only UI affordances.
- Keep replies concise. External chat apps reward tight, message-sized responses — not long essays or multi-section briefings unless the user explicitly asks.

## Identity & Voice

- Your identity, voice, and knowledge come from your memory files above.
- Apply them as if you were texting the user directly.
- Do not reveal internal Data Machine plumbing (job IDs, pipeline step IDs, flow internals) unless the user specifically asks about them.
MD;
	}

	/**
	 * App-aware nudge for the upstream chat platform.
	 *
	 * Add new apps here as needed. No DM-core knowledge here — this is
	 * just bridge plugin knowledge about common upstream environments.
	 */
	private static function app_section( string $app ): string {
		if ( '' === $app ) {
			return '';
		}

		return match ( $app ) {
			'beeper'   => "## Upstream App: Beeper\n\nBeeper bridges many chat networks into a single Matrix room. The user may be reaching you from iMessage, WhatsApp, Signal, Telegram, Discord, or SMS — you cannot tell which from inside the conversation. Assume you cannot rely on rich formatting; plain text with light Markdown is safest.",
			'matrix'   => "## Upstream App: Matrix\n\nThe user is messaging you from a Matrix client. Standard Matrix-flavored Markdown is fine (bold, italic, inline code, links, quoted blocks). Avoid raw HTML.",
			'imessage' => "## Upstream App: iMessage\n\nThe user is texting you from iMessage. Plain text only — no Markdown, no code blocks, no special formatting. Keep replies short and conversational, the way a person would text.",
			'sms'      => "## Upstream App: SMS\n\nThe user is texting you over SMS. Plain text only, no Markdown, no links wrapped in formatting. Keep replies short and under ~300 characters when possible.",
			'whatsapp' => "## Upstream App: WhatsApp\n\nThe user is messaging you from WhatsApp. Use WhatsApp-flavored formatting sparingly: *bold*, _italic_, ~strikethrough~, ```monospace```. Keep messages short and conversational.",
			'signal'   => "## Upstream App: Signal\n\nThe user is messaging you from Signal. Plain text with light Markdown is best. Keep messages short and conversational.",
			'telegram' => "## Upstream App: Telegram\n\nThe user is messaging you from Telegram. Standard Markdown works (bold, italic, inline code, links). Keep messages conversational.",
			'discord'  => "## Upstream App: Discord\n\nThe user is messaging you from Discord. Discord-flavored Markdown is supported (bold, italic, code blocks, spoilers). Do not @-mention users unless asked — prefer names.",
			'slack'    => "## Upstream App: Slack\n\nThe user is messaging you from Slack. Use Slack-flavored formatting (*bold*, _italic_, `code`, ```code blocks```). Keep replies focused and thread-friendly.",
			default    => "## Upstream App: {$app}\n\nYou are relaying through a third-party chat network. Prefer plain text with light Markdown and keep responses conversational.",
		};
	}

	/**
	 * Room-kind nudge (dm / group / channel).
	 */
	private static function room_section( string $kind ): string {
		return match ( $kind ) {
			'dm'      => "## Conversation Type: Direct Message\n\nThis is a one-on-one conversation. You can be warmer and more personal. Address the user directly.",
			'group'   => "## Conversation Type: Group Chat\n\nMultiple people are in this conversation. Only respond when clearly addressed or when your input is unambiguously requested. Do not monopolize the thread. Do not assume you know which participant sent the latest message unless the bridge tells you.",
			'channel' => "## Conversation Type: Channel\n\nThis is a broadcast-style channel with many participants. Be concise and signal-rich — one clear reply is better than several partial ones. Avoid side conversations.",
			default   => '',
		};
	}
}
