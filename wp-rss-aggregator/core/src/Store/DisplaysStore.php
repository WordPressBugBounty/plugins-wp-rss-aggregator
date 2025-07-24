<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use Throwable;
use RebelCode\Aggregator\Core\Utils\Strings;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Exception\NotFoundException;
use RebelCode\Aggregator\Core\Display\DisplaySettings;
use RebelCode\Aggregator\Core\Display\DisplayInstance;
use RebelCode\Aggregator\Core\Display;
use RebelCode\Aggregator\Core\Database;

class DisplaysStore {

	public const ID = 'id';
	public const NAME = 'name';
	public const SOURCES = 'sources';
	public const FOLDERS = 'folders';
	public const SETTINGS = 'settings';
	public const V4_SLUG = 'v4_slug';

	protected Database $db;
	protected string $table;

	public function __construct( Database $db, string $table ) {
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Gets a display by ID.
	 *
	 * @param int $id The display ID.
	 * @return Result<Display>
	 */
	public function getById( int $id ): Result {
		try {
			$result = $this->db->getRow(
				"SELECT * FROM {$this->table} WHERE `id` = %d",
				array( $id ),
				0,
			);

			if ( $result === null ) {
				return Result::Err(
					new NotFoundException(
						sprintf( __( 'Display with ID %d not found', 'wp-rss-aggregator' ), $id )
					)
				);
			}

			return Result::Ok( $this->rowToDisplay( $result ) );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets multiple displays by their IDs.
	 *
	 * @param iterable<int> $ids The display IDs.
	 * @return Result<iterable<Display>>
	 */
	public function getManyByIds( iterable $ids ): Result {
		$idList = Arrays::gjoin( ',', $ids );
		if ( empty( $idList ) ) {
			return Result::Ok( array() );
		}

		try {
			$results = $this->db->getResults(
				"SELECT * FROM {$this->table} WHERE `id` IN (%s)",
				array( $idList )
			);

			$displays = Arrays::gmap( $results, fn ( $row ) => $this->rowToDisplay( $row ) );

			return Result::Ok( $displays );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets a display by its v4 slug.
	 *
	 * @param string $v4Slug The v4 slug.
	 * @return Result<Display>
	 */
	public function getByV4Slug( string $v4Slug ): Result {
		try {
			$result = $this->db->getRow(
				"SELECT * FROM {$this->table} WHERE `v4_slug` = %s",
				array( $v4Slug ),
				0,
			);

			if ( $result === null ) {
				return Result::Err(
					new NotFoundException(
						sprintf( __( 'Display with slug "%s" not found', 'wp-rss-aggregator' ), $v4Slug )
					)
				);
			}

			return Result::Ok( $this->rowToDisplay( $result ) );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets a listing of displays.
	 *
	 * Similar to {@link DisplaysStore::query()}, but accepts a filter string and a page number instead
	 * of a WHERE condition and an offset.
	 *
	 * @param string   $filter Optional search or filter string.
	 * @param int|null $num The number of items to get.
	 * @param int      $page The page number.
	 * @param string   $order Either "asc" or "desc".
	 * @param string   $orderBy The name of the field to sort by. Default is 'name'.
	 * @return Result<iterable<Display>> The folders.
	 */
	public function getList( string $filter = '', ?int $num = null, int $page = 1, string $order = 'asc' | 'desc', string $orderBy = self::NAME ): Result {
		$args = array();
		$where = 'true';

		if ( $filter ) {
			$where = '(`name` LIKE %s)';
			$args[] = "%$filter%";
		}

		$order = $this->db->normalizeOrder( $orderBy );
		$args[] = $orderBy;

		$pagination = $this->db->pagination( $num, $page );

		$sql = "SELECT * FROM {$this->table}
            WHERE $where
            ORDER BY %i $order
            {$pagination}";

		try {
			$results = $this->db->getResults( $sql, $args );
			$srcs = array_map( array( $this, 'rowToDisplay' ), $results );
			return Result::Ok( $srcs );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Inserts a new display.
	 *
	 * @param Display $display The display to insert.
	 * @return Result<Display>
	 */
	public function insert( Display $display ): Result {
		$row = $this->displayToRow( $display );
		$formats = $this->getColumnFormats();
		unset( $row['id'], $formats['id'] );

		try {
			$id = $this->db->insert( $this->table, $row, $formats );

			return Result::Ok( $display->withId( $id ) );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Updates a feed display in its entirety.
	 *
	 * @param Display $display The display to update.
	 * @return Result<Display>
	 */
	public function replace( Display $display ): Result {
		if ( ! $display->id ) {
			return Result::Err( __( 'Cannot replace display with no ID', 'wp-rss-aggregator' ) );
		}

		$row = $this->displayToRow( $display );

		try {
			$this->db->replace( $this->table, $row, $this->getColumnFormats() );

			return Result::Ok( $display );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Saves a feed display. Inserts if the display has no ID, or replaces if it does.
	 *
	 * @param Display $display The display to save.
	 * @return Result<Display>
	 */
	public function save( Display $display ): Result {
		if ( $display->id === null ) {
			return $this->insert( $display );
		} else {
			return $this->replace( $display );
		}
	}

	/**
	 * Gets the displays that use a source from a given list of IDs.
	 *
	 * @param list<int> $sourceIds The IDs of the sources.
	 * @param int|null  $num The number of items to get.
	 * @param int       $page The page number.
	 * @return Result<iterable<Display>> The displays.
	 */
	public function getWithSources( array $sourceIds, ?int $num = null, int $page = 1 ): Result {
		if ( count( $sourceIds ) === 0 ) {
			return Result::Ok( array() );
		}

		$where = array();
		$args = array();
		foreach ( $sourceIds as $srcId ) {
			$where[] = '(`sources` LIKE %s)';
			$args[] = "%|$srcId|%";
		}

		$whereStr = implode( ' OR ', $where );
		$pagination = $this->db->pagination( $num, $page );

		$sql = "SELECT * FROM {$this->table}
                WHERE {$whereStr}
                ORDER BY `name` ASC
                {$pagination}";

		try {
			$results = $this->db->getResults( $sql, $args );
			$displays = array_map( array( $this, 'rowToDisplay' ), $results );
			return Result::Ok( $displays );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets the displays that use a folder from a given list of IDs.
	 *
	 * @param iterable<string> $folders The id of the folders.
	 * @param int|null         $num The number of items to get.
	 * @param int              $page The page number.
	 * @return Result<iterable<Display>> The folders.
	 */
	public function getWithFolders( iterable $folders, ?int $num = null, int $page = 1 ): Result {
		if ( count( $folders ) === 0 ) {
			return Result::Ok( array() );
		}

		$where = array();
		$args = array();
		foreach ( $folders as $folder ) {
			$where[] = '(`folders` LIKE %s)';
			$args[] = "%|$folder|%";
		}

		$whereStr = implode( ' OR ', $where );
		$pagination = $this->db->pagination( $num, $page );

		$sql = "SELECT * FROM {$this->table}
                WHERE {$whereStr}
                ORDER BY `name` ASC
                {$pagination}";

		try {
			$results = $this->db->getResults( $sql, $args );
			$displays = array_map( array( $this, 'rowToDisplay' ), $results );
			return Result::Ok( $displays );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets the posts where a display is embedded.
	 *
	 * @return list<DisplayInstance>
	 */
	public function getInstances( int $id ): array {
		$results = DisplayInstance::findShortcodes( $id );
		$results = array_merge( $results, DisplayInstance::findBlocks( $id ) );

		return $results;
	}

	/**
	 * Gets the total number of displays that match a query.
	 *
	 * @return Result<int>
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
	 * Configure one or more displays.
	 *
	 * @param iterable<int>        $ids The IDs of the displays.
	 * @param array<string, mixed> $changes The settings to change.
	 * @return Result<int> The number of updates displays.
	 */
	public function configure( iterable $ids, array $changes ): Result {
		$result = $this->getManyByIds( $ids );

		if ( $result->isOk() ) {
			$num = 0;
			foreach ( $result->get() as $display ) {
				$display->settings->patch( $changes );

				$result = $this->replace( $display );

				if ( $result->isOk() ) {
					$num++;
				} else {
					return Result::Err( $result->error() );
				}
			}

			return Result::Ok( $num );
		} else {
			return Result::Err( $result->error() );
		}
	}

	/**
	 * Deletes a display by its ID.
	 *
	 * @param int $id The ID of the display to delete.
	 * @return Result<int> The number of deleted displays.
	 */
	public function deleteById( int $id ): Result {
		try {
			$num = $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
			return Result::Ok( $num );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Deletes multiple displays by their IDs.
	 *
	 * @param list<int> $ids The IDs of the displays to delete.
	 * @return Result<int> The number of deleted displays.
	 */
	public function deleteManyByIds( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		$args = array();
		$idList = $this->db->prepareList( $ids, '%d', $args );

		try {
			$num = $this->db->query(
				"DELETE FROM {$this->table}
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $num );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Converts a database row to a display.
	 *
	 * @param array $row The database row.
	 * @return Display
	 */
	protected function rowToDisplay( array $row ): Display {
		$id = $row[ self::ID ] ?? null;
		if ( $id === null ) {
			Logger::warning( 'Database row for display does not have an ID.' );
		} else {
			$id = (int) $id;
		}

		$name = $row[ self::NAME ] ?? '';

		$sources = Strings::cleanSplit( $row[ self::SOURCES ] ?? '', '|', 'intval' );
		$folders = Strings::cleanSplit( $row[ self::FOLDERS ] ?? '', '|' );

		$settingsArr = json_decode( $row[ self::SETTINGS ] ?? '', true );
		if ( ! is_array( $settingsArr ) ) {
			$settingsArr = array();
			Logger::warning( 'Database value for display settings is not a valid JSON string.' );
		}

		$settings = new DisplaySettings( $settingsArr );

		$v4Slug = $row[ self::V4_SLUG ] ?? '';

		return new Display( $id, $name, $sources, $folders, $settings, $v4Slug );
	}

	/**
	 * Converts a display to a database row.
	 *
	 * @param Display $display The display to convert.
	 * @return array<string,mixed> An associative array of database column names to row values.
	 */
	protected function displayToRow( Display $display ): array {
		return array(
			self::ID => $display->id,
			self::NAME => $display->name,
			self::SOURCES => '|' . implode( '|', $display->sources ) . '|',
			self::FOLDERS => '|' . implode( '|', $display->folders ) . '|',
			self::SETTINGS => json_encode( $display->settings->toArray() ),
			self::V4_SLUG => $display->v4Slug,
		);
	}

	protected function getColumnFormats(): array {
		return array(
			'id' => '%d',
			'name' => '%s',
			'sources' => '%s',
			'folders' => '%s',
			'settings' => '%s',
		);
	}

	public function createTable(): void {
		if ( $this->db->tableExists( $this->table ) ) {
			return;
		}

		$this->db->delta(
			"CREATE TABLE {$this->table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) DEFAULT '',
                sources TEXT DEFAULT '',
                folders TEXT DEFAULT '',
                settings TEXT DEFAULT '',
                v4_slug TEXT DEFAULT '',
                PRIMARY KEY  (id)
            ) {$this->db->charsetCollate};"
		);
	}
}
