<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source\Schedule;

use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;

/** Represents a weekly schedule, for events that repeat after a fixed number of weeks. */
class WeeklySchedule implements Schedule {

	public const TYPE = 'weekly';

	public int $weeks = 1;
	public int $day = -1;
	public int $time = -1;

	protected const DAYS = array(
		'Sun',
		'Mon',
		'Tue',
		'Wed',
		'Thu',
		'Fri',
		'Sat',
	);

	/**
	 * Constructor.
	 *
	 * @param int $weeks The number of weeks between occurrences.
	 * @param int $day The day of the week. 0-6 where 0 is Sunday. Use -1 to ignore.
	 * @param int $time The time in the day, in seconds. 0-86399. Use -1 to ignore.
	 */
	public function __construct( int $weeks = 1, int $day = -1, int $time = -1 ) {
		$this->weeks = $weeks;
		$this->day = $day % 7;
		$this->time = $time;
	}

	/** @inheritDoc */
	public function getNext( ?int $prev = null ): int {
		$prev = $prev ?? time();

		if ( $this->day >= 0 ) {
			$prev = Time::getStartOfWeek( $prev ) + ( $this->day * 86400 ) + Time::getTimeOfDay( $prev );
		}

		if ( $this->time >= 0 ) {
			$prev = Time::getStartOfDay( $prev ) + $this->time;
		}

		return $prev + ( $this->weeks * 604800 );
	}

	/** @inheritDoc */
	public function explain(): string {
		if ( $this->day >= 0 && $this->time >= 0 ) {
			return sprintf(
				_n( 'Every week on %2$s at %3$s', 'Every %1$d weeks on %2$s at %3$s', $this->weeks, 'wpra' ),
				$this->weeks,
				date( 'l', $this->getNext() ),
				date( 'H:i', $this->time )
			);
		} elseif ( $this->day >= 0 ) {
			return sprintf(
				_n( 'Every week on %2$s', 'Every %1$d weeks on %2$s', $this->weeks, 'wpra' ),
				$this->weeks,
				date( 'l', $this->getNext() ),
			);
		} elseif ( $this->time >= 0 ) {
			return sprintf(
				_n( 'Every week at %2$s', 'Every %1$d weeks at %2$s', $this->weeks, 'wpra' ),
				$this->weeks,
				date( 'H:i', $this->time )
			);
		} else {
			return sprintf(
				_n( 'Every week', 'Every %d weeks', $this->weeks, 'wpra' ),
				$this->weeks
			);
		}
	}

	/** @inheritDoc */
	public function toString(): string {
		$str = sprintf( '%dw', $this->weeks );

		if ( $this->day >= 0 ) {
			$str .= sprintf( ' on %s', self::DAYS[ $this->day % 7 ] );
		}

		if ( $this->time >= 0 ) {
			$str .= ' at ' . date( 'H:i', $this->time );
		}

		return $str;
	}

	/** @inheritDoc */
	public static function parse( string $str ): Result {
		$matches = array();

		if ( preg_match( '/^(\d+)w(?:\s+on\s+(\w+))?(?:\s+at\s+(\d{1,2}:\d{1,2}))?$/', $str, $matches ) ) {
			if ( ! empty( $matches[2] ) ) {
				$dayName = $matches[2];
				$day = array_search( $dayName, self::DAYS, true );

				if ( $day === false ) {
					return Result::Err( "Invalid weekly schedule string: unknown day \"$dayName\"" );
				}
			} else {
				$day = -1;
			}

			$time = ! empty( $matches[3] )
				? strtotime( $matches[3] . ':00', 0 )
				: -1;
			return Result::Ok( new WeeklySchedule( (int) $matches[1], $day, $time ) );
		} else {
			return Result::Err( 'Invalid weekly schedule string' );
		}
	}

	public function toArray(): array {
		return array(
			'type' => 'weekly',
			'weeks' => $this->weeks,
			'day' => $this->day,
			'time' => $this->time,
		);
	}

	public static function fromArray( array $array ): self {
		$weeks = $array['weeks'] ?? 1;
		$day = $array['day'] ?? -1;
		$time = $array['time'] ?? -1;

		return new WeeklySchedule( $weeks, $day, $time );
	}
}
