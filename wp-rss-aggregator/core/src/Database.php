<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use wpdb;
use RuntimeException;

class Database {

	public wpdb $wpdb;
	public string $prefix;
	public string $charsetCollate;
	private ?array $tables = null;

	public function __construct( wpdb $wpdb, string $prefix ) {
		$this->wpdb = $wpdb;
		$this->prefix = $prefix;
		$this->charsetCollate = $wpdb->get_charset_collate();
	}

	public function tableName( string $name ): string {
		return $this->wpdb->prefix . $this->prefix . $name;
	}

	/** @return int|bool */
	public function query( string $query, array $args = array() ) {
		if ( count( $args ) > 0 ) {
			$query = $this->wpdb->prepare( $query, ...$args );
		}

		$result = $this->wpdb->query( $query );

		if ( $this->wpdb->last_error ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}
		return $result;
	}

	public function getResults( string $query, array $args = array() ): array {
		if ( count( $args ) > 0 ) {
			$query = $this->wpdb->prepare( $query, ...$args );
		}

		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		if ( $this->wpdb->last_error ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}
		return $rows;
	}

	public function getRow( string $query, array $args = array(), int $row = 0 ): ?array {
		if ( count( $args ) > 0 ) {
			$query = $this->wpdb->prepare( $query, ...$args );
		}

		$rows = $this->wpdb->get_row( $query, ARRAY_A, $row );

		if ( $this->wpdb->last_error ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		if ( empty( $rows ) ) {
			return null;
		}
		return $rows;
	}

	public function getCol( string $query, array $args = array(), int $column = 0 ): array {
		if ( count( $args ) > 0 ) {
			$query = $this->wpdb->prepare( $query, ...$args );
		}

		$columns = $this->wpdb->get_col( $query, $column );

		if ( $this->wpdb->last_error ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		return $columns ?? array();
	}

	public function insert( string $table, array $data, array $format ): int {
		$result = $this->wpdb->insert( $table, $data, $format );

		if ( $this->wpdb->last_error || $result === false ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		if ( is_numeric( $this->wpdb->insert_id ) ) {
			return (int) $this->wpdb->insert_id;
		}

		throw new RuntimeException( 'Could not get the ID of the last inserted row' );
	}

	public function replace( string $table, array $data, array $format ): int {
		$result = $this->wpdb->replace( $table, $data, $format );

		if ( $this->wpdb->last_error || $result === false ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		return $result;
	}

	public function update( string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null ): int {
		$result = $this->wpdb->update( $table, $data, $where, $format, $whereFormat );

		if ( $this->wpdb->last_error || $result === false ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		return $result;
	}

	public function delete( string $table, array $where, ?array $whereFormat = null ): int {
		$result = $this->wpdb->delete( $table, $where, $whereFormat );

		if ( $this->wpdb->last_error || $result === false ) {
			throw new RuntimeException( $this->wpdb->last_error );
		}

		return $result;
	}

	/**
	 * Adds every value in the list to the args array and returns a new list
	 * where each value is replaced by the given format. Duplicate values are
	 * removed.
	 */
	public function prepareList( array $list, string $format = '', array &$args = array() ): string {
		$newList = array_unique( $list );
		foreach ( $newList as $i => $value ) {
			if ( $format ) {
				$args[] = $value;
				$newList[ $i ] = $format;
			} elseif ( is_int( $value ) ) {
				$args[] = $value;
				$newList[ $i ] = '%d';
			} elseif ( is_string( $value ) ) {
				$args[] = $value;
				$newList[ $i ] = '%s';
			}
		}

		return implode( ',', $newList );
	}

	public function pagination( ?int $num, int $page = 1 ): string {
		if ( $num === null ) {
			return '';
		}
		$offset = max( 0, $page - 1 ) * $num;
		return sprintf( 'LIMIT %d OFFSET %d', esc_sql( $num ), esc_sql( $offset ) );
	}

	public function normalizeOrder( string $order, string $default = 'ASC' ): string {
		switch ( strtolower( $order ) ) {
			case 'asc':
				return 'ASC';
			case 'desc':
				return 'DESC';
		}
		return $default;
	}

	public function delta( $queries = '' ) {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		return dbDelta( $queries );
	}

	public function tableExists( string $table ): bool {
		if ( $this->tables === null ) {
			$rows = (array) $this->wpdb->get_results( 'SHOW TABLES', 'ARRAY_A' );

			$this->tables = array();
			foreach ( $rows as $row ) {
				$name = reset( $row );
				$this->tables[ $name ] = true;
			}
		}

		return array_key_exists( $table, $this->tables );
	}
}
