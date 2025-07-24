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

	public function connectZapier( string $email, bool $term ): Result {
		$zapier_webhook = 'https://hooks.zapier.com/hooks/catch/305784/ubw7rn0/';
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return Result::Err( __( 'Email is not valid', 'wp-rss-aggregator' ) );
		}

		$payload = array(
			'email' => $email,
			'term'  => (bool) $term,
		);

		$args = array(
			'timeout'     => 10,
			'body'        => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $zapier_webhook, $args );

		if ( is_wp_error( $response ) ) {
			return Result::Err( __( 'Connection failed, please try again.', 'wp-rss-aggregator' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			$body = wp_remote_retrieve_body( $response );
			return Result::Err( __( 'Returned an error: HTTP ', 'wp-rss-aggregator' ) . $status_code );
		}

		$body = trim( wp_remote_retrieve_body( $response ) );

		return Result::Ok( $body );
	}
}
