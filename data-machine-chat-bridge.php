<?php
/**
 * Plugin Name: Data Machine Chat Bridge
 * Plugin URI: https://github.com/Extra-Chill/data-machine-chat-bridge
 * Description: External chat bridge connections for Data Machine. Message queue, webhook delivery, and REST API for any chat client integration.
 * Version: 0.3.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-chat-bridge
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMACHINE_CHAT_BRIDGE_VERSION', '0.3.0' );
define( 'DATAMACHINE_CHAT_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_CHAT_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Data Machine core must be active — check at plugins_loaded time.
 */
function datamachine_chat_bridge_bootstrap() {
	if ( ! class_exists( 'DataMachine\Abilities\PermissionHelper' ) ) {
		add_action( 'admin_notices', function () {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Data Machine Chat Bridge requires Data Machine core plugin to be installed and activated.', 'data-machine-chat-bridge' ); ?></p>
			</div>
			<?php
		} );
		return;
	}

	// Register REST endpoints.
	\DataMachineChatBridge\Api\BridgeEndpoints::register();

	// Register PKCE authorize hook into core's AgentAuthorize flow.
	\DataMachineChatBridge\Api\BridgeEndpoints::register_pkce_hook();

	// Register the response listener (hooks into core's chat response action).
	\DataMachineChatBridge\Queue\ResponseListener::register();

	// Register execution context.
	add_action( 'datamachine_contexts', array( \DataMachineChatBridge\BridgeContext::class, 'register' ) );

	// Register daily cleanup cron.
	\DataMachineChatBridge\Cron\Cleanup::register();

	// Run schema upgrades when plugin version changes.
	datamachine_chat_bridge_maybe_upgrade();
}
add_action( 'plugins_loaded', 'datamachine_chat_bridge_bootstrap', 20 );

/**
 * Run database migrations on activation.
 */
function datamachine_chat_bridge_activate() {
	\DataMachineChatBridge\Database\Schema::create_tables();
	update_option( 'datamachine_chat_bridge_version', DATAMACHINE_CHAT_BRIDGE_VERSION, false );
}
register_activation_hook( __FILE__, 'datamachine_chat_bridge_activate' );

/**
 * Unschedule cron on deactivation.
 */
function datamachine_chat_bridge_deactivate() {
	\DataMachineChatBridge\Cron\Cleanup::unschedule();
}
register_deactivation_hook( __FILE__, 'datamachine_chat_bridge_deactivate' );

/**
 * Run schema upgrades on plugin version changes.
 *
 * @return void
 */
function datamachine_chat_bridge_maybe_upgrade() {
	$installed_version = get_option( 'datamachine_chat_bridge_version', '' );

	if ( DATAMACHINE_CHAT_BRIDGE_VERSION === $installed_version ) {
		return;
	}

	\DataMachineChatBridge\Database\Schema::create_tables();
	update_option( 'datamachine_chat_bridge_version', DATAMACHINE_CHAT_BRIDGE_VERSION, false );
}
