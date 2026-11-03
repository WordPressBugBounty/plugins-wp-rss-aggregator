<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Settings;
use RebelCode\Aggregator\Core\Logger\PostLogger;

class RpcLoggerHandler {

	/** The number of entries read per query when exporting the whole log. */
	private const PRINT_BATCH_SIZE = 500;

	private PostLogger $logger;
	private Settings $settings;

	public function __construct( PostLogger $logger, Settings $settings ) {
		$this->logger = $logger;
		$this->settings = $settings;
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'loggerEnabled' );
	}

	public function start(): string {
		$ok = $this->settings->patch( array( 'loggerEnabled' => true ) )->save();
		if ( $ok ) {
			return 'Started logging.';
		}
		return 'Already logging.';
	}

	public function stop(): string {
		$ok = $this->settings->patch( array( 'loggerEnabled' => false ) )->save();
		if ( $ok ) {
			return 'Stopped logging.';
		}
		return 'Already stopped.';
	}

	/**
	 * Gets the log entries as plain text, one entry per line.
	 *
	 * @since 5.7.0 Accepts filters, includes the source ID, and returns every matching entry when `$num` is null.
	 *
	 * @param int|null            $num The number of entries per page, or null for all matching entries.
	 * @param int                 $page The page number.
	 * @param array<string,mixed> $filters Optional filters: `level`, `source`, `search` and `since`.
	 * @return string The log text.
	 */
	public function print( ?int $num = null, int $page = 1, array $filters = array() ): string {
		$filters = $this->normalizeFilters( $filters );
		$lines = array();

		if ( $num !== null ) {
			foreach ( $this->logger->getLogs( $num, $page, $filters )->get() as $row ) {
				$lines[] = $this->formatLine( $row );
			}

			return join( "\r\n", $lines );
		}

		// I read every matching entry in batches, so a large log does not load all of its posts at once.
		$batch = 1;
		do {
			$logs = $this->logger->getLogs( self::PRINT_BATCH_SIZE, $batch++, $filters )->get();

			foreach ( $logs as $row ) {
				$lines[] = $this->formatLine( $row );
			}

			$this->flushPostCache( $logs );
		} while ( count( $logs ) === self::PRINT_BATCH_SIZE );

		return join( "\r\n", $lines );
	}

	/**
	 * Gets a page of log entries and the number of entries that match the filters.
	 *
	 * @since 5.7.0 Accepts filters, and `$num` can be null to get all matching entries.
	 *
	 * @param int|null            $num The number of entries per page, or null for all matching entries.
	 * @param int                 $page The page number.
	 * @param array<string,mixed> $filters Optional filters: `level`, `source`, `search` and `since`.
	 * @return array{logs: array<int,array<string,mixed>>, total: int}
	 */
	public function getList( ?int $num = 20, int $page = 1, array $filters = array() ): array {
		$filters = $this->normalizeFilters( $filters );

		$logs = $this->logger->getLogs( $num, $page, $filters )->get();
		$total = $this->logger->getCount( $filters )->get();

		return array(
			'logs' => $logs,
			'total' => $total,
		);
	}

	public function clear(): string {
		$num = $this->logger->deleteAll()->get();
		return sprintf( 'Deleted %d log messages', $num );
	}

	/**
	 * Keeps only the supported filters and casts them to their expected types.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $filters The raw filters from the request.
	 * @return array{level?: string, source?: int, search?: string, since?: string}
	 */
	public function normalizeFilters( array $filters ): array {
		$result = array();

		$level = $filters['level'] ?? null;
		if ( is_string( $level ) && trim( $level ) !== '' ) {
			$result['level'] = strtolower( trim( $level ) );
		}

		$source = $filters['source'] ?? null;
		if ( is_numeric( $source ) && (int) $source > 0 ) {
			$result['source'] = (int) $source;
		}

		$search = $filters['search'] ?? null;
		if ( is_string( $search ) && trim( $search ) !== '' ) {
			$result['search'] = trim( $search );
		}

		$since = $filters['since'] ?? null;
		if ( is_string( $since ) && isset( PostLogger::DATE_RANGES[ $since ] ) ) {
			$result['since'] = $since;
		}

		return $result;
	}

	/**
	 * Drops a batch of log posts and their meta from the object cache, so an export's memory use stays flat.
	 *
	 * @since 5.7.0
	 *
	 * @param array<int,array<string,mixed>> $logs The log entries of the batch.
	 */
	private function flushPostCache( array $logs ): void {
		foreach ( $logs as $row ) {
			if ( ! empty( $row['id'] ) ) {
				wp_cache_delete( (int) $row['id'], 'posts' );
				wp_cache_delete( (int) $row['id'], 'post_meta' );
			}
		}
	}

	/**
	 * Formats a log entry as a line of text.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $row The log entry.
	 * @return string The formatted line.
	 */
	public function formatLine( array $row ): string {
		$source = isset( $row['source'] ) && $row['source'] ? sprintf( 'source: #%d', $row['source'] ) : 'source: -';

		return sprintf(
			'[%s] %s | %s | %s',
			$row['date'] ?? '',
			strtoupper( (string) ( $row['level'] ?? '' ) ),
			$source,
			str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) ( $row['message'] ?? '' ) )
		);
	}
}
