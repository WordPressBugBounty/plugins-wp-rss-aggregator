<?php

namespace RebelCode\Aggregator\Core\Utils;

use WP_Query;
use Generator;
class WpUtils {

	/** @return Generator<WP_Post|int> */
	public static function batchQueryPosts( int $perPage, array $args ): Generator {
		$args['posts_per_page'] = $perPage;
		$page                   = 1;

		do {
			$args['paged'] = $page++;

			$query = new WP_Query( $args );

			foreach ( $query->posts as $post ) {
				yield $post;
			}
		} while ( $query->have_posts() );
	}

	/** @return Generator<WP_Post|int|string> */
	public static function batchQueryTerms( int $perPage, array $args ): Generator {
		$args['posts_per_page'] = $perPage;
		$page = 1;

		do {
			$args['paged'] = $page;
			$page++;

			$results = get_terms( $args );

			foreach ( $results as $item ) {
				yield $item;
			}
		} while ( count( $results ) > 0 );
	}

	public static function isMultiSite(): bool {
		if ( ! function_exists( 'is_multisite' ) || ! function_exists( 'get_sites' ) ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return false;
		}
		$sites = get_sites();
		if ( count( $sites ) === 0 ) {
			return false;
		}
		return true;
	}

	public static function getSites(): array {
		if ( ! function_exists( 'get_sites' ) ) {
			return array();
		}
		$result = array();
		foreach ( get_sites() as $site ) {
			/** @var \WP_Site $site */
			$result[] = array(
				'id' => (int) $site->blog_id,
				'path' => $site->path,
				'name' => $site->blogname,
			);
		}
		return $result;
	}
}
