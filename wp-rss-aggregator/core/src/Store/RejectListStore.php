<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use DateTime;
use RebelCode\Aggregator\Core\Database;
use RebelCode\Aggregator\Core\RejectedItem;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;
use Throwable;

/**
 * @phpstan-type RejectListRow array{guid?: string, guid_hash?: string, date?: string, notes?: string}
 * @psalm-type RejectListRow = array{guid?: string, guid_hash?: string, date?: string, notes?: string}
 */
class RejectListStore {

	public const GUID = 'guid';
	public const GUID_HASH = 'guid_hash';
	public const NOTES = 'notes';
	public const DATE = 'date';
	public const HASH_PREFIX = 'hash:';

	protected Database $db;
	protected string $table;
	private ?bool $hasGuidHashColumn = null;

	public function __construct( Database $db, string $table ) {
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Adds a rejected item using its original GUID for display and a stable hash
	 * key for internal identity when the upgraded schema is available.
	 *
	 * @return Result<RejectedItem>
	 *
	 * @since 5.4.0 Added hash-key storage support.
	 */
	public function add( RejectedItem $item ): Result {
		$new = clone $item;
		$new->date = new DateTime();

		$row = $this->itemToRow( $new );
		$formats = $this->getColumnFormats();

		try {
			$this->db->replace( $this->table, $row, $formats );
			return Result::Ok( $new );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Checks if the list contains a GUID that matches any of the given strings.
	 *
	 * @param list<string> $guids The strings to check.
	 * @return Result<bool> `true` if at least one string matches a GUID in the
	 *                      list, `false` otherwise.
	 *
	 * @since 5.4.0 Added hash-key lookup support.
	 */
	public function contains( array $guids ): Result {
		if ( count( $guids ) === 0 ) {
			return Result::Ok( false );
		}

		try {
			$guids = array_values( array_filter( $guids, fn ( string $guid ): bool => $guid !== '' ) );
			if ( count( $guids ) === 0 ) {
				return Result::Ok( false );
			}

			$args = array();
			$where = $this->guidWhereSql( $guids, $args );

			$result = $this->db->getRow(
				"SELECT COUNT(*) as `count`
                FROM {$this->table}
                WHERE {$where}",
				$args,
			);

			$result ??= array();
			$count = intval( $result['count'] ?? 0 );

			return Result::Ok( $count > 0 );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Gets a listing of the items in the rejection list.
	 *
	 * Similar to {query()}, but accepts a filter string and a page number
	 * instead of a WHERE condition and an offset.
	 *
	 * @param string      $filter Optional search or filter string.
	 * @param int|null    $num The number of items to get.
	 * @param int         $page The page number.
	 * @param list<Order> $order The order of the items.
	 * @return Result<iterable<RejectedItem>> The folders.
	 */
	public function getList( string $filter = '', ?int $num = null, int $page = 1 ): Result {
		$args = array();
		$where = 'true';

		if ( $filter ) {
			$where = '(`guid` LIKE %s) OR (`notes` LIKE %s)';
			array_push( $args, "%$filter%", "%$filter%" );
		}

		$pagination = $this->db->pagination( $num, $page );

		$sql = "SELECT * FROM {$this->table}
                WHERE {$where}
                {$pagination}";

		try {
			$results = $this->db->getResults( $sql, $args );
			$items = array_map( array( $this, 'rowToItem' ), $results );
			return Result::Ok( $items );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets the number of items in the rejection list.
	 *
	 * @return Result<int> The number of items.
	 */
	public function getCount(): Result {
		try {
			$result = $this->db->getRow(
				"SELECT COUNT(*) as count FROM {$this->table}"
			);

			$result ??= array();
			$count = (int) ( $result['count'] ?? 0 );

			return Result::Ok( $count );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Updates an item. Since the GUID is the primary key, this requires knowing
	 * the previous GUID.
	 *
	 * @return Result<RejectedItem>
	 *
	 * @since 5.4.0 Added hash-key update support.
	 */
	public function update( string $guid, RejectedItem $new ): Result {
		$data = $this->itemToRow( $new );
		$formats = $this->getColumnFormats();

		try {
			if ( $this->hasGuidHashColumn() ) {
				$args = array(
					$data[ self::GUID_HASH ],
					$data[ self::GUID ],
					$data[ self::DATE ],
					$data[ self::NOTES ],
					self::hashGuid( $guid ),
					$guid,
				);

				$this->db->query(
					"UPDATE {$this->table}
                    SET `guid_hash` = %s, `guid` = %s, `date` = %s, `notes` = %s
                    WHERE `guid_hash` = %s OR `guid` = %s",
					$args
				);
			} else {
				$this->db->update(
					$this->table,
					$data,
					array( 'guid' => $guid ),
					$formats,
					array( '%s' )
				);
			}
			return Result::Ok( $new );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Deletes a GUID from the reject list.
	 *
	 * @param string $guid The GUID to delete.
	 * @return Result<int> A result containing the number of rows deleted.
	 *
	 * @since 5.4.0 Added hash-key delete support.
	 */
	public function delete( string $guid ): Result {
		try {
			if ( $this->hasGuidHashColumn() ) {
				$num = $this->db->query(
					"DELETE FROM {$this->table}
                    WHERE `guid_hash` = %s OR `guid` = %s",
					array( self::hashGuid( $guid ), $guid )
				);
			} else {
				$num = $this->db->delete( $this->table, array( 'guid' => $guid ), array( '%s' ) );
			}
			return Result::Ok( $num );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Deletes multiple GUIDs from the reject list.
	 *
	 * @param list<string> $guids The GUIDs to delete.
	 * @return Result<int> A result containing the number of rows deleted.
	 *
	 * @since 5.4.0 Added hash-key delete support.
	 */
	public function deleteManyByGuids( array $guids ): Result {
		if ( count( $guids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$guids = array_values( array_filter( $guids, fn ( string $guid ): bool => $guid !== '' ) );
			if ( count( $guids ) === 0 ) {
				return Result::Ok( 0 );
			}

			$args = array();
			$where = $this->guidWhereSql( $guids, $args );

			$num = $this->db->query(
				"DELETE FROM {$this->table}
                WHERE {$where}",
				$args
			);
			return Result::Ok( (int) $num );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Deletes all the items from the rejection list.
	 *
	 * @return Result<int> The number of items deleted.
	 */
	public function deleteAll(): Result {
		try {
			$num = $this->db->query( "DELETE FROM {$this->table}" );
			return Result::Ok( $num );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Converts a database row into the public rejected-item value object.
	 *
	 * @param RejectListRow $row
	 *
	 * @since 5.4.0
	 */
	private function rowToItem( array $row ): RejectedItem {
		$guid = $row['guid'] ?? '';
		$dateStr = $row['date'] ?? '';
		$notes = $row['notes'] ?? '';

		$date = Time::createAndCatch( $dateStr );

		return new RejectedItem( $guid, $date, $notes, );
	}

	/**
	 * Converts a rejected item to a database row.
	 *
	 * @return array{guid: string, date: string, notes: string, guid_hash?: string}
	 *
	 * @since 5.4.0 Added optional hash-key column support.
	 */
	private function itemToRow( RejectedItem $item ): array {
		$row = array(
			self::GUID => $item->guid,
			self::DATE => $item->date->format( 'Y-m-d H:i:s' ),
			self::NOTES => $item->notes,
		);

		if ( $this->hasGuidHashColumn() ) {
			$row[ self::GUID_HASH ] = self::hashGuid( $item->guid );
		}

		return $row;
	}

	/**
	 * Gets the WordPress database column formats for reject-list writes.
	 *
	 * @return array{guid: '%s', date: '%s', notes: '%s', guid_hash?: '%s'}
	 *
	 * @since 5.4.0 Added optional hash-key column support.
	 */
	protected function getColumnFormats(): array {
		$formats = array(
			'guid' => '%s',
			'date' => '%s',
			'notes' => '%s',
		);

		if ( $this->hasGuidHashColumn() ) {
			$formats[ self::GUID_HASH ] = '%s';
		}

		return $formats;
	}

	/**
	 * Creates the reject-list table using the current schema.
	 *
	 * @since 5.4.0 Switched the primary key from raw GUID to GUID hash.
	 */
	public function createTable(): void {
		if ( $this->db->tableExists( $this->table ) ) {
			return;
		}

		$this->db->delta(
			"CREATE TABLE {$this->table} (
                guid_hash VARCHAR(69) NOT NULL,
                guid TEXT NOT NULL,
                notes TEXT DEFAULT '',
                date DATETIME DEFAULT NOW(),
                PRIMARY KEY  (guid_hash)
            ) {$this->db->charsetCollate};"
		);

		$this->hasGuidHashColumn = true;
	}

	/**
	 * Creates the internal identity key for a rejected GUID.
	 *
	 * @since 5.4.0
	 */
	public static function hashGuid( string $guid ): string {
		return self::HASH_PREFIX . hash( 'sha256', $guid );
	}

	/**
	 * @param list<string> $guids
	 * @param list<mixed>  $args
	 *
	 * @since 5.4.0
	 */
	private function guidWhereSql( array $guids, array &$args ): string {
		if ( ! $this->hasGuidHashColumn() ) {
			$guidList = $this->db->prepareList( $guids, '%s', $args );
			return "`guid` IN ({$guidList})";
		}

		$hashes = array_map( array( self::class, 'hashGuid' ), $guids );
		$hashList = $this->db->prepareList( $hashes, '%s', $args );
		$guidList = $this->db->prepareList( $guids, '%s', $args );

		return "(`guid_hash` IN ({$hashList}) OR `guid` IN ({$guidList}))";
	}

	/**
	 * Checks whether the current reject-list table supports hash-key storage.
	 *
	 * @since 5.4.0
	 */
	private function hasGuidHashColumn(): bool {
		if ( $this->hasGuidHashColumn !== null ) {
			return $this->hasGuidHashColumn;
		}

		try {
			$cols = $this->db->getCol(
				"SHOW COLUMNS FROM {$this->table} LIKE %s",
				array( self::GUID_HASH )
			);

			$this->hasGuidHashColumn = ! empty( $cols );
		} catch ( Throwable $t ) {
			$this->hasGuidHashColumn = false;
		}

		return $this->hasGuidHashColumn;
	}
}
