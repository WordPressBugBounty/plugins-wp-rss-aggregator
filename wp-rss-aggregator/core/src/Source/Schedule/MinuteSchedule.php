<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source\Schedule;

use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Source\Schedule;

/** Represents a minute schedule, for events that repeat after a fixed number of minutes. */
class MinuteSchedule implements Schedule {

	public const TYPE = 'minute';

	public int $minutes;
	public int $base;

	/**
	 * Constructor.
	 *
	 * @param int $minutes The number of minutes between occurrences.
	 * @param int $base The time in the hour, in minutes, to base occurrences off of. 0-59. Use -1 to ignore.
	 */
	public function __construct( int $minutes, int $base = -1 ) {
		$this->minutes = $minutes;
		$this->base = $base;
	}

	/** @inheritdoc */
	public function getNext( ?int $prev = null ): int {
		$prev = $prev ?? time();
		if ( $this->base >= 0 ) {
			$base = Time::getStartOfDay( $prev ) + $this->base;

			if ( $prev < $base ) {
				$base -= 86400; // Use yesterday's base if today's comes after prev
			}

			// Calculate how many occurrences should have happened between base
			// and prev, rounding up.
			$numOccurences = (int) ceil( ( $prev - $base ) / ( $this->minutes * 3600 ) );

			return $base + ( $numOccurences * $this->minutes * 3600 );
		} else {
			$next = ( $this->base >= 0 )
			? Time::getStartOfHour( $prev ) + ( $this->base * 60 )
			: $prev;

			while ( $next <= $prev ) {
				$next += ( $this->minutes * 60 );
			}
		}

		return $next;
	}

	/** @inheritdoc */
	public function explain(): string {
		if ( $this->base >= 0 ) {
			return sprintf(
				_n(
					'Every minute, starting from xx:%2$s',
					'Every %1$d minutes, starting from xx:%2$02d',
					$this->minutes,
					'wpra'
				),
				$this->minutes,
				$this->base
			);
		} else {
			return sprintf(
				_n( 'Every minute', 'Every %d minutes', $this->minutes, 'wpra' ),
				$this->minutes
			);
		}
	}

	/** @inheritdoc */
	public function toString(): string {
		$str = sprintf( '%dm', $this->minutes );

		if ( $this->base >= 0 ) {
			$str .= sprintf( ' from 00:%02d', $this->base );
		}

		return $str;
	}

	/** @inheritdoc */
	public static function parse( string $str ): Result {
		$matches = array();

		if ( preg_match( '/^(\d+)m(?:\s*from 00:(\d+))?$/', $str, $matches ) ) {
			$minutes = (int) $matches[1];
			$base = (int) ( $matches[2] ?? -1 );
			return Result::Ok( new self( $minutes, $base ) );
		} else {
			return Result::Err( 'Invalid minute schedule string' );
		}
	}

	public function toArray(): array {
		return array(
			'type' => self::TYPE,
			'minutes' => $this->minutes,
			'base' => $this->base,
		);
	}

	public static function fromArray( array $array ): self {
		return new self( $array['minutes'] ?? 0, $array['base'] ?? -1 );
	}
}
