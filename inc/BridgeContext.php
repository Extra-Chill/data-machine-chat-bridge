<?php
/**
 * Bridge Execution Context
 *
 * Registers the 'bridge' execution context with Data Machine's ContextRegistry.
 * This allows agent context files (agents/{slug}/contexts/bridge.md) to be
 * loaded when agents respond to messages from external chat bridges.
 *
 * @package DataMachineChatBridge
 * @since 0.1.0
 */

namespace DataMachineChatBridge;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class BridgeContext {

	/**
	 * Register the bridge execution context.
	 *
	 * @param mixed $registry ContextRegistry instance (passed by datamachine_contexts action).
	 */
	public static function register( $registry ): void {
		if ( ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$registry->register( 'bridge', array(
			'label'       => 'Bridge',
			'description' => 'External chat bridge connections (Beeper, Matrix, etc.)',
			'priority'    => 50,
		) );
	}
}
