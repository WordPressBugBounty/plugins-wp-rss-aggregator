<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use Throwable;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Progress;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Exception\NotFoundException;
use RebelCode\Aggregator\Core\Database;
use Generator;
use DateTime;

class ProgressStore {

	public const ID = 'id';
	public const CURRENT = 'current';
	public const TOTAL = 'total';
	public const STARTED = 'started';
	public const MESSAGE = 'message';

	public Database $db;
	public string $table;

	public function __construct( Database $db, string $table ) {
		$this->db = $db;
		$this->table = $table;
	}

	/** @return Result<Progress[]> */
	public function getList( ?int $num = null, int $page = 1 ): Result {
		$pagination = $this->db->pagination( $num, $page );

		try {
			$rows = $this->db->getResults( "SELECT * FROM {$this->table} {$pagination}" );
			$items = Arrays::gmap( $rows, fn ( array $row ) => $this->rowToProgress( $row ) );

			return Result::Ok( $items );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Gets a progress row by its ID.
	 *
	 * @param string $id The ID of the progress to get.
	 * @return Result<Progress> The result containing the progress.
	 */
	public function getById( string $id ): Result {
		$row = $this->db->getRow( "SELECT * FROM {$this->table} WHERE `id` = %s", array( $id ) );

		if ( $row === null ) {
			return Result::Err(
				new NotFoundException(
					sprintf( __( 'Progress with ID %s not found', 'wp-rss-aggregator' ), $id )
				)
			);
		}

		return Result::Ok( $this->rowToProgress( $row ) );
	}

	/**
	 * Watches a progress row for changes at a constant rate.
	 *
	 * @param string $id The ID of the progress to watch.
	 * @param int    $intervalMs The milliseconds between checks.
	 * @param bool   $stop Whether to stop the generator when the progress is done.
	 * @param int    $timeout Optional timeout in seconds. When no progress matches
	 *           the given ID for this amount of time, the watcher stops.
	 * @return Generator<Progress> A generator that yields a Progress instance
	 *         whenever the row changes.
	 */
	public function watch( ?string $id, int $intervalMs = 500, bool $stop = true, int $timeout = 3 ): Generator {
		if ( $id === null ) {
			return;
		}

		$progress = null;
		$lastHit = time();

		while ( true ) {
			$prev = $progress;
			$progress = $this->getById( $id )->getOr( null );

			if ( $progress !== null ) {
				$lastHit = time();

				if ( $prev === null || ! $progress->equals( $prev ) ) {
					yield $progress;
				}

				if ( $stop && $progress->isDone() ) {
					break;
				}
			} else {
				$elapsed = time() - $lastHit;

				if ( $elapsed > $timeout ) {
					break;
				}
			}

			usleep( $intervalMs * 1000 );
		}
	}

	public function touch( ?string $id, int $total, string $message = '' ): Result {
		if ( $id === null ) {
			return Result::Ok( true );
		}

		$progress = new Progress( $id, new DateTime(), 0, $total, $message );
		$data = $this->progressToRow( $progress );

		try {
			$num = $this->db->replace( $this->table, $data, $this->getColumnFormats() );

			return Result::Ok( $num );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/** @return Result<bool> */
	public function set( ?string $id, int $current, int $total, string $message = '' ): Result {
		return $this->update(
			$id,
			array(
				'current' => $current,
				'total' => $total,
				'message' => $message,
			)
		);
	}

	/** @return Result<bool> */
	public function setCurrent( ?string $id, int $current ): Result {
		return $this->update( $id, array( self::CURRENT => $current ) );
	}

	/** @return Result<bool> */
	public function setTotal( ?string $id, int $total ): Result {
		return $this->update( $id, array( self::TOTAL => $total ) );
	}

	/** @return Result<bool> */
	public function setMessage( ?string $id, string $message = '' ): Result {
		return $this->update( $id, array( self::MESSAGE => $message ) );
	}

	/**
	 * @param array<string,mixed> $set
	 * @return Result<bool>
	 */
	private function update( ?string $id, array $set = array() ): Result {
		if ( $id === null ) {
			return Result::Ok( false );
		}

		$formats = array_intersect_key( $this->getColumnFormats(), $set );

		try {
			$num = $this->db->update(
				$this->table,
				$set,
				array( 'id' => $id ),
				$formats,
				array( '%s' )
			);
			return Result::Ok( $num > 0 );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Advances a progress by an optional given amount.
	 *
	 * @param string|null $id The ID of the progress to advance. May be null for
	 *        convenience, in which case a result of 0 is returned.
	 * @param int         $num Optional amount to advance by. Defaults to 1.
	 * @return Result<int> The number of updated rows.
	 */
	public function advance( ?string $id, int $num = 1, string $message = '' ): Result {
		if ( $id === null ) {
			return Result::Ok( false );
		}

		try {
			$num = $this->db->query(
				"UPDATE {$this->table}
                SET `current` = `current` + %d, `message` = %s
                WHERE `id` = %s",
				array( $num, $message, $id )
			);
			return Result::Ok( $num > 0 );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Regresses a progress by an optional given amount.
	 *
	 * @param string|null $id The ID of the progress to regress.
	 * @param int         $num Optional amount to regress by. Defaults to 1.
	 * @return Result
	 */
	public function regress( ?string $id, int $num = 1, string $message = '' ): Result {
		return $this->advance( $id, -$num, $message );
	}

	/**
	 * Deletes a progress.
	 *
	 * @param string|null $id The ID of the progress to delete. If null, a
	 *        result of 0 is returned.
	 * @return Result<bool>
	 */
	public function delete( ?string $id ): Result {
		if ( $id === null ) {
			return Result::Ok( 0 );
		}

		try {
			$num = $this->db->delete( $this->table, array( 'id' => $id ), array( '%s' ) );
			return Result::Ok( $num > 0 );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Checks if a progress can be deleted, and if so, deletes it.
	 *
	 * @param Progress $progress The progress to check.
	 * @return Result<bool> A result that contains a boolean indicating whether the progress was deleted.
	 */
	public function clean( Progress $progress ): Result {
		if ( $progress->current >= $progress->total ) {
			$result = $this->delete( $progress->id );

			if ( $result->isOk() ) {
				return Result::Ok( true );
			} else {
				return Result::Err( $result->error() );
			}
		} else {
			return Result::Ok( false );
		}
	}

	/**
	 * Transforms a database row into a {@link advance} instance.
	 *
	 * @param array $row The database row.
	 * @return Progress The progress instance.
	 */
	public function rowToProgress( array $row ): Progress {
		$id = $row[ self::ID ] ?? '';
		$timeStarted = Time::createAndCatch( $row[ self::STARTED ] ?? 'now' ) ?? new DateTime();
		$current = (int) ( $row[ self::CURRENT ] ?? 0 );
		$total = (int) ( $row[ self::TOTAL ] ?? 0 );
		$message = $row[ self::MESSAGE ] ?? '';

		return new Progress( $id, $timeStarted, $current, $total, $message );
	}

	/**
	 * Transforms a {@link Progress} instance into a database row.
	 *
	 * @param Progress $progress The progress instance.
	 * @return array The database row.
	 */
	public function progressToRow( Progress $progress ): array {
		$row = array(
			self::CURRENT => $progress->current,
			self::TOTAL => $progress->total,
			self::STARTED => $progress->started->format( 'Y-m-d H:i:s' ),
			self::MESSAGE => $progress->message,
		);

		if ( $progress->id !== '' ) {
			$row[ self::ID ] = $progress->id;
		}

		return $row;
	}

	protected function getColumnFormats(): array {
		return array(
			'id' => '%s',
			'current' => '%d',
			'total' => '%d',
			'started' => '%s',
			'message' => '%s',
		);
	}

	public function createTable(): void {
		if ( $this->db->tableExists( $this->table ) ) {
			return;
		}

		$this->db->delta(
			"CREATE TABLE {$this->table} (
                id VARCHAR(30) NOT NULL,
                current INT(10) DEFAULT 0,
                total INT(10) DEFAULT 0,
                started DATETIME DEFAULT NOW(),
                message VARCHAR(255) DEFAULT '',
                PRIMARY KEY  (id)
            ) {$this->db->charsetCollate};"
		);
	}
}
