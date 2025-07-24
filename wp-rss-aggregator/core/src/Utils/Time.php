<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use DateTime;
use Throwable;

use function get_option;

abstract class Time {

	public const HUMAN_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Creates a {@link DateTime} object from a string and catches any exceptions.
	 *
	 * @param string $datetime The date-time string.
	 * @return DateTime|null The date-time object, or null if the string is invalid.
	 */
	public static function createAndCatch( string $datetime ): ?DateTime {
		try {
			return new DateTime( $datetime );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Formats a date-time object into a human-friendly string.
	 *
	 * @param DateTime|null $dateTime The date-time object.
	 * @param string        $default The default value to return if the date-time object is null.
	 * @return string The formatted string.
	 */
	public static function toHumanFormat( ?DateTime $dateTime, string $default = '' ): string {
		return $dateTime ? $dateTime->format( static::HUMAN_FORMAT ) : $default;
	}

	/** Gets a time-of-day as a number of seconds relative to that day's midnight. */
	public static function timeOfDaySeconds( int $hour, int $minute, int $second ): int {
		return $hour * 3600 + $minute * 60 + $second;
	}

	public static function secondsToTimeStr( int $seconds ): string {
		$hours = floor( $seconds / 3600 );
		$remainder = $seconds - ( $hours * 3600 );
		$minutes = floor( $remainder / 60 );
		$seconds = $remainder - ( $minutes * 60 );
		return sprintf( '%02d:%02d:%02d', $hours, $minutes, $seconds );
	}

	public static function parseTimeString( string $str ): int {
		$parts = explode( ':', $str, 3 );
		if ( count( $parts ) < 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) || ! is_numeric( $parts[2] ?? 0 ) ) {
			return -1;
		}
		$hrs = (int) $parts[0];
		$mins = (int) $parts[1];
		$secs = (int) ( $parts[2] ?? 0 );
		return ( $hrs * 3600 ) + ( $mins * 60 ) + $secs;
	}

	/** Gets the current WordPress timezone setting. */
	public static function getWpTz(): string {
		$tzString = get_option( 'timezone_string' );

		if ( empty( $tzString ) ) {
			$offset = (int) get_option( 'gmt_offset' );
			$tzString = timezone_name_from_abbr( '', $offset * 60 * 60, 1 );
		}

		return $tzString;
	}

	/** Switches to a different timezone and returns the previous timezone. */
	public static function switchTimezone( string $tz ): string {
		$prev = date_default_timezone_get();
		date_default_timezone_set( $tz );

		return $prev;
	}

	/** Switches to the WordPress timezone and returns the previous timezone. */
	public static function switchToWpTz(): string {
		return static::switchTimezone( static::getWpTz() );
	}

	/** Alternate version of {@link mktime()} that creates dates relative to the unix epoch. */
	public static function make(
		?int $h = null,
		?int $m = null,
		?int $s = null,
		?int $D = null,
		?int $M = null,
		?int $Y = null
	): int {
		return mktime( $h ?? 0, $m ?? 0, $s ?? 0, $M ?? 1, $D ?? 1, $Y ?? 1970 );
	}

	/** Gets the number of seconds since the start of the day for a given timestamp. */
	public static function getTimeOfDay( int $timestamp ): int {
		return self::make( (int) date( 'H', $timestamp ), (int) date( 'i', $timestamp ), (int) date( 's', $timestamp ) );
	}

	/**
	 * Switches to the WordPress timezone, runs the given function, then switches back to the previous timezone.
	 *
	 * @template T
	 * @param callable():T $fn The function to run.
	 * @return T The result of the function.
	 */
	public static function useWpTimezone( callable $fn ) {
		$prev = static::switchToWpTz();

		try {
			return $fn();
		} finally {
			static::switchTimezone( $prev );
		}
	}

	/**
	 * Gets the start of the hour for the given timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return int The timestamp at the start of the hour.
	 */
	public static function getStartOfHour( int $timestamp ): int {
		$minutes = (int) date( 'i', $timestamp );
		$seconds = (int) date( 's', $timestamp );

		return $timestamp - ( $minutes * 60 ) - $seconds;
	}

	/**
	 * Gets the start of the day for the given timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return int The timestamp at the start of the day.
	 */
	public static function getStartOfDay( int $timestamp ): int {
		$hourStart = static::getStartOfHour( $timestamp );
		$hour = (int) date( 'H', $hourStart );

		return $hourStart - ( $hour * 3600 );
	}

	/**
	 * Gets the start of the week for a given timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return int The timestamp at the start of the week.
	 */
	public static function getStartOfWeek( int $timestamp ): int {
		$dayOfWeek = (int) date( 'w', $timestamp );
		$dayStart = static::getStartOfDay( $timestamp );

		return $dayStart - ( $dayOfWeek * 86400 );
	}

	/**
	 * Gets the start of the month for the given timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return int The start at the start of the month.
	 */
	public static function getStartOfMonth( int $timestamp ): int {
		$firstDay = strtotime( 'first day of this month', $timestamp );

		return static::getStartOfDay( $firstDay );
	}
}
