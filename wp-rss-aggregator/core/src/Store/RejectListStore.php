<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use DateTime;
use RebelCode\Aggregator\Core\Database;
use RebelCode\Aggregator\Core\RejectedItem;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;
use Throwable;

class RejectListStore {

	public const GUID = 'guid';
	public const NOTES = 'notes';
	public const DATE = 'date';

	protected Database $db;
	protected string $table;

	public function __construct( Database $db, string $table ) {
		$this->db = $db;
		$this->table = $table;
	}

	public function add( RejectedItem $item ): Result {
		$new = clone $item;
		$new->date = new DateTime();

		$row = $this->itemToRow( $item );
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
	 */
	public function contains( array $guids ): Result {
		if ( count( $guids ) === 0 ) {
			return Result::Ok( false );
		}

		try {
			$args = array();
			$guidList = $this->db->prepareList( $guids, '%s', $args );

			$result = $this->db->getRow(
				"SELECT COUNT(*) as `count`
                FROM {$this->table}
                WHERE `guid` IN ({$guidList})",
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
	 */
	public function update( string $guid, RejectedItem $new ): Result {
		$data = $this->itemToRow( $new );
		$formats = $this->getColumnFormats();

		try {
			$this->db->update(
				$this->table,
				$data,
				array( 'guid' => $guid ),
				$formats,
				array( '%s' )
			);
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
	 */
	public function delete( string $guid ): Result {
		try {
			$num = $this->db->delete( $this->table, array( 'guid' => $guid ), array( '%s' ) );
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
	 */
	public function deleteManyByGuids( array $guids ): Result {
		if ( count( $guids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array();
			$guidList = $this->db->prepareList( $guids, '%s', $args );

			$num = $this->db->query(
				"DELETE FROM {$this->table}
                WHERE `guid` IN ({$guidList})",
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

	private function rowToItem( array $row ): RejectedItem {
		$guid = $row['guid'] ?? '';
		$dateStr = $row['date'] ?? '';
		$notes = $row['notes'] ?? '';

		$date = Time::createAndCatch( $dateStr );

		return new RejectedItem( $guid, $date, $notes, );
	}

	private function itemToRow( RejectedItem $item ) {
		return array(
			self::GUID => $item->guid,
			self::DATE => $item->date->format( 'Y-m-d H:i:s' ),
			self::NOTES => $item->notes,
		);
	}

	protected function getColumnFormats(): array {
		return array(
			'guid' => '%s',
			'date' => '%s',
			'notes' => '%s',
		);
	}

	public function createTable(): void {
		if ( $this->db->tableExists( $this->table ) ) {
			return;
		}

		$this->db->delta(
			"CREATE TABLE {$this->table} (
                guid VARCHAR(180) NOT NULL,
                notes TEXT DEFAULT '',
                date DATETIME DEFAULT NOW(),
                PRIMARY KEY  (guid)
            ) {$this->db->charsetCollate};"
		);
	}
}
