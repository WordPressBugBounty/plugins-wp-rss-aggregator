<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use function get_option;
use Throwable;
use DateTimeZone;
use DateTime;

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

	/**
	 * Normalizes a date/time value into a DateTimeImmutable object using the site's timezone.
	 *
	 * This ensures that any given input (string, timestamp, or DateTimeInterface) is
	 * consistently represented in the WordPress site's configured timezone. It avoids
	 * relying on PHP defaults or ambiguous timezone offsets.
	 *
	 * @since 5.0.3
	 *
	 * Usage:
	 * - Converts RSS/Atom feed dates, API values, or raw timestamps into WP-local time.
	 * - Provides a consistent basis for post_date, scheduling, and comparisons.
	 *
	 * @param string|int|\DateTimeInterface $value Date/time to normalize. Accepts:
	 *     - string  A valid date/time string parsable by DateTime.
	 *     - int     A Unix timestamp (in seconds).
	 *     - DateTimeInterface An existing DateTime/DateTimeImmutable object.
	 * @param \DateTimeZone|null            $timezone Optional. Explicit timezone to apply. If null,
	 *                the site's configured timezone (from get_site_timezone()) will be used.
	 *
	 * @return \DateTimeImmutable Normalized date/time in the correct timezone.
	 *
	 * @throws \Exception If the input cannot be parsed as a valid date/time.
	 */
	public static function normalizeDatetime( ?DateTime $dt ): ?array {
		if ( ! $dt ) {
			return null;
		}

		$utc = ( clone $dt )->setTimezone( new DateTimeZone( 'UTC' ) );
		$post_date_gmt = $utc->format( static::HUMAN_FORMAT );

		$site_tz = self::getSiteTimezone();
		$local   = $utc->setTimezone( $site_tz );
		$post_date_local = $local->format( static::HUMAN_FORMAT );

		return array(
			'local' => $post_date_local,
			'gmt'   => $post_date_gmt,
		);
	}

	/**
	 * Retrieves the site's timezone as a DateTimeZone object.
	 *
	 * @since 5.0.3
	 *
	 * WordPress stores the timezone in two ways:
	 * - timezone_string (preferred): e.g., "America/New_York".
	 * - gmt_offset (fallback): a float offset from UTC, without DST context.
	 *
	 * This method attempts to resolve timezone_string first. If not set, it falls back
	 * to gmt_offset and constructs an appropriate DateTimeZone. If resolution fails,
	 * UTC is returned as the ultimate fallback.
	 *
	 * This avoids using timezone_name_from_abbr() directly, which is unreliable for
	 * ambiguous offsets and DST handling.
	 *
	 * @return \DateTimeZone The site's timezone object. Defaults to UTC if unresolved.
	 */
	public static function getSiteTimezone(): DateTimeZone {
		$tz_string = get_option( 'timezone_string' );

		if ( $tz_string ) {
			// Normal case: WP stores a valid timezone like "Europe/Bucharest"
			return new DateTimeZone( $tz_string );
		}

		// Legacy fallback: only gmt_offset is set (may be float like 5.5, -4.75, etc.)
		$offset = (float) get_option( 'gmt_offset' );
		$seconds = (int) ( $offset * HOUR_IN_SECONDS );

		$name = timezone_name_from_abbr( '', $seconds, 0 );

		if ( $name === false ) {
			return new DateTimeZone( 'UTC' );
		}

		return new DateTimeZone( $name );
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

	/**
	 * Gets the current WordPress timezone setting.
	 *
	 * @deprecated 5.0.3 Use getSiteTimezone() instead.
	 */
	public static function getWpTz(): string {
		_deprecated_function(
			__METHOD__,
			'5.0.3',
			'Use getSiteTimezone() instead of this method'
		);

		$tzString = get_option( 'timezone_string' );

		if ( empty( $tzString ) ) {
			$offset = (int) get_option( 'gmt_offset' );
			$tzString = timezone_name_from_abbr( '', $offset * 60 * 60, 1 );
		}

		return $tzString;
	}

	/**
	 * Switches to a different timezone and returns the previous timezone.
	 *
	 * @deprecated 5.0.3 Use normalize_datetime() and DateTimeImmutable with WP timezone instead.
	 *
	 * @param string $tz The timezone identifier to switch to.
	 * @return string The previous timezone.
	 */
	public static function switchTimezone( string $tz ): string {
		_deprecated_function(
			__METHOD__,
			'5.0.3',
			'normalize_datetime() with a DateTimeImmutable instead of changing PHP timezone globally'
		);

		$prev = date_default_timezone_get();
		date_default_timezone_set( $tz );

		return $prev;
	}

	/**
	 * Switches to the WordPress timezone, runs the given function, then switches back to the previous timezone.
	 *
	 * @template T
	 * @deprecated 5.0.3 Use normalize_datetime() and DateTimeImmutable instead of temporarily switching PHP timezone.
	 *
	 * @param callable():T $fn The function to run.
	 * @return T The result of the function.
	 */
	public static function useWpTimezone( callable $fn ) {
		_deprecated_function(
			__METHOD__,
			'5.0.3',
			'Use normalize_datetime() with DateTimeImmutable to work in WP timezone without switching PHP global timezone'
		);

		$prev = static::switchToWpTz();

		try {
			return $fn();
		} finally {
			static::switchTimezone( $prev );
		}
	}

	/**
	 * Switches to the WordPress timezone and returns the previous timezone.
	 *
	 * @deprecated 5.0.3 Use normalize_datetime() with WP timezone instead of switching PHP global timezone.
	 *
	 * @return string The previous timezone.
	 */
	public static function switchToWpTz(): string {
		_deprecated_function(
			__METHOD__,
			'5.0.3',
			'Use normalize_datetime() with DateTimeImmutable instead of switching PHP timezone'
		);

		return static::switchTimezone( static::getWpTz() );
	}
}
