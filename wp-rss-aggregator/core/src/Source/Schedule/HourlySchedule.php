<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source\Schedule;

use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;

/** Represents an hourly schedule, for events that repeat after a fixed number of hours. */
class HourlySchedule implements Schedule {

	public const TYPE = 'hourly';

	public int $hours = 1;
	public int $base = -1;

	/**
	 * Constructor.
	 *
	 * @param int $hours The number of hours between occurrences.
	 * @param int $base The time of day, in seconds, to base occurrences on. 0-86399. Use -1 to ignore.
	 */
	public function __construct( int $hours = 1, int $base = -1 ) {
		$this->hours = $hours;
		$this->base = $base;
	}

	/** @inheritDoc */
	public function getNext( ?int $prev = null ): int {
		$prev = $prev ?? time();

		if ( $this->base >= 0 ) {
			$base = Time::getStartOfDay( $prev ) + $this->base;

			if ( $prev < $base ) {
				$base -= 86400; // Use yesterday's base if today's comes after prev
			}

			// Calculate how many occurrences should have happened between base
			// and prev, rounding up.
			$numOccurences = (int) ceil( ( $prev - $base ) / ( $this->hours * 3600 ) );

			return $base + ( $numOccurences * $this->hours * 3600 );
		} else {
			// If no base, simply add the hours to the timestamp
			$next = $prev + ( $this->hours * 3600 );
		}

		return $next;
	}

	/** @inheritDoc */
	public function explain(): string {
		if ( $this->base >= 0 ) {
			return sprintf(
				_n(
					'Every hour starting from %2$02d:00',
					'Every %1$d hours starting from %2$02d:00',
					$this->hours,
					'wpra'
				),
				$this->hours,
				$this->base,
			);
		} else {
			return sprintf(
				_n( 'Every hour', 'Every %d hours', $this->hours, 'wpra' ),
				$this->hours
			);
		}
	}

	/** @inheritDoc */
	public function toString(): string {
		$str = sprintf( '%dh', $this->hours );
		if ( $this->base >= 0 ) {
			$str .= ' from ' . date( 'H:i', $this->base );
		}
		return $str;
	}

	/** @inheritDoc */
	public static function parse( string $str ): Result {
		$matches = array();
		if ( preg_match( '/^(\d+)h(?:\s+from\s+(\d{1,2}:\d{1,2}))?$/', $str, $matches ) ) {
			$hours = (int) $matches[1];
			$base = -1;
			if ( count( $matches ) > 2 ) {
				$baseParts = explode( ':', $matches[2] ?? '', 2 );
				$base = ( intval( $baseParts[0] ) * 3600 ) + intval( $baseParts[1] * 60 );
			}
			return Result::Ok( new self( $hours, $base ) );
		} else {
			return Result::Err( 'Invalid hourly schedule string' );
		}
	}

	public function toArray(): array {
		return array(
			'type' => self::TYPE,
			'hours' => $this->hours,
			'base' => $this->base,
		);
	}

	public static function fromArray( array $array ): self {
		$hours = $array['hours'] ?? 1;
		$base = $array['base'] ?? -1;

		return new self( $hours, $base );
	}
}
