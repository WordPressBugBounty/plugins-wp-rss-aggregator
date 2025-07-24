<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use WP_Post;
use RebelCode\Aggregator\Core\Utils\WpUtils;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Store\DisplaysStore;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Display;
use Generator;

class V4TemplatesMigrator {

	private DisplaysStore $store;

	public function __construct( DisplaysStore $store ) {
		$this->store = $store;
	}

	public function getCount(): int {
		$counts = wp_count_posts( 'wprss_feed_template' );
		return array_sum( (array) $counts );
	}

	/** @return Generator<Source> */
	public function migrateAll( bool $dryRun = false ): Generator {
		try {
			$posts = WpUtils::batchQueryPosts( 100, array( 'post_type' => 'wprss_feed_template' ) );

			foreach ( $posts as $post ) {
				$result = $this->migrate( $post->ID, $dryRun );
				// Error logging is handled within the migrate method if it returns an Err.
				yield $result; // Yield the Result object
				usleep( 50_000 );
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4 template migration in migrateAll: %s', $e->getMessage() ) );
			// Depending on desired behavior, you might want to yield an error Result or re-throw
		}
	}

	/** @return Result<Display> */
	public function migrate( int $id, bool $dryRun = false ): Result {
		try {
			$srcPost = get_post( $id );
			if ( ! ( $srcPost instanceof WP_Post ) ) {
				$errMsg = sprintf( "V4 Template migration: WordPress post with ID '%d' does not exist or is not a WP_Post object.", $id );
				Logger::error( $errMsg );
				return Result::Err( $errMsg );
			}

			$name = $srcPost->post_title ?: '(no title)'; // Ensure name is not empty

			$meta = get_post_meta( $id );
			$meta = $this->normalizeMeta( $meta );

			$display = $this->convert( $name, $meta );
			$display = apply_filters( 'wpra.v4Migration.display.converted', $display, $meta );
			$display->v4Slug = $srcPost->post_name;

			if ( $dryRun ) {
				return Result::Ok( $display );
			}

			$result = $this->store->save( $display );
			if ( $result->isOk() ) {
				$newDisplay = $result->get();
				$newDisplay = apply_filters( 'wpra.v4Migration.display.inserted', $newDisplay, $id, $meta );
				update_post_meta( $id, 'wpra_v5_id', $newDisplay->id );

				// If this was the v4 default template, set it as the v5 default
				if ( $newDisplay->v4Slug === 'default' ) {
					update_option( 'wpra_default_display_id', $newDisplay->id );
				}

				return Result::Ok( $newDisplay );
			} else {
				Logger::error(
					sprintf(
						'Failed to save V4 migrated template (original ID: %d, Name: %s) to V5 store: %s',
						$id,
						$name,
						$result->error() ? $result->error()->getMessage() : 'Unknown error during save'
					)
				);
				return $result;
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error migrating V4 template with original ID %d: %s', $id, $e->getMessage() ) );
			return Result::Err( $e );
		}
	}

	/**
	 * Converts v4 template meta data into a v5 display instance.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function convert( string $name, array $meta ): Display {
		$display = new Display( null );

		$v5Id = (int) ( $meta['wpra_v5_id'] ?? 0 );
		if ( $v5Id > 0 ) {
			$display = $this->store->getById( $v5Id )->getOr( $display );
		}

		$display->name = $name;

		$type = is_array( $meta['wprss_template_type'] )
		? $meta['wprss_template_type'][0]
		: ( $meta['wprss_template_type'] ?? 'list' );
		switch ( $type ) {
			default:
			case '__built_in':
			case 'list':
				$display->settings->layout = 'list';
				break;
			case 'grid':
			case 'et':
				$display->settings->layout = $type;
				break;
		}

		$options = $meta['wprss_template_options'] ?? array();

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$limit = $options['limit'] ?? 0;
		if ($limit === 0 || $limit === '') {
			$limit = 999;
		}
		$display->settings->numItems = (int) $limit;
		$display->settings->titleMaxLength = (int) ( $options['title_max_length'] ?? 0 ) ?: null;
		$display->settings->linkTitles = (bool) ( $options['title_is_link'] ?? true );
		$display->settings->enableSources = (bool) ( $options['source_enabled'] ?? true );
		$display->settings->sourcePrefix = $options['source_prefix'] ?? __( 'Source:', 'wp-rss-aggregator' );
		$display->settings->linkSource = (bool) ( $options['source_is_link'] ?? true );
		$display->settings->enableDates = (bool) ( $options['date_enabled'] ?? true );
		$display->settings->datePrefix = $options['date_prefix'] ?? __( 'Published on:', 'wp-rss-aggregator' );
		$display->settings->dateFormat = $options['date_format'] ?? 'Y-m-d';
		$display->settings->useRelDateFormat = (bool) ( $options['date_use_time_ago'] ?? false );
		$display->settings->enableAuthors = (bool) ( $options['author_enabled'] ?? false );
		$display->settings->authorPrefix = $options['author_prefix'] ?? __( 'By', 'wp-rss-aggregator' );
		$display->settings->enablePagination = (bool) ( $options['pagination'] ?? 0 );
		$display->settings->paginationStyle = isset( $options['pagination_type'] ) && 'default' !== $options['pagination_type'] ? $options['pagination_type'] : 'older_newer';
		$display->settings->enableBullets = (bool) ( $options['bullets_enabled'] ?? true );
		$display->settings->bulletStyle = $options['bullet_type'] ?? 'default';
		$display->settings->enableAudioPlayer = (bool) ( $options['audio_player_enabled'] ?? 'default' );
		$display->settings->linksNoFollow = (bool) ( $options['links_nofollow'] ?? true );
		$display->settings->linkToEmbeds = (bool) ( $options['link_to_embed'] ?? true );
		$display->settings->htmlClass = $options['custom_css_classname'] ?? '';

		$linksBehavior = $options['links_behavior'] ?? 'blank';
		switch ( $linksBehavior ) {
			default:
			case 'blank':
			case '_blank':
				$display->settings->linkTarget = '_blank';
				break;
			case 'self':
			case '_self':
				$display->settings->linkTarget = '_self';
				break;
			case 'lightbox':
				$display->settings->linkTarget = 'lightbox';
				break;
		}

		return $display;
	}

	private function normalizeMeta( array $meta ): array {
		$type = $meta['wprss_template_type'] ?? 'list';
		$options = $meta['wprss_template_options'][0] ?? array();
		$options = maybe_unserialize( $options );

		$options = wp_parse_args(
			$options,
			array(
				'limit' => 15,
				'pagination' => false,
				'links_behavior' => 'blank',
				'links_nofollow' => true,
				'link_to_embed' => false,
				'custom_css_classname' => '',
				'className' => '',
				'title_max_length' => 0,
				'title_is_link' => true,
				'pagination_type' => 'default',
				'source_enabled' => true,
				'source_prefix' => __( 'Source:', 'wp-rss-aggregator' ),
				'source_is_link' => true,
				'author_enabled' => false,
				'author_prefix' => __( 'By', 'wp-rss-aggregator' ),
				'date_enabled' => true,
				'date_prefix' => __( 'Published on:', 'wp-rss-aggregator' ),
				'date_format' => 'Y-m-d',
				'date_use_time_ago' => false,
				'bullets_enabled' => true,
				'bullet_type' => 'default',
				'audio_player_enabled' => false,
			)
		);

		return array(
			'wprss_template_type' => $type,
			'wprss_template_options' => $options,
		);
	}
}
