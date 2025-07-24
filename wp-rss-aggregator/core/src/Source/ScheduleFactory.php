<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

use DomainException;
use RebelCode\Aggregator\Core\Source\Schedule\DailySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\HourlySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\MinuteSchedule;
use RebelCode\Aggregator\Core\Source\Schedule\MonthlySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\WeeklySchedule;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Types;

class ScheduleFactory {

	/** @return Result<Schedule|null> */
	public static function from( $var ): Result {
		if ( $var === null || $var instanceof Schedule ) {
			return Result::Ok( $var );
		}
		if ( is_string( $var ) ) {
			return self::fromString( $var );
		}
		if ( is_array( $var ) ) {
			return self::fromArray( $var );
		}
		throw new DomainException( 'Invalid source schedule: ' . Types::getType( $var ) );
	}

	/** @return Result<Schedule> */
	public static function fromString( string $str ): Result {
		$matches = array();
		if ( preg_match( '/^\d+(\w)/', $str, $matches ) ) {
			$letter = isset( $matches[1] ) ? $matches[1] : '';

			switch ( $letter ) {
				case 'm':
					return MinuteSchedule::parse( $str );
				case 'h':
					return HourlySchedule::parse( $str );
				case 'd':
					return DailySchedule::parse( $str );
				case 'w':
					return WeeklySchedule::parse( $str );
				case 'M':
					return MonthlySchedule::parse( $str );
			}
		}

		return Result::Err( 'Invalid schedule string' );
	}

	/**
	 * @param array<string,mixed> $array
	 * @return Result<Schedule>
	 */
	public static function fromArray( array $array ): Result {
		$type = $array['type'] ?? '';

		switch ( $type ) {
			case MinuteSchedule::TYPE:
				return Result::Ok( MinuteSchedule::fromArray( $array ) );
			case HourlySchedule::TYPE:
				return Result::Ok( HourlySchedule::fromArray( $array ) );
			case DailySchedule::TYPE:
				return Result::Ok( DailySchedule::fromArray( $array ) );
			case WeeklySchedule::TYPE:
				return Result::Ok( WeeklySchedule::fromArray( $array ) );
			case MonthlySchedule::TYPE:
				return Result::Ok( MonthlySchedule::fromArray( $array ) );
		}

		return Result::Err( 'Invalid schedule array' );
	}

	public static function fromWpSchedule( string $name, string $updateTime = '' ): ?Schedule {
		$wpSchedules = wp_get_schedules();
		$interval = $wpSchedules[ $name ]['interval'] ?? null;

		if ( $interval === null ) {
			return null;
		}

		$base = Time::parseTimeString( $updateTime );

		$numDays = max( 0, $interval / DAY_IN_SECONDS );
		if ( $numDays > 0 && $numDays === (int) floor( $numDays ) ) {
			return new DailySchedule( (int) $numDays, $base );
		}

		$numHours = max( 0, $interval / HOUR_IN_SECONDS );
		if ( $numHours > 0 && $numHours === (int) floor( $numHours ) ) {
			return new HourlySchedule( (int) $numHours, $base );
		}

		$numMins = max( 1, $interval / MINUTE_IN_SECONDS );
		return new MinuteSchedule( (int) $numMins );
	}
}
