<?php

namespace RebelCode\Aggregator\Core\AdminUi;

use RebelCode\Aggregator\Core\Utils\Result;

class Tutorials {

	private const POSTS_JSON_URL = 'https://wprssaggregator.com/wp-json/wp/v2/posts';
	private const NUM_POSTS = 9;
	private const DEF_CATEGORY_ID = 70;

	private const CATEGORY_IDS = array(
		'tutorials' => 70,
		'news' => 106,
		'curation' => 243,
		'aggregation' => 263,
		'syndication' => 322,
		'monetization' => 323,
		'plugins' => 324,
	);

	public function getCategory( string $category ): Result {
		$categoryId = self::CATEGORY_IDS[ $category ] ?? self::DEF_CATEGORY_ID;

		$res = wp_remote_get(
			self::POSTS_JSON_URL . '?' . http_build_query(
				array(
					'per_page' => self::NUM_POSTS,
					'categories' => $categoryId,
				)
			)
		);

		if ( is_wp_error( $res ) ) {
			return Result::Err( $res->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$posts = json_decode( $body );

		return Result::Ok( $posts );
	}
}
