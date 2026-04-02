<?php
/**
 * PKCE Authorization Codes
 *
 * Manages short-lived authorization codes for the OAuth-style bridge login flow.
 * The Go bridge sends a code_challenge in the authorize URL; WordPress stores it
 * with a random auth code. When the callback fires, the bridge exchanges the code
 * + code_verifier for a bearer token. PKCE ensures the code is useless without
 * the verifier.
 *
 * @package DataMachineChatBridge\Database
 * @since 0.1.0
 */

namespace DataMachineChatBridge\Database;

use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AuthCodes {

	/**
	 * Code lifetime in seconds (10 minutes).
	 */
	private const CODE_TTL = 600;

	/**
	 * Generate and store a PKCE authorization code.
	 *
	 * @param int    $agent_id       Agent ID being authorized.
	 * @param int    $user_id        WordPress user who authorized.
	 * @param string $code_challenge PKCE S256 challenge (base64url-encoded SHA256 hash).
	 * @param string $redirect_uri   The bridge's callback URL.
	 * @param string $label          Optional token label (e.g. "beeper-roadie").
	 * @return string The generated authorization code.
	 */
	public function create_code( int $agent_id, int $user_id, string $code_challenge, string $redirect_uri, string $label = '' ): string {
		global $wpdb;

		$code       = bin2hex( random_bytes( 32 ) ); // 64-char hex string.
		$table_name = $wpdb->prefix . 'datamachine_bridge_auth_codes';

		$wpdb->insert(
			$table_name,
			array(
				'code'           => $code,
				'agent_id'       => $agent_id,
				'user_id'        => $user_id,
				'code_challenge' => $code_challenge,
				'redirect_uri'   => $redirect_uri,
				'label'          => $label,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $code;
	}

	/**
	 * Exchange an authorization code + PKCE verifier for agent credentials.
	 *
	 * Validates the code exists, hasn't expired, the redirect_uri matches,
	 * and the code_verifier hashes to the stored code_challenge. On success,
	 * deletes the code (single-use) and returns agent info.
	 *
	 * @param string $code          The authorization code.
	 * @param string $code_verifier The original PKCE verifier.
	 * @param string $redirect_uri  The redirect_uri from the original request.
	 * @return array{agent_id: int, user_id: int, label: string}|WP_Error
	 */
	public function exchange_code( string $code, string $code_verifier, string $redirect_uri ): array|WP_Error {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_auth_codes';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE code = %s',
				$table_name,
				$code
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( ! $row ) {
			return new WP_Error( 'invalid_code', 'Authorization code not found.', array( 'status' => 400 ) );
		}

		// Check expiry.
		if ( strtotime( $row['expires_at'] ) < time() ) {
			// Delete expired code.
			$wpdb->delete( $table_name, array( 'code' => $code ), array( '%s' ) );
			return new WP_Error( 'expired_code', 'Authorization code has expired. Please try again.', array( 'status' => 400 ) );
		}

		// Verify redirect_uri matches.
		if ( $row['redirect_uri'] !== $redirect_uri ) {
			return new WP_Error( 'redirect_mismatch', 'redirect_uri does not match the original request.', array( 'status' => 400 ) );
		}

		// Verify PKCE: hash the verifier and compare to stored challenge.
		$computed_challenge = $this->compute_pkce_challenge( $code_verifier );
		if ( ! hash_equals( $row['code_challenge'], $computed_challenge ) ) {
			return new WP_Error( 'pkce_failed', 'Code verification failed.', array( 'status' => 400 ) );
		}

		// Single-use: delete the code.
		$wpdb->delete( $table_name, array( 'code' => $code ), array( '%s' ) );

		return array(
			'agent_id' => (int) $row['agent_id'],
			'user_id'  => (int) $row['user_id'],
			'label'    => $row['label'],
		);
	}

	/**
	 * Delete expired authorization codes.
	 *
	 * Called by scheduled cleanup job.
	 *
	 * @return int Number of codes deleted.
	 */
	public function cleanup_expired(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'datamachine_bridge_auth_codes';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$table_name,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		return (int) $deleted;
	}

	/**
	 * Compute PKCE code_challenge from code_verifier.
	 *
	 * Per RFC 7636 S256: BASE64URL(SHA256(code_verifier))
	 *
	 * @param string $verifier The PKCE code verifier.
	 * @return string The code challenge (base64url-encoded, no padding).
	 */
	private function compute_pkce_challenge( string $verifier ): string {
		$hash = hash( 'sha256', $verifier, true );
		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
	}
}
