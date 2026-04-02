<?php
/**
 * Scheduled Cleanup Jobs
 *
 * Daily cron tasks for the chat bridge:
 * - Expired PKCE auth codes
 * - Delivered messages older than 7 days
 * - Stale bridge registrations (no heartbeat > 24h)
 *
 * @package DataMachineChatBridge\Cron
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Cron;

use DataMachineChatBridge\Database\AuthCodes;
use DataMachineChatBridge\Database\BridgeConnections;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Cleanup {

	/**
	 * Cron hook name.
	 */
	private const CRON_HOOK = 'datamachine_chat_bridge_daily_cleanup';

	/**
	 * Register the cron event and hook.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );

		// Schedule if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron event (called on deactivation).
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run all cleanup tasks.
	 */
	public static function run(): void {
		$auth_codes  = new AuthCodes();
		$connections = new BridgeConnections();

		$codes_deleted     = $auth_codes->cleanup_expired();
		$messages_deleted  = $connections->cleanup_delivered_messages( 7 );
		$regs_removed      = $connections->cleanup_stale_registrations();

		do_action(
			'datamachine_log',
			'info',
			'Chat bridge daily cleanup completed',
			array(
				'expired_auth_codes'    => $codes_deleted,
				'delivered_messages'    => $messages_deleted,
				'stale_registrations'   => $regs_removed,
			)
		);
	}
}
