<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use WP_Term;
use RebelCode\Aggregator\Core\Utils\Result;

class RpcWpHandler {

	/** @return list<array{id:int,slug:string,name:string,parent:int}> */
	public function getTerms( string $taxonomy, string $search = '', int $num = 50 ): array {
		$query = array(
			'taxonomy' => $taxonomy,
			'number' => $num,
			'hide_empty' => false,
		);

		$search = trim( $search );
		if ( strlen( $search ) > 0 ) {
			$query['search'] = $search;
		}

		$terms = get_terms( $query );

		if ( is_wp_error( $terms ) ) {
			return Result::Err( $terms->get_error_message() );
		}

		if ( ! is_array( $terms ) ) {
			return Result::Err( 'Unexpected result from get_terms()', 'wprss' );
		}

		$results = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$results[] = array(
					'id' => $term->term_id,
					'slug' => $term->slug,
					'name' => $term->name,
					'parent' => $term->parent,
				);
			}
		}

		return $results;
	}

	/** @return list<array{id:int,name:string,email:string}> */
	public function getUsers( string $search = '', int $num = 50 ): array {
		$users = get_users(
			array(
				'search' => "*{$search}*",
				'search_columns' => array( 'display_name', 'user_nicename', 'user_login', 'user_email' ),
				'number' => $num,
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id' => $user->ID,
				'name' => $user->display_name,
				'email' => $user->user_email,
			);
		}

		return $results;
	}

	public function getMediaUrl( int $id ): string {
		return wp_get_attachment_url( $id );
	}

	/**
	 * Subscribe a contact to FluentCRM via external webhooks.
	 *
	 * Since 5.0.8:
	 * - Zapier has been fully removed from the opt-in workflow.
	 * - FluentCRM does not support conditional checks for assigning lists,
	 *   so this method now triggers two separate webhooks:
	 *     1. The main subscription webhook (always fired).
	 *     2. An optional "terms accepted" webhook when $term is true.
	 *
	 * No custom database tables or additional abstractions were introduced
	 * to keep the logic simple and fully compatible with standard EDD
	 * and FluentCRM webhook handling.
	 *
	 * @param string $email Email address to subscribe.
	 * @param bool   $term  Whether the user accepted the terms (triggers second webhook).
	 *
	 * @return Result
	 */
	public function subscribeToFluentCRM( string $email, bool $term ): Result {
		$fluentCRM_webhook = 'https://www.wprssaggregator.com/?fluentcrm=1&route=contact&hash=42207fed-0850-4dfb-bb81-987d0f9de7a7';
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return Result::Err( __( 'Email is not valid', 'wp-rss-aggregator' ) );
		}

		$payload = array(
			'email' => $email,
		);

		$args = array(
			'timeout'     => 10,
			'body'        => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $fluentCRM_webhook, $args );

		if ( is_wp_error( $response ) ) {
			return Result::Err( __( 'Connection failed, please try again.', 'wp-rss-aggregator' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			return Result::Err( __( 'Returned an error: HTTP ', 'wp-rss-aggregator' ) . $status_code );
		}

		if ( $term ) {
			$fluentCRM_webhook_term = 'https://www.wprssaggregator.com/?fluentcrm=1&route=contact&hash=5a61552e-c027-4e62-ad3a-72a74ae25f9f';
			$response_term = wp_remote_post( $fluentCRM_webhook_term, $args );

			if ( is_wp_error( $response_term ) ) {
				return Result::Err( __( 'Connection failed for the second subscription, please try again.', 'wp-rss-aggregator' ) );
			}

			$status_code_term = wp_remote_retrieve_response_code( $response_term );

			if ( $status_code_term >= 400 ) {
				return Result::Err( __( 'Returned an error for the second subscription: HTTP ', 'wp-rss-aggregator' ) . $status_code_term );
			}
		}

		$body = trim( wp_remote_retrieve_body( $response ) );

		return Result::Ok( $body );
	}

	/**
	 * @deprecated 5.0.8 Use RpcWpHandler::subscribeToFluentCRM() instead.
	 */
	public function connectZapier( string $email, bool $term ): Result {
		_deprecated_function(
			__METHOD__,
			'5.0.8',
			'RpcWpHandler::subscribeToFluentCRM'
		);
		return $this->subscribeToFluentCRM( $email, $term );
	}
}
