<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source\Schedule;

use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;

/** Represents a daily schedule, for events that repeat after a fixed number of days. */
class DailySchedule implements Schedule {

	public const TYPE = 'daily';

	public int $days = 1;
	public int $time = -1;

	/**
	 * Constructor.
	 *
	 * @param int $days The number of days between occurrences.
	 * @param int $time The time in the day, in seconds. 0-86399. Use -1 to ignore.
	 */
	public function __construct( int $days = 1, int $time = -1 ) {
		$this->days = $days;
		$this->time = $time;
	}

	/** @inheritDoc */
	public function getNext( ?int $prev = null ): int {
		$prev = $prev ?? time();

		if ( $this->time >= 0 ) {
			$prev = Time::getStartOfDay( $prev ) + $this->time;
		}

		return $prev + ( $this->days * 86400 );
	}

	/** @inheritDoc */
	public function explain(): string {
		if ( $this->time >= 0 ) {
			return sprintf(
				_n( 'Every day at %s', 'Every %1$d days at %2$s', $this->days, 'wpra' ),
				$this->days,
				date( 'H:i', $this->time )
			);
		} else {
			return sprintf(
				_n( 'Every day', 'Every %d days', $this->days, 'wpra' ),
				$this->days
			);
		}
	}

	/** @inheritDoc */
	public function toString(): string {
		$str = sprintf( '%dd', $this->days );

		if ( $this->time >= 0 ) {
			$str .= ' ' . date( 'H:i', $this->time );
		}

		return $str;
	}

	/** @inheritDoc */
	public static function parse( string $str ): Result {
		$matches = array();

		if ( preg_match( '/^(\d+)d(?:\s+(\d{1,2}:\d{1,2}))?$/', $str, $matches ) ) {
			$time = ! empty( $matches[2] )
				? strtotime( $matches[2] . ':00', 0 )
				: -1;

			return Result::Ok( new self( (int) $matches[1], $time ) );
		} else {
			return Result::Err( 'Invalid daily schedule string' );
		}
	}

	public function toArray(): array {
		return array(
			'type' => self::TYPE,
			'days' => $this->days,
			'time' => $this->time,
		);
	}

	public static function fromArray( array $array ): self {
		$days = $array['days'] ?? 1;
		$time = $array['time'] ?? -1;

		return new self( $days, $time );
	}
}
