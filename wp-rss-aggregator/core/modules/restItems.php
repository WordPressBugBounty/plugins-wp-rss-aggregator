<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Rest\ItemsFeed;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

wpra()->addModule(
	'restItems',
	array( 'db' ),
	function ( Database $db ) {
		$feed = new ItemsFeed( $db );

		add_action(
			'rest_api_init',
			function () use ( $feed ) {
				register_rest_route(
					'wpra/v1',
					'/items',
					array(
						'methods' => 'GET',
						'callback' => function ( WP_REST_Request $request ) use ( $feed ) {
							try {
								$data = $feed->fetch( $request->get_param( 'cursor' ), $request->get_param( 'per_page' ) );
							} catch ( InvalidArgumentException $e ) {
								return new WP_Error( 'wpra_invalid_cursor', 'The cursor is invalid.', array( 'status' => 400 ) );
							}

							$response = new WP_REST_Response( $data );
							if ( $data['next_cursor'] !== null ) {
								$response->header( 'X-WPRA-Next-Cursor', $data['next_cursor'] );
							}

							return $response;
						},
						'permission_callback' => array( ItemsFeed::class, 'checkReadAccess' ),
						'args' => array(
							'cursor' => array(
								'type' => 'string',
							),
							'per_page' => array(
								'type' => 'integer',
								'default' => ItemsFeed::DEFAULT_LIMIT,
								'sanitize_callback' => fn ( $v ) => ItemsFeed::clampPerPage( $v ),
							),
						),
					)
				);
			}
		);

		return $feed;
	}
);
