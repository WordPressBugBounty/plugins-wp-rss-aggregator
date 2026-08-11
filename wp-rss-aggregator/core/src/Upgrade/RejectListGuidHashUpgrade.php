<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Upgrade;

use RebelCode\Aggregator\Core\Database;
use RebelCode\Aggregator\Core\Store\RejectListStore;

/**
 * Upgrades reject-list storage from raw GUID primary keys to hash-key identity.
 *
 * @since 5.4.0
 */
class RejectListGuidHashUpgrade {

	private Database $db;
	private string $table;
	private RejectListStore $store;

	/**
	 * @param non-empty-string $table Fully-qualified reject-list table name.
	 * @param RejectListStore  $store Reject-list store used for table creation.
	 *
	 * @since 5.4.0
	 */
	public function __construct( Database $db, string $table, RejectListStore $store ) {
		$this->db = $db;
		$this->table = $table;
		$this->store = $store;
	}

	/**
	 * Runs the reject-list hash-key upgrade.
	 *
	 * @since 5.4.0
	 */
	public function run(): void {
		if ( ! $this->db->tableExists( $this->table ) ) {
			$this->store->createTable();
			return;
		}

		if ( ! $this->columnExists( RejectListStore::GUID_HASH ) ) {
			$this->db->query( "ALTER TABLE {$this->table} ADD COLUMN `guid_hash` VARCHAR(69) NOT NULL DEFAULT '' FIRST" );
		}

		$this->backfillHashes();
		$this->ensureFinalSchema();
	}

	/**
	 * Backfills empty hash keys from existing raw display GUID values using the
	 * same hashing code path as runtime writes.
	 *
	 * @since 5.4.0
	 */
	private function backfillHashes(): void {
		$rows = $this->db->getResults(
			"SELECT `guid`
			FROM {$this->table}
			WHERE `guid_hash` = '' OR `guid_hash` IS NULL"
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$guid = (string) ( $row['guid'] ?? '' );

			$this->db->update(
				$this->table,
				array( 'guid_hash' => RejectListStore::hashGuid( $guid ) ),
				array( 'guid' => $guid ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Ensures the reject-list table has the final hash-key primary schema.
	 *
	 * @since 5.4.0
	 */
	private function ensureFinalSchema(): void {
		$primary = $this->primaryKeyColumns();
		if ( $primary !== array( RejectListStore::GUID_HASH ) ) {
			if ( count( $primary ) > 0 ) {
				$this->db->query( "ALTER TABLE {$this->table} DROP PRIMARY KEY" );
			}

			$this->db->query( "ALTER TABLE {$this->table} MODIFY COLUMN `guid` TEXT NOT NULL" );
			$this->db->query( "ALTER TABLE {$this->table} ADD PRIMARY KEY (`guid_hash`)" );
		} else {
			$this->db->query( "ALTER TABLE {$this->table} MODIFY COLUMN `guid` TEXT NOT NULL" );
		}
	}

	/**
	 * Checks whether a column exists in the reject-list table.
	 *
	 * @param non-empty-string $column
	 *
	 * @since 5.4.0
	 */
	private function columnExists( string $column ): bool {
		$cols = $this->db->getCol(
			"SHOW COLUMNS FROM {$this->table} LIKE %s",
			array( $column )
		);

		return ! empty( $cols );
	}

	/**
	 * Gets the current primary-key column names.
	 *
	 * @return list<string>
	 *
	 * @since 5.4.0
	 */
	private function primaryKeyColumns(): array {
		$rows = $this->db->getResults( "SHOW INDEX FROM {$this->table} WHERE Key_name = 'PRIMARY'" );
		$columns = array();

		foreach ( $rows as $row ) {
			$column = $row['Column_name'] ?? $row['column_name'] ?? '';
			if ( is_string( $column ) && $column !== '' ) {
				$columns[] = $column;
			}
		}

		return $columns;
	}
}
