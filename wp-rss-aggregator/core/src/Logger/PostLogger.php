<?php

namespace RebelCode\Aggregator\Core\Logger;

use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Database;
use Psr\Log\LogLevel;
use Psr\Log\AbstractLogger;

class PostLogger extends AbstractLogger {

	private const CPT_SLUG = 'wpra-logger';
	private const CTX_META_KEY = '_log_ctx_';

	/** The date ranges that the log can be filtered by, mapped to the start of each range. */
	public const DATE_RANGES = array(
		'today' => 'today',
		'24hours' => '-24 hours',
		'3days' => '-3 days',
		'7days' => '-7 days',
		'14days' => '-14 days',
		'30days' => '-30 days',
	);
	private Database $db;
	private bool $enabled;

	public function __construct( Database $db, bool $enabled ) {
		$this->db = $db;
		$this->enabled = $enabled;
	}

	public function log( $level, $message, array $context = array() ) {
		if ( ! $this->enabled ) {
			return;
		}
		$post_status = $this->get_post_status_for_level( $level );

		$post_data = array(
			'post_type'    => self::CPT_SLUG,
			'post_title'   => sanitize_text_field( mb_substr( $message, 0, 20 ) ),
			'post_content' => wp_kses_post( $message ),
			'post_status'  => $post_status,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
			update_post_meta( $post_id, self::CTX_META_KEY, json_encode( $context ) );
			update_post_meta( $post_id, '_log_level', sanitize_text_field( $level ) );
		}
	}

	private function get_all_log_statuses(): array {
		return array(
			LogLevel::EMERGENCY => 'wpra_emergency',
			LogLevel::ALERT     => 'wpra_alert',
			LogLevel::CRITICAL  => 'wpra_critical',
			LogLevel::ERROR     => 'wpra_error',
			LogLevel::WARNING   => 'wpra_warning',
			LogLevel::NOTICE    => 'wpra_notice',
			LogLevel::INFO      => 'wpra_info',
			LogLevel::DEBUG     => 'wpra_debug',
		);
	}

	private function get_post_status_for_level( string $level ): string {
		$status_map = $this->get_all_log_statuses();
		return $status_map[ strtolower( $level ) ] ?? 'wpra_info';
	}

	/**
	 * Builds the `WP_Query` arguments for reading log entries.
	 *
	 * @since 5.7.0
	 *
	 * @param array{level?: string, source?: int, search?: string, since?: string} $filters Optional filters.
	 * @param int|null                                            $num The number of entries per page, or null for all.
	 * @param int                                                 $page The page number.
	 * @return array<string,mixed> The query arguments.
	 */
	public function buildQueryArgs( array $filters = array(), ?int $num = null, int $page = 1 ): array {
		$statuses = $this->get_all_log_statuses();

		$level = strtolower( (string) ( $filters['level'] ?? '' ) );
		if ( $level === LogLevel::ERROR ) {
			// The level filter offers Error as the most severe choice, so it also matches the levels above it.
			$postStatus = array(
				$statuses[ LogLevel::EMERGENCY ],
				$statuses[ LogLevel::ALERT ],
				$statuses[ LogLevel::CRITICAL ],
				$statuses[ LogLevel::ERROR ],
			);
		} else {
			$postStatus = isset( $statuses[ $level ] ) ? array( $statuses[ $level ] ) : array_values( $statuses );
		}

		$args = array(
			'post_type'      => self::CPT_SLUG,
			'post_status'    => $postStatus,
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'posts_per_page' => $num === null ? -1 : max( 1, $num ),
			'paged'          => max( 1, $page ),
			'no_found_rows'  => false,
		);

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( $search !== '' ) {
			$args['s'] = $search;
		}

		$range = (string) ( $filters['since'] ?? '' );
		if ( isset( self::DATE_RANGES[ $range ] ) ) {
			$args['date_query'] = array(
				array(
					'column'    => 'post_date',
					'after'     => self::DATE_RANGES[ $range ],
					'inclusive' => true,
				),
			);
		}

		$source = (int) ( $filters['source'] ?? 0 );
		if ( $source > 0 ) {
			// The source ID is part of the JSON context stored for every entry logged during an import, so matching
			// it there also covers entries written before the source filter existed. Older entries could store the
			// whole source object, whose JSON starts with its ID.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only, paginated log view.
			$args['meta_query'] = array(
				array(
					'key'     => self::CTX_META_KEY,
					'value'   => sprintf( '"source":([{]"id":)?"?%d"?[,}]', $source ),
					'compare' => 'REGEXP',
				),
			);
		}

		return $args;
	}

	/**
	 * Gets log entries, newest first.
	 *
	 * @since 5.7.0 Accepts filters, returns the entry and source IDs, and decodes the stored context.
	 *
	 * @param int|null                                            $num The number of entries per page, or null for all.
	 * @param int                                                 $page The page number.
	 * @param array{level?: string, source?: int, search?: string, since?: string} $filters Optional filters.
	 * @return Result<list<array{id:int,date:string,level:string,message:string,context:string,source:int|null}>>
	 */
	public function getLogs( ?int $num = null, int $page = 1, array $filters = array() ): Result {
		try {
			$args = $this->buildQueryArgs( $filters, $num, $page );
			// The total comes from getCount(), so this query can skip counting the found rows.
			$args['no_found_rows'] = true;

			$query = new \WP_Query( $args );
			$logs  = array();

			foreach ( $query->posts as $post ) {
				$context = $this->decodeContext( get_post_meta( $post->ID, self::CTX_META_KEY, true ) );

				$logs[] = array(
					'id'      => (int) $post->ID,
					'date'    => $post->post_date,
					'level'   => $this->getLevelForPost( $post ),
					'message' => $post->post_content,
					'context' => (string) wp_json_encode( (object) $context ),
					'source'  => $this->getSourceFromContext( $context ),
				);
			}

			return Result::Ok( $logs );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	/**
	 * Counts the log entries that match the given filters.
	 *
	 * @since 5.7.0 Accepts filters and counts only log entries with a log level status.
	 *
	 * @param array{level?: string, source?: int, search?: string, since?: string} $filters Optional filters.
	 * @return Result<int>
	 */
	public function getCount( array $filters = array() ): Result {
		try {
			$args = $this->buildQueryArgs( $filters, 1, 1 );
			$args['fields'] = 'ids';
			$args['update_post_meta_cache'] = false;
			$args['update_post_term_cache'] = false;

			$query = new \WP_Query( $args );

			return Result::Ok( (int) $query->found_posts );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	/**
	 * Deletes all log entries and their meta data.
	 *
	 * @since 5.7.0 Also deletes the entries' meta data and returns the number of deleted entries.
	 *
	 * @return Result<int> The number of deleted log entries.
	 */
	public function deleteAll(): Result {
		try {
			$posts = $this->db->wpdb->posts;
			$postmeta = $this->db->wpdb->postmeta;

			$this->db->query(
				"DELETE meta FROM {$postmeta} AS meta
				INNER JOIN {$posts} AS post ON post.ID = meta.post_id
				WHERE post.post_type = %s",
				array( self::CPT_SLUG )
			);

			$num = $this->db->query(
				"DELETE FROM {$posts} WHERE post_type = %s",
				array( self::CPT_SLUG )
			);
			$this->flushQueryCache();

			return Result::Ok( (int) $num );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	/**
	 * Deletes the log entries older than a number of days, and their meta data.
	 *
	 * @since 5.7.0
	 *
	 * @param int $days The number of days of entries to keep.
	 * @return Result<int> The number of deleted log entries.
	 */
	public function deleteOlderThan( int $days ): Result {
		if ( $days < 1 ) {
			return Result::Ok( 0 );
		}

		try {
			$posts = $this->db->wpdb->posts;
			$postmeta = $this->db->wpdb->postmeta;
			// Entries are saved with the site's local time in `post_date`, so I compare with the same clock.
			$cutoff = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

			$this->db->query(
				"DELETE meta FROM {$postmeta} AS meta
				INNER JOIN {$posts} AS post ON post.ID = meta.post_id
				WHERE post.post_type = %s AND post.post_date < %s",
				array( self::CPT_SLUG, $cutoff )
			);

			$num = $this->db->query(
				"DELETE FROM {$posts} WHERE post_type = %s AND post_date < %s",
				array( self::CPT_SLUG, $cutoff )
			);
			$this->flushQueryCache();

			return Result::Ok( (int) $num );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	/**
	 * Marks the cached post queries as stale after entries are deleted with SQL.
	 *
	 * `WP_Query` caches its results until the posts' `last_changed` value changes, and deleting rows directly
	 * doesn't change it, so the log would keep listing the deleted entries.
	 *
	 * @since 5.7.0
	 */
	private function flushQueryCache(): void {
		wp_cache_delete( 'last_changed', 'posts' );
	}

	/**
	 * Gets the level of a log entry, falling back to its post status when the level meta is missing.
	 *
	 * @since 5.7.0
	 *
	 * @param \WP_Post $post The log post.
	 * @return string The PSR log level, or an empty string if it cannot be determined.
	 */
	public function getLevelForPost( $post ): string {
		$level = (string) get_post_meta( $post->ID, '_log_level', true );

		if ( $level === '' ) {
			$level = (string) array_search( $post->post_status, $this->get_all_log_statuses(), true );
		}

		return $level;
	}

	/**
	 * Decodes the stored JSON context of a log entry.
	 *
	 * @since 5.7.0
	 *
	 * @param mixed $value The stored meta value.
	 * @return array<string,mixed> The context, or an empty array if it is missing or invalid.
	 */
	public function decodeContext( $value ): array {
		if ( ! is_string( $value ) || $value === '' ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Gets the source ID from a log entry's context.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $context The decoded context.
	 * @return int|null The source ID, or null if the entry is not tied to a source.
	 */
	public function getSourceFromContext( array $context ): ?int {
		$source = $context['source'] ?? null;

		// Older entries could store the whole source object instead of its ID.
		if ( is_array( $source ) ) {
			$source = $source['id'] ?? null;
		}

		if ( is_numeric( $source ) && (int) $source > 0 ) {
			return (int) $source;
		}

		return null;
	}
}
