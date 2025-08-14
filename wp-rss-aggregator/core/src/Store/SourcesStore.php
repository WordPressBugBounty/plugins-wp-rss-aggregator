<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use Throwable;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Source\SourceSettings;
use RebelCode\Aggregator\Core\Source\ScheduleFactory;
use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Exception\NotFoundException;
use RebelCode\Aggregator\Core\Database;
use DateTime;

class SourcesStore {

	public const ID = 'id';
	public const NAME = 'name';
	public const URL = 'url';
	public const ACTIVE = 'active';
	public const SCHEDULE = 'schedule';
	public const LAST_UPDATE = 'last_update';
	public const LAST_ERROR = 'last_error';
	public const SETTINGS = 'settings';
	public const V4_ID = 'v4_id';
	public const V4_SLUG = 'v4_slug';

	public Database $db;
	public string $table;

	public function __construct( Database $db, string $table ) {
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Gets a source by ID.
	 *
	 * @param int $id The source ID.
	 * @return Result<Source>
	 */
	public function getById( int $id ): Result {
		try {
			$result = $this->db->getRow(
				"SELECT * FROM {$this->table} WHERE `id` = %d",
				array( $id ),
				0,
				new NotFoundException(
					sprintf( __( 'Source with ID %d not found', 'wp-rss-aggregator' ), $id )
				)
			);

			return Result::Ok( $this->rowToSource( $result ) );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets multiple sources by their IDs.
	 *
	 * @param iterable<int> $ids The source IDs.
	 * @return Result<iterable<Source>>
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

			$srcs = Arrays::gmap( $results, fn ( $row ) => $this->rowToSource( $row ) );

			return Result::Ok( $srcs );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets multiple sources by their v4 slug names.
	 *
	 * @param iterable<string> $slugs The slug names.
	 * @return Result<iterable<Source>>
	 */
	public function getManyByV4Slugs( iterable $slugs ): Result {
		$slugList = Arrays::gjoin( ',', $slugs );
		if ( empty( $slugList ) ) {
			return Result::Ok( array() );
		}

		try {
			$results = $this->db->getResults(
				"SELECT * FROM {$this->table} WHERE `v4_slug` IN (%s)",
				array( $slugList )
			);

			$srcs = Arrays::gmap( $results, fn ( $row ) => $this->rowToSource( $row ) );

			return Result::Ok( $srcs );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets the v5 IDs for a list of v4 source IDs.
	 *
	 * @param list<int> The v4 IDs to resolve.
	 * @return Result<array<int,int>> A mapping between the v4 IDs and the
	 *         corresponding v5 IDs. Non-existent v4 IDs are omitted.
	 */
	public function resolveV4Ids( array $ids ): Result {
		if ( empty( $ids ) ) {
			return Result::Ok( array() );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$results = $this->db->getResults(
				"SELECT `id`, `v4_id` FROM {$this->table} WHERE `v4_id` IN ({$idList})",
				$args,
			);

			$map = array();
			foreach ( $results as $row ) {
				$map[ (int) $row['v4_id'] ] = (int) $row['id'];
			}

			return Result::Ok( $map );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets a listing of sources.
	 *
	 * Similar to {@link SourcesStore::query()}, but accepts a filter string and a page number instead
	 * of a WHERE condition and an offset.
	 *
	 * @param string   $filter Optional search or filter string.
	 * @param int|null $num The number of items to get.
	 * @param int      $page The page number.
	 * @param string   $order Either "asc" or "desc". Results are sorted by name.
	 * @param string   $status Optional status filter: '', 'active', 'inactive', 'erred'.
	 * @return Result<iterable<Source>> The sources.
	 */
	public function getList(
		string $filter = '',
		?int $num = null,
		int $page = 1,
		string $order = 'asc',
		?array $srcIds = null,
		string $status = ''
	): Result {
		$sql = "SELECT * FROM {$this->table} ";

		$where = array();
		$args = array();

		if ( $filter ) {
			$where[] = '(`name` LIKE %s OR `url` LIKE %s)';
			array_push( $args, "%$filter%", "%$filter%" );
		}

		switch ( $status ) {
			case 'erred':
				$where[] = '(`last_error` IS NOT NULL)';
				break;
			case 'active':
				$where[] = '(`active` = true)';
				break;
			case 'inactive':
				$where[] = '(`active` = false)';
				break;
		}

		if ( $srcIds !== null && count( $srcIds ) > 0 ) {
			$srcIdList = $this->db->prepareList( $srcIds, '%d', $args );
			$where[] = "(`id` IN ({$srcIdList}))";
		}

		if ( ! empty( $where ) ) {
			$sql .= 'WHERE ' . implode( ' AND ', $where ) . ' ';
		}

		$order = $this->db->normalizeOrder( $order );
		$sql .= "ORDER BY `name` {$order} {$this->db->pagination($num,$page)}";

		try {
			$results = $this->db->getResults( $sql, $args );
			$srcs = array_map( array( $this, 'rowToSource' ), $results );
			return Result::Ok( $srcs );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Gets the total number of sources.
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

	/** @return Result<int> */
	public function getCountErred(): Result {
		try {
			$result = $this->db->getRow(
				"SELECT COUNT(*) as count FROM {$this->table}
                WHERE `last_error` IS NOT NULL AND `last_error` != \"\"
                "
			);

			$result ??= array();
			$count = (int) ( $result['count'] ?? 0 );

			return Result::Ok( $count );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Gets the names of a specific list of sources, by their IDs.
	 *
	 * @param list<int> $ids The IDs of the sources.
	 * @return Result<array<int,string>> A mapping of IDs to names.
	 */
	public function getNames( array $ids, ?int $blogId = null ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( array() );
		}

		if ( $blogId !== null ) {
			switch_to_blog( $blogId );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$results = $this->db->getResults(
				"SELECT id, name FROM {$this->table}
                WHERE `id` IN ({$idList})",
				$args
			);

			$names = array();
			foreach ( $results as $row ) {
				$src = $this->rowToSource( $row );
				$names[ $src->id ] = $src->name;
			}

			foreach ( $ids as $id ) {
				$names[ $id ] ??= sprintf( __( '#%d [Missing]', 'wp-rss-aggregator' ), $id );
			}

			return Result::Ok( $names );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		} finally {
			if ( $blogId !== null ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Gets the names of a specific list of sources, by their v4 id.
	 *
	 * @param list<int> $ids The v4 IDs of the sources.
	 * @return Result<array<int,string>> A mapping of IDs to names.
	 */
	public function getNamesByV4ID( array $ids, ?int $blogId = null ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( array() );
		}

		if ( $blogId !== null ) {
			switch_to_blog( $blogId );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$results = $this->db->getResults(
				"SELECT v4_id, name FROM {$this->table}
                WHERE `v4_id` IN ({$idList})",
				$args
			);

			$names = array();
			foreach ( $results as $row ) {
				$src = $this->rowToSource( $row );
				$names[ $src->v4Id ] = $src->name;
			}

			foreach ( $ids as $id ) {
				$names[ $id ] ??= sprintf( __( '#%d [Missing]', 'wp-rss-aggregator' ), $id );
			}

			return Result::Ok( $names );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		} finally {
			if ( $blogId !== null ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Gets the sources that have a pending update. Note that this does not mean that every returned source needs to
	 * be updated. Check the source's {@link Source::getNextUpdate()} method to see if it needs to be updated.
	 *
	 * @param int|null $num The number of items to get.
	 * @param int      $page The page number.
	 * @return Result<iterable<Source>>
	 */
	public function getPendingUpdate( ?int $num = null, int $page = 1 ): Result {
		$where = '(`active` = true) AND (`schedule` != "")';
		$pagination = $this->db->pagination( $num, $page );

		try {
			$results  = $this->db->getResults( "SELECT * FROM {$this->table} WHERE $where $pagination" );

			$srcs = ( function () use ( $results ) {
				foreach ( $results as $row ) {
					$src = $this->rowToSource( $row );
					if ( $src->isPendingUpdate() ) {
						yield $src;
					}
				}
			} )();

			return Result::Ok( $srcs );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Saves a feed source. Inserts if the source has no ID, or replaces if it does.
	 *
	 * @param Source $src The source to save.
	 * @return Result<Source>
	 */
	public function save( Source $src ): Result {
		if ( $src->id === null ) {
			return $this->insert( $src );
		} else {
			return $this->replace( $src );
		}
	}

	/**
	 * Inserts a new source.
	 *
	 * @param Source $src The source to insert.
	 * @return Result<Source>
	 */
	private function insert( Source $src ): Result {
		$row = $this->sourceToRow( $src->withId( null ) );
		$formats = $this->getColumnFormats();
		unset( $row['id'], $formats['id'] );

		try {
			$id = $this->db->insert( $this->table, $row, $formats );

			add_action( 'wpra.sources.store.inserted', $src );

			return Result::Ok( $src->withId( $id ) );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Updates a feed source in its entirety.
	 *
	 * @param Source $src The source to update.
	 * @return Result<Source>
	 */
	private function replace( Source $src ): Result {
		if ( ! $src->id ) {
			return Result::Err( __( 'Cannot replace source with no ID', 'wp-rss-aggregator' ) );
		}

		$row = $this->sourceToRow( $src );

		try {
			$this->db->replace( $this->table, $row, $this->getColumnFormats() );

			return Result::Ok( $src );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Activates a feed source.
	 *
	 * @param list<int> $ids The IDs of the sources to activate.
	 * @return Result<int> The number of activated sources.
	 */
	public function activate( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `active` = true
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Pauses a feed source.
	 *
	 * @param list<int> $ids The IDs of the sources to pause.
	 * @return Result<int> The number of paused sources.
	 */
	public function pause( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `active` = false
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Sets the schedule for some sources.
	 *
	 * @param list<int> $ids The IDs of the sources to schedule.
	 * @param Schedule  $schedule The schedule.
	 * @return Result<int> The number of scheduled sources.
	 */
	public function schedule( array $ids, Schedule $schedule ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array( $schedule->toString() );
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `schedule` = %s
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Removes the schedule for some sources.
	 *
	 * @param list<int> $ids The IDs of the sources to unschedule.
	 * @return Result<int> The number of unscheduled sources.
	 */
	public function unschedule( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `schedule` = NULL
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Updates the last updated time for some sources.
	 *
	 * @param list<int> $ids The IDs of the sources to update.
	 * @return Result<int> The number of updated sources.
	 */
	public function updateLastUpdateTime( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$now = Time::toHumanFormat( new DateTime() );
			$args = array( $now );
			$idList = $this->db->prepareList( $ids, '%d', $args );

			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `last_update` = %s
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Updates the last error for some sources.
	 *
	 * @param list<int>   $ids The IDs of the sources to update.
	 * @param string|null $lastError The error message, or null to remove it.
	 * @return Result<int> The number of sources that where changed.
	 */
	public function updateLastError( array $ids, ?string $lastError ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array( $lastError );

			$idList = $this->db->prepareList( $ids, '%d', $args );
			$result = $this->db->query(
				"UPDATE {$this->table}
                SET `last_error` = %s
                WHERE `id` IN ({$idList})",
				$args
			);
			return Result::Ok( (int) $result );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Deletes a source by its ID.
	 *
	 * @param int $id The ID of the source to delete.
	 * @return Result<int> The number of deleted sources.
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
	 * Deletes multiple sources by their IDs.
	 *
	 * @param list<int> $ids The IDs of the sources to delete.
	 * @return Result<int> The number of deleted sources.
	 */
	public function deleteManyByIds( array $ids ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		try {
			$args = array();
			$idList = $this->db->prepareList( $ids, '%d', $args );

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
	 * Modifies the settings for multiple sources.
	 *
	 * @param list<int>            $ids The IDs of the sources to configure.
	 * @param array<string, mixed> $settings The settings to set.
	 * @return Result<int> The number of configured sources.
	 */
	public function configure( array $ids, array $settings = array() ): Result {
		if ( count( $ids ) === 0 ) {
			return Result::Ok( 0 );
		}

		$result = $this->getManyByIds( $ids );

		if ( $result->isOk() ) {
			$num = 0;
			foreach ( $result->get() as $src ) {
				$src->settings->patch( $settings );

				$result = $this->replace( $src );

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

	protected function getColumnFormats(): array {
		return array(
			'id' => '%d',
			'name' => '%s',
			'url' => '%s',
			'active' => '%d',
			'schedule' => '%s',
			'last_update' => '%s',
			'last_error' => '%s',
			'settings' => '%s',
			'v4_slug' => '%s',
		);
	}

	/** @return array<string,mixed> The database row. */
	protected function sourceToRow( Source $source ): array {
		$settings = $source->settings->toArray();
		$settingsJson = addslashes( json_encode( $settings ) );

		return array(
			self::ID => $source->id,
			self::NAME => $source->name,
			self::URL => $source->url,
			self::ACTIVE => $source->isActive,
			self::SCHEDULE => $source->schedule ? $source->schedule->toString() : null,
			self::LAST_UPDATE => $source->lastUpdate ? Time::toHumanFormat( $source->lastUpdate ) : null,
			self::LAST_ERROR => $source->lastError ?: null,
			self::SETTINGS => $settingsJson,
			self::V4_ID => $source->v4Id,
			self::V4_SLUG => $source->v4Slug,
		);
	}

	/** @param array<string,mixed> $row The database row, as an associative array. */
	protected function rowToSource( array $row ): Source {
		$src = new Source();

		$src->id = (int) ( $row[ self::ID ] ?? 0 );
		$src->name = $row[ self::NAME ] ?? '';
		$src->url = $row[ self::URL ] ?? '';
		$src->isActive = filter_var( $row[ self::ACTIVE ] ?? false, FILTER_VALIDATE_BOOLEAN );
		$src->lastError = $row[ self::LAST_ERROR ] ?? null;
		$src->v4Id = ( (int) ( $row[ self::V4_ID ] ?? 0 ) ) ?: null;
		$src->v4Slug = $row[ self::V4_SLUG ] ?? '';

		$schedStr = trim( $row[ self::SCHEDULE ] ?? '' );
		if ( ! empty( $schedStr ) ) {
			$result = ScheduleFactory::fromString( $schedStr );
			$src->schedule = $result->getOr( null );

			if ( $result->isErr() ) {
				Logger::error( $result->error(), array( 'source' => $src ) );
			}
		}

		$lastUpStr = trim( $row[ self::LAST_UPDATE ] ?? '' );
		if ( ! empty( $lastUpStr ) ) {
			$src->lastUpdate = Time::createAndCatch( $lastUpStr );
		}

		$settingsStr = $row[ self::SETTINGS ] ?? '{}';
		$settingsStr = stripslashes( $settingsStr );
		$settingsArr = json_decode( $settingsStr, true );
		if ( is_array( $settingsArr ) ) {
			$src->settings = SourceSettings::fromArray( $settingsArr );
		} else {
			Logger::error( "Invalid JSON for feed source #{$src->id} settings: \"{$settingsStr}\"" );
			$src->settings = new SourceSettings();
		}

		return $src;
	}

	public function createTable(): void {
		if ( $this->db->tableExists( $this->table ) ) {
			return;
		}

		$this->db->delta(
			"CREATE TABLE {$this->table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) DEFAULT '',
                url VARCHAR(2000) DEFAULT '',
                active TINYINT(1) DEFAULT 1,
                schedule VARCHAR(50) DEFAULT '',
                last_update VARCHAR(100) DEFAULT '0000-00-00T00:00:00+00:00',
                last_error VARCHAR(2000) DEFAULT NULL,
                settings TEXT DEFAULT '',
                v4_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                v4_slug VARCHAR(255) DEFAULT '',
                PRIMARY KEY (id)
            ) {$this->db->charsetCollate};"
		);
	}
}
