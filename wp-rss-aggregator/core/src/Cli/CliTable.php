<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli;

use DateTime;
use RebelCode\Aggregator\Core\Utils\Arrays;

use RebelCode\Aggregator\Core\Utils\Time;
use Traversable;

use function WP_CLI\Utils\format_items;

class CliTable {

	protected iterable $data;
	protected ?array $columnNames;
	protected ?array $columnsToShow;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>       $data The table data.
	 * @param array<string,string>|null $columnNames A mapping of row indices to column names. If null, the column names
	 *                                               will be detected from the first row.
	 * @param string[]|null             $columnsToShow The list of columns to show. If null, all columns will be shown.
	 */
	public function __construct(
		iterable $data,
		?array $columnNames = null,
		?array $columnsToShow = null
	) {
		$this->data = $data;
		$this->columnNames = $columnNames;
		$this->columnsToShow = $columnsToShow ?? array_keys( $this->columnNames ?? array() );
	}

	/**
	 * Sets the columns to show.
	 *
	 * @param string[]|null $columns The list of columns to show. If null, all columns will be shown.
	 * @return $this
	 */
	public function showColumns( ?array $columns = null ): self {
		$this->columnsToShow = $columns ?? array_keys( $this->columnNames ?? array() );

		return $this;
	}

	/**
	 * Sets the column names.
	 *
	 * @param array|null $columnNames mapping of row indices to column names. If null, the column names will be
	 *                                detected from the first row.
	 * @return $this
	 */
	public function columnNames( ?array $columnNames = null ): self {
		$this->columnNames = $columnNames;

		return $this;
	}

	/**
	 * Renders the table.
	 *
	 * @return $this
	 */
	public function render(): self {
		$data = Arrays::map(
			$this->data,
			function ( $item ) {
				$row = array();

				if ( $this->columnNames === null ) {
					$this->columnNames = static::detectColumns( $item );
				}

				foreach ( $this->columnsToShow ?? array() as $key ) {
					$value = $item[ $key ] ?? '';
					$col = $this->columnNames[ $key ] ?? $key;

					if ( $value instanceof Traversable ) {
						$value = Arrays::fromIterable( $value );
					}

					if ( is_array( $value ) ) {
						$row[ $col ] = implode( ', ', $value );
					} elseif ( $value instanceof DateTime ) {
						$row[ $col ] = $value->format( Time::HUMAN_FORMAT );
					} else {
						$row[ $col ] = (string) $value;
					}
				}

				return $row;
			}
		);

		$colNames = Arrays::map( $this->columnsToShow ?? array(), fn ( string $col ) => $this->columnNames[ $col ] ?? $col );

		format_items( 'table', $data, $colNames );

		return $this;
	}

	/**
	 * Detects the column names from the given row item.
	 *
	 * @param mixed $item The row item to detect the column names from.
	 * @return string[] The detected column names.
	 */
	protected static function detectColumns( $item ): array {
		if ( is_object( $item ) ) {
			return array_keys( get_object_vars( $item ) );
		} elseif ( is_array( $item ) ) {
			return array_keys( $item );
		} else {
			return array();
		}
	}

	/**
	 * Static constructor to make method chaining easier.
	 *
	 * @param array $data The data to create the table with.
	 * @return self The created instance.
	 */
	public static function create( iterable $data ): self {
		return new static( $data );
	}
}
