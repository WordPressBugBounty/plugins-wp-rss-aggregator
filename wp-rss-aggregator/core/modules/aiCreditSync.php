<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Licensing\License;
use WP_REST_Request;
use WP_REST_Response;

wpra()->addModule(
	'aiCreditSync',
	array( 'licensing' ),
	function ( Licensing $licensing ) {
		$handler = new AiCreditSyncEndpoint( $licensing );
		add_action( 'rest_api_init', [ $handler, 'register_routes' ] );
		return $handler;
	}
);

class AiCreditSyncEndpoint {
	private const ROUTE_NAMESPACE = 'wpra/v1';
	private const ROUTE_PATH = '/ai-sync-credits';
	private const TIMESTAMP_WINDOW = 300;
	private const ACTION_REFRESH_LICENSE = 'refresh_license';

	/**
	 * @var Licensing
	 */
	private Licensing $licensing;

	public function __construct( Licensing $licensing ) {
		$this->licensing = $licensing;
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$license_key = $this->sanitize_string( $payload['license_key'] ?? '' );
		$action      = $this->sanitize_string( $payload['action'] ?? self::ACTION_REFRESH_LICENSE );
		$site_url    = $this->canonicalize_site_url( $payload['site_url'] ?? '' );
		$timestamp   = absint( $payload['timestamp'] ?? 0 );
		$signature   = $this->sanitize_string( $payload['signature'] ?? '' );

		if ( '' === $license_key || '' === $site_url || $timestamp <= 0 || '' === $signature ) {
			return $this->error_response( esc_html__( 'Invalid credit sync payload.', 'wp-rss-aggregator' ), 400 );
		}

		$expected_site_url = $this->canonicalize_site_url( network_site_url() );
		if ( '' === $expected_site_url || $site_url !== $expected_site_url ) {
			return $this->error_response( esc_html__( 'Site URL mismatch.', 'wp-rss-aggregator' ), 403 );
		}

		$current_license = $this->licensing->getLicense();
		if ( null !== $current_license && $current_license->key !== $license_key ) {
			return $this->error_response( esc_html__( 'License key does not match.', 'wp-rss-aggregator' ), 403 );
		}

		$expected_signature = hash_hmac( 'sha256', $license_key . $action . $site_url . $timestamp, $license_key );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return $this->error_response( esc_html__( 'Signature mismatch.', 'wp-rss-aggregator' ), 403 );
		}

		if ( abs( time() - $timestamp ) > self::TIMESTAMP_WINDOW ) {
			return $this->error_response( esc_html__( 'Timestamp is too old.', 'wp-rss-aggregator' ), 400 );
		}

		if ( self::ACTION_REFRESH_LICENSE !== $action ) {
			return $this->error_response( esc_html__( 'Unsupported sync action.', 'wp-rss-aggregator' ), 400 );
		}

		$refresh_result = $this->refresh_license( $license_key );

		if ( $refresh_result instanceof WP_REST_Response ) {
			return $refresh_result;
		}

		return rest_ensure_response(
			[
				'success' => true,
				'action'  => $action,
			]
		);
	}

	/**
	 * Refreshes the locally stored license from the licensing server.
	 *
	 * @return true|WP_REST_Response
	 */
	private function refresh_license( string $license_key ) {
		$check_result = $this->licensing->check( $license_key );
		if ( $check_result->isErr() ) {
			return $this->error_response( $check_result->error()->getMessage(), 500 );
		}

		/** @var License $license */
		$license = $check_result->get();
		$this->licensing->setLicense( $license );

		return true;
	}

	/**
	 * Normalizes the incoming site URL for comparison.
	 */
	private function canonicalize_site_url( string $site_url ): string {
		$site_url = trim( sanitize_text_field( $site_url ) );
		if ( '' === $site_url ) {
			return '';
		}

		$site_url = $this->ensure_scheme( $site_url );
		if ( '' === $site_url ) {
			return '';
		}

		return untrailingslashit( $site_url );
	}

	/**
	 * Ensures the provided URL contains a scheme for validation.
	 */
	private function ensure_scheme( string $site_url ): string {
		$parsed = wp_parse_url( $site_url );
		if ( false !== $parsed && ! empty( $parsed['scheme'] ) ) {
			return $site_url;
		}

		$scheme = $this->detect_scheme_for_host( $site_url );
		return $scheme . '://' . ltrim( $site_url, '/' );
	}

	/**
	 * Picks a default protocol for known hosts.
	 */
	private function detect_scheme_for_host( string $site_url ): string {
		$lower = strtolower( $site_url );
		if ( str_contains( $lower, 'localhost' ) || str_contains( $lower, '127.' ) || str_contains( $lower, '::1' ) ) {
			return 'http';
		}

		return 'https';
	}

	/**
	 * Sanitizes a string field for comparison.
	 */
	private function sanitize_string( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Returns a formatted error response.
	 */
	private function error_response( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'success' => false,
				'message' => $message,
			],
			$status
		);
	}
}
