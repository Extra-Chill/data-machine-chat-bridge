<?php
/**
 * Bridge Execution Mode
 *
 * Registers the 'bridge' execution mode with Data Machine's AgentModeRegistry.
 * This allows mode-specific guidance to be injected when agents respond to
 * messages from external chat bridges.
 *
 * @package DataMachineChatBridge
 * @since 0.1.0
 * @since 0.2.0 Migrated from ContextRegistry to AgentModeRegistry.
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
			'label'       => 'Bridge',
			'description' => 'External chat bridge connections (Beeper, Matrix, etc.)',
		) );
	}
}
