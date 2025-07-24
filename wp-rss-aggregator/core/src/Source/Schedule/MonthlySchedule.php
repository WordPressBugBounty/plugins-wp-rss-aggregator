<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source\Schedule;

use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Utils\Numbers;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;

/** Represents a monthly schedule, for events that repeat after a fixed number of months. */
class MonthlySchedule implements Schedule {

	public const TYPE = 'monthly';

	public int $months = 1;
	public int $day = -1;
	public int $time = -1;

	/**
	 * Constructor.
	 *
	 * @param int $months The number of months between occurrences.
	 * @param int $day The day of the month. 1-31. Use 0 or less to ignore.
	 * @param int $time The time in the day, in seconds. 0-86399. Use -1 to ignore.
	 */
	public function __construct( int $months = 1, int $day = -1, int $time = -1 ) {
		$this->months = $months;
		$this->day = $day;
		$this->time = $time;
	}

	/** @inheritDoc */
	public function getNext( ?int $prev = null ): int {
		$prev = $prev ?? time();

		$next = strtotime( "+{$this->months} months", $prev );

		if ( $this->day > 0 ) {
			$next = Time::getStartOfMonth( $next ) + ( ( $this->day - 1 ) * 86400 ) + Time::getTimeOfDay( $next );
		}

		if ( $this->time >= 0 ) {
			$next = Time::getStartOfDay( $next ) + $this->time;
		}

		return $next;
	}

	/** @inheritDoc */
	public function explain(): string {
		if ( $this->day >= 0 && $this->time >= 0 ) {
			return sprintf(
				_n( 'Every month, on the %2$s, at %3$s', 'Every %1$d months, on the %2$s, at %3$s', $this->months, 'wpra' ),
				$this->months,
				Numbers::formatOrdinal( $this->day ),
				date( 'H:i', $this->time )
			);
		} elseif ( $this->day >= 0 ) {
			return sprintf(
				_n( 'Every month on the %2$s', 'Every %1$d months on the %2$s', $this->months, 'wpra' ),
				$this->months,
				Numbers::formatOrdinal( $this->day )
			);
		} elseif ( $this->time >= 0 ) {
			return sprintf(
				_n( 'Every month at %2$s', 'Every %1$d months at %2$s', $this->months, 'wpra' ),
				$this->months,
				date( 'H:i', $this->time )
			);
		} else {
			return sprintf(
				_n( 'Every month', 'Every %d months', $this->months, 'wpra' ),
				$this->months
			);
		}
	}

	/** @inheritDoc */
	public function toString(): string {
		$str = sprintf( '%dM', $this->months );

		if ( $this->day > 0 ) {
			$str .= sprintf( ' on %d', $this->day );
		}

		if ( $this->time >= 0 ) {
			$str .= ' at ' . date( 'H:i', $this->time );
		}

		return $str;
	}

	/** @inheritDoc */
	public static function parse( string $str ): Result {
		$matches = array();

		if ( preg_match( '/^(\d+)M(?:\s+on\s+(\d+))?(?:\s+at\s+(\d{1,2}:\d{1,2}))?$/', $str, $matches ) ) {
			$day = ! empty( $matches[2] )
				? (int) $matches[2]
				: -1;
			$time = ! empty( $matches[3] )
				? strtotime( $matches[3] . ':00', 0 )
				: -1;
			return Result::Ok( new MonthlySchedule( (int) $matches[1], $day, $time ) );
		} else {
			return Result::Err( 'Invalid monthly schedule string' );
		}
	}

	public function toArray(): array {
		return array(
			'type' => 'monthly',
			'months' => $this->months,
			'day' => $this->day,
			'time' => $this->time,
		);
	}

	public static function fromArray( array $array ): self {
		$months = $array['months'] ?? 1;
		$day = $array['day'] ?? -1;
		$time = $array['time'] ?? -1;

		return new MonthlySchedule( $months, $day, $time );
	}
}
