<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use WP_Post;
use RebelCode\Aggregator\Core\Utils\WpUtils;
use RebelCode\Aggregator\Core\Utils\Size;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Bools;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Source\ScheduleFactory;
use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Source\ImportOrder;
use RebelCode\Aggregator\Core\Source\FtImageToUse;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Logger;
use Generator;
use DateTime;

class V4SourceMigrator {

	private SourcesStore $store;
	private array $coreSettings;

	/** @param array<string,mixed> $v4Settings */
	public function __construct( SourcesStore $store, array $v4Settings ) {
		$this->store = $store;
		$this->coreSettings = $v4Settings;
	}

	public function getCount(): int {
		/** @var wpdb $wpdb */
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} as p
            WHERE post_type = 'wprss_feed' AND post_status != 'auto-draft'"
		);

		if ( ! is_numeric( $count ) ) {
			return 0;
		}
		return (int) $count;
	}

	public function migrateAll( bool $dryRun = false ): Generator {
		try {
			$posts = WpUtils::batchQueryPosts(
				100,
				array(
					'post_type' => 'wprss_feed',
					'post_status' => array( 'publish', 'draft' ),
				)
			);

			foreach ( $posts as $post ) {
				$result = $this->migrate( $post->ID, $dryRun );
				// Error logging is handled within the migrate method if it returns an Err.
				yield $result; // Yield the Result object
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4 source migration in migrateAll: %s', $e->getMessage() ) );
		}
	}

	/** @return Result<Source> */
	public function migrate( int $id, bool $dryRun = false ): Result {
		try {
			$srcPost = get_post( $id );
			if ( ! ( $srcPost instanceof WP_Post ) ) {
				$errMsg = sprintf( "V4 Source migration: WordPress post with ID '%d' does not exist or is not a WP_Post object.", $id );
				Logger::error( $errMsg );
				return Result::Err( $errMsg );
			}

			$postId = $srcPost->ID; // Use $postId to avoid confusion with function param $id
			$title = $srcPost->post_title ?: '(no title)';
			$slug = $srcPost->post_name ?: sanitize_title( $srcPost->post_title );
			$meta = get_post_meta( $postId );
			foreach ( $meta as $key => $list ) {
				$meta[ $key ] = $list[0];
			}

			$source = $this->convert( $postId, $slug, $title, $meta );
			$source = apply_filters( 'wpra.v4Migration.source.converted', $source, $meta, $dryRun );

			if ( $dryRun ) {
				return Result::Ok( $source );
			}

			$result = $this->store->save( $source );
			if ( $result->isOk() ) {
				$newSource = $result->get();
				$newSource = apply_filters( 'wpra.v4Migration.source.inserted', $newSource, $postId );
				update_post_meta( $postId, 'wpra_v5_id', $newSource->id );
				return Result::Ok( $newSource );
			} else {
				Logger::error(
					sprintf(
						'Failed to save V4 migrated source (original ID: %d, Name: %s) to V5 store: %s',
						$postId,
						$title,
						$result->error() ? $result->error()->getMessage() : 'Unknown error during save'
					)
				);
				return $result;
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error migrating V4 source with original ID %d: %s', $id, $e->getMessage() ) );
			return Result::Err( $e );
		}
	}

	/**
	 * Converts v4 source meta data into a v5 source instance.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function convert( int $id, string $slug, string $name, array $meta ): Source {
		$src = new Source( null );
		$src->v4Id = $id;
		$src->v4Slug = $slug;

		$v5Id = (int) ( $meta['wpra_v5_id'] ?? 0 );
		if ( $v5Id > 0 ) {
			$src = $this->store->getById( $v5Id )->getOr( $src );
		}

		$src->name = $name;
		$src->url = $meta['wprss_url'] ?? '';

		$state = $meta['wprss_state'] ?? '';
		$wpSchedule = $meta['wprss_update_interval'] ?? '';
		$updateTime = $meta['wprss_update_time'] ?? '';
		$activate = $meta['wprss_activate_feed'] ?? '';
		$pause = $meta['wprss_pause_feed'] ?? '';

		$src->isActive = empty( $state ) || $state == 'active';
		$src->schedule = $this->convertSchedule( $wpSchedule, $updateTime );
		$src->settings->futureActivate = $this->convertFuture( $activate );
		$src->settings->futurePause = $this->convertFuture( $pause );
		$srcLimit = $meta['wprss_limit'] ?? '0';
		$globalLimit = $this->coreSettings['limit_feed_items_imported'] ?? 0;
		$ageLimit = (int) ( $meta['wprss_age_limit'] ?? '0' );
		$ageUnit = $meta['wprss_age_unit'] ?? 'days';

		$src->settings->importLimit = (int) ( $srcLimit ?: $globalLimit ?: 0 );
		$src->settings->ageLimit = $ageLimit;
		$src->settings->ageLimitUnit = $ageUnit;

		$importOrder = $this->coreSettings['feed_items_import_order'] ?? 'latest';
		$src->settings->importOrder = ( strtolower( $importOrder ) === 'oldest' )
			? ImportOrder::ASC
			: ImportOrder::DESC;

		$uniqueTitles = $meta['wprss_unique_titles'] ?? '0';
		$uniqueTitles = $uniqueTitles === '' ? ( $this->coreSettings['unique_titles'] ?? false ) : $uniqueTitles;
		$src->settings->uniqueTitles = Bools::normalize( $uniqueTitles );

		$linkToEnclosure = $meta['wprss_enclosure'] ?? '0';
		$src->settings->linkToEnclosure = Bools::normalize( $linkToEnclosure );
		$is_feed_item = isset( $meta['wprss_ftp_post_type'] ) && 'wprss_feed_item' === trim( $meta['wprss_ftp_post_type'] );
		$ftImage = $is_feed_item ? $meta['wprss_import_ft_images'] ?? '' : $meta['wprss_ftp_featured_image'] ?? '';
		$saveImages = $meta['wprss_ftp_save_images_locally'] ?? '0';
		$saveAllSizes = $meta['wprss_ftp_save_all_image_sizes'] ?? '0';
		$imgMinWidth  = $meta[ $is_feed_item ? 'wprss_image_min_width' : 'wprss_ftp_image_min_width' ] ?? '0';
		$imgMinHeight = $meta[ $is_feed_item ? 'wprss_image_min_height' : 'wprss_ftp_image_min_height' ] ?? '0';
		$useFtImage = $is_feed_item ? (bool) $meta['wprss_import_ft_images'] ?? '' : $meta['wprss_ftp_use_featured_image'] ?? '0';
		$removeFtImage = $meta['wprss_ftp_remove_ft_image'] ?? '0';
		$mustHaveFtImage = $meta['wprss_ftp_must_have_ft_image'] ?? '0';

		$src->settings->downloadImages = Bools::normalize( $saveImages );
		$src->settings->downloadAllImgSizes = Bools::normalize( $saveAllSizes );
		$src->settings->assignFtImage = Bools::normalize( $useFtImage );
		$src->settings->deDupeFtImage = Bools::normalize( $removeFtImage );
		$src->settings->mustHaveFtImage = Bools::normalize( $mustHaveFtImage );
		$src->settings->minImageSize = new Size( (int) $imgMinWidth, (int) $imgMinHeight );
		$src->settings->fallbackFtImageId = get_post_thumbnail_id( $id );
		switch ( strtolower( $ftImage ) ) {
			default:
			case 'first':
			case 'content':
				$src->settings->whichFtImage = FtImageToUse::CONTENT_FIRST;
				break;
			case 'last':
				$src->settings->whichFtImage = FtImageToUse::CONTENT_LAST;
				break;
			case 'thumb':
			case 'media':
				$src->settings->whichFtImage = FtImageToUse::MEDIA;
				break;
			case 'enclosure':
				$src->settings->whichFtImage = FtImageToUse::ENCLOSURE;
				break;
			case 'default':
			case 'fallback':
				$src->settings->whichFtImage = FtImageToUse::USER;
				break;
			case 'auto':
				$src->settings->whichFtImage = FtImageToUse::AUTO;
				break;
			case 'itunes':
				$src->settings->whichFtImage = FtImageToUse::ITUNES;
				break;
			case '':
				$src->settings->whichFtImage = FtImageToUse::NO_IMAGE;
				break;
		}

		$allowFuture = $this->coreSettings['schedule_future_items'] ?? false;
		$src->settings->allowFutureDates = Bools::normalize( $allowFuture );

		return $src;
	}

	public function convertSchedule( string $wpSchedule, string $updateTime ): ?Schedule {
		if ( $wpSchedule === 'global' ) {
			$wpSchedule = $this->coreSettings['cron_interval'] ?? 'hourly';
		}
		return ScheduleFactory::fromWpSchedule( $wpSchedule, $updateTime );
	}

	public function convertFuture( string $timeStr ): ?DateTime {
		$timestamp = $this->v4StrToTime( $timeStr );
		if ( $timestamp === 0 ) {
			return null;
		}
		$future = new DateTime();
		$future->setTimestamp( $timestamp );
		return $future;
	}

	/** Copied from v4. Could probably be replaced with the DateTime ctor. */
	private function v4StrToTime( $str ): int {
		if ( empty( $str ) ) {
			return 0;
		}
		$parts = explode( ' ', $str );
		$date = explode( '/', $parts[0] );
		$time = explode( ':', $parts[1] );
		return mktime(
			(int) $time[0],
			(int) $time[1],
			(int) $time[2],
			(int) $date[1],
			(int) $date[0],
			(int) $date[2]
		);
	}
}
