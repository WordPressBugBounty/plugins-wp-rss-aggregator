<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use wpdb;
use WP_Post;
use WP_CLI;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\IrPost\IrAuthor;
use RebelCode\Aggregator\Core\ImportedPost;
use Generator;
use DateTime;

class V4ItemMigrator {

	public function getCount(): int {
		/** @var wpdb $wpdb */
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} as p
            WHERE EXISTS (SELECT post_id FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = 'wprss_feed_id') AND
                  NOT EXISTS (SELECT post_id FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_wpra_source')"
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}
		return (int) $count;
	}

	/** @return Generator<WP_Post> */
	public function migrateAll( bool $dryRun = false ): Generator {
		global $wpdb;

		$failed_ids = array();
		$batchSize  = 100;
		$lastId     = 0;

		while ( true ) {
			$sql = $wpdb->prepare(
				"
				SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m1 ON m1.post_id = p.ID AND m1.meta_key = 'wprss_feed_id'
				LEFT JOIN {$wpdb->postmeta} m2 ON m2.post_id = p.ID AND m2.meta_key = '_wpra_source'
				WHERE m2.post_id IS NULL AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d
				",
				$lastId,
				$batchSize
			);

			$post_ids = $wpdb->get_col( $sql );

			if ( empty( $post_ids ) ) {
				break;
			}

			Logger::info(
				sprintf(
					'Processing batch with %d posts (IDs %d → %d)',
					count( $post_ids ),
					min( $post_ids ),
					max( $post_ids )
				)
			);

			foreach ( $post_ids as $postId ) {
				if ( class_exists( 'WP_CLI' ) && is_callable( 'WP_CLI::log' ) ) {
					WP_CLI::log( "-- V4ItemMigrator: Processing post ID: $postId --" );
				}

				try {
					$meta = get_post_meta( $postId );

					$postType = get_post_field( 'post_type', $postId );
					if ( class_exists( 'WP_CLI' ) ) {
						WP_CLI::log( "-- V4ItemMigrator: Post ID: $postId, Type: $postType --" );
					}

					$flattenedMeta = array_map(
						static fn( $arr ) => $arr[0],
						$meta
					);

					$newMeta = $this->migrate( $postType, $flattenedMeta );

					if ( ! $dryRun ) {
						if ( class_exists( 'WP_CLI' ) ) {
							WP_CLI::log( "---- V4ItemMigrator: Updating meta for post ID: $postId ----" );
						}
						foreach ( $newMeta as $key => $val ) {
							update_post_meta( $postId, $key, $val );
						}
					} else {
						if ( class_exists( 'WP_CLI' ) ) {
							WP_CLI::log( "---- V4ItemMigrator: Dry run for post ID: $postId ----" );
						}
					}
				} catch ( \Throwable $e ) {
					Logger::error(
						sprintf(
							'Failed to migrate post ID: %d — %s',
							$postId,
							$e->getMessage()
						)
					);
					if ( class_exists( 'WP_CLI' ) && is_callable( 'WP_CLI::error' ) ) {
						WP_CLI::error( "V4ItemMigrator: Error processing post ID $postId: " . $e->getMessage() );
					}
					$failed_ids[] = $postId;
				}

				$lastId = $postId;

				if ( defined( 'WP_CLI' ) && WP_CLI::get_config( 'debug' ) ) {
					WP_CLI::log( "-- V4ItemMigrator: Yielding post ID: $postId, Last ID: $lastId --" );
				}

				yield $postId;
			}
		}

		if ( ! empty( $failed_ids ) ) {
			update_option( 'wpra_v4_failed_migrated_item_ids', $failed_ids );
			Logger::warning(
				sprintf(
					'Migration completed with %d failed posts.',
					count( $failed_ids )
				)
			);
		} else {
			Logger::info( 'Migration completed successfully with no errors.' );
		}
	}

	public function migrate( string $postType, array $meta ): array {
		$isFeedItem = $postType === 'wprss_feed_item';

		$v4SrcId = $meta['wprss_feed_id'] ?? '';
		$v5SrcId = get_post_meta( $v4SrcId, 'wpra_v5_id', true );
		if ( is_numeric( $v5SrcId ) ) {
			$v5SrcId = (int) $v5SrcId;
		} else {
			$v5SrcId = $v4SrcId;
		}

		$importTs = (int) ( $meta['wprss_ftp_import_date'] ?? time() );
		$importDate = ( new DateTime() )->setTimestamp( $importTs );

		$authorName = $meta['wprss_item_author'] ?? null;
		$author = null;

		// Attempt to get author from WordPress user ID first
		$authorId = $meta['wprss_item_author_id'] ?? null;
		if (!empty($authorId) && is_numeric($authorId)) {
			$author = IrAuthor::fromWpUserId((int) $authorId);
		}

		// If no WordPress user, try to use the author name from feed item
		if (!$author && !empty($authorName)) {
			// We don't have email or URL from V4 item meta directly for authors not mapped to WP users
			// IrAuthor will generate an email if only name is provided.
			$author = new IrAuthor(null, $authorName);
		}

		$result = array(
			ImportedPost::GUID => $meta['wprss_item_guid'] ?? '',
			ImportedPost::URL => $meta['wprss_item_permalink'] ?? '',
			ImportedPost::SOURCE => $v5SrcId,
			ImportedPost::SOURCE_NAME => $meta['wprss_item_source_name'] ?? '',
			ImportedPost::SOURCE_URL => $meta['wprss_item_source_url'] ?? '',
			ImportedPost::IMPORT_DATE => $importDate->format( DateTime::ATOM ),
			ImportedPost::FT_IMAGE_URL => '',
			ImportedPost::IS_YT => $meta['wprss_item_is_yt'] ?? '',
			ImportedPost::YT_VIEWS => 0,
			ImportedPost::YT_VIDEO_ID => 0,
			ImportedPost::YT_EMBED_URL => $meta['wprss_item_yt_embed_url'] ?? '',
			ImportedPost::AUDIO_URL => $meta['wprss_item_audio'] ?? '',
			ImportedPost::ENCLOSURE_URL => $isFeedItem
				? ( $meta['wprss_item_enclosure'] ?? '' )
				: ( $meta['wprss_ftp_enclosure_link'] ?? '' ),
			ImportedPost::AUTHOR_NAME => $author ? $author->name : '',
			ImportedPost::AUTHOR_EMAIL => $author ? $author->email : '',
			ImportedPost::AUTHOR_URL => $author ? $author->link : '',
		);

		return $result;
	}
}
