<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use WP_Post;
use RebelCode\Aggregator\Core\Utils\WpUtils;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Store\RejectListStore;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\RejectedItem;
use RebelCode\Aggregator\Core\Logger;
use Generator;

class V4BlacklistMigrator {

	private RejectListStore $store;

	public function __construct( RejectListStore $store ) {
		$this->store = $store;
	}

	public function getCount(): int {
		$counts = wp_count_posts( 'wprss_blacklist' );
		return array_sum( (array) $counts );
	}

	/** @return Generator<Source> */
	public function migrateAll( bool $dryRun = false ): Generator {
		try {
			$posts = WpUtils::batchQueryPosts( 100, array( 'post_type' => 'wprss_blacklist' ) );

			foreach ( $posts as $post ) {
				$result = $this->migrate( $post->ID, $dryRun );
				if ( $result->isErr() ) {
					// Log is already done in migrate() method if it's an error we catch there
					// Or we can log a more general error here if needed.
					// For now, relying on migrate() to log specific errors.
				}
				yield $result;
				usleep( 50_000 );
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4 blacklist migration in migrateAll: %s', $e->getMessage() ) );
		}
	}

	/** @return Result<RejectedItem> */
	public function migrate( int $id, bool $dryRun = false ): Result {
		try {
			$srcPost = get_post( $id );
			if ( ! ( $srcPost instanceof WP_Post ) ) {
				$errMsg = sprintf( "V4 Blacklist migration: WordPress post with ID '%d' does not exist or is not a WP_Post object.", $id );
				Logger::error( $errMsg );
				return Result::Err( $errMsg );
			}

			$name = $srcPost->post_title;
			$url = get_post_meta( $id, 'wprss_permalink', true );
			if ( empty( $url ) ) {
				// It might be valid for a blacklist item to not have a URL, but good to log if unexpected.
				// Logger::warning(sprintf("V4 Blacklist migration: Post ID '%d' (Title: '%s') has no 'wprss_permalink' meta.", $id, $name));
			}
			$item = new RejectedItem( $url, null, $name );

			if ( $dryRun ) {
				return Result::Ok( $item );
			}

			$addResult = $this->store->add( $item );
			if ( $addResult->isErr() ) {
				Logger::error(
					sprintf(
						'Failed to add V4 blacklist item (original ID: %d, URL: %s, Name: %s) to V5 store: %s',
						$id,
						$url,
						$name,
						$addResult->error()->getMessage()
					)
				);
			}
			return $addResult;
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error migrating V4 blacklist item with ID %d: %s', $id, $e->getMessage() ) );
			return Result::Err( $e );
		}
	}
}
