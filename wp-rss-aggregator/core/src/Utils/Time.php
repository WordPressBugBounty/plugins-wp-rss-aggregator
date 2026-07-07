<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use function get_option;
use function get_gmt_from_date;
use function wp_resolve_post_date;
use function wp_timezone;
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
	 * Creates a UTC DateTime from a WordPress MySQL date string.
	 *
	 * @since 5.2.1
	 *
	 * @param string $datetime The date-time string.
	 * @return DateTime|null The date-time object, or null if the string is invalid or empty.
	 */
	private static function createUtcFromMysqlDate( string $datetime ): ?DateTime {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return null;
		}

		$utc = new DateTimeZone( 'UTC' );
		$date = DateTime::createFromFormat( '!Y-m-d H:i:s', $datetime, $utc );
		$errors = DateTime::getLastErrors();

		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->format( static::HUMAN_FORMAT ) === $datetime ? $date : null;
	}

	/**
	 * Resolves a WordPress post date pair into a DateTime at the correct UTC instant.
	 *
	 * WordPress stores two columns: the local `post_date` and the UTC `post_date_gmt`.
	 * The GMT value is treated as canonical when present, because it represents the real
	 * publish instant and remains stable if the site's timezone setting changes later.
	 * When GMT is missing, this helper falls back to WordPress' local-date resolution and
	 * conversion APIs.
	 *
	 * @since 5.2.1
	 *
	 * @param string $localDate The local post date string (`post_date`).
	 * @param string $gmtDate   The UTC post date string (`post_date_gmt`).
	 * @return DateTime|null The date-time in UTC, or null if it cannot be resolved.
	 */
	public static function fromWpPostDate( string $localDate, string $gmtDate ): ?DateTime {
		$gmtDateTime = self::createUtcFromMysqlDate( $gmtDate );
		if ( $gmtDateTime !== null ) {
			return $gmtDateTime;
		}

		$resolved = wp_resolve_post_date( $localDate, $gmtDate );
		if ( $resolved === false ) {
			return null;
		}

		$gmt = get_gmt_from_date( $resolved );

		try {
			return new DateTime( $gmt, new DateTimeZone( 'UTC' ) );
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
	 * Normalizes a date/time value into WordPress local and GMT post date strings.
	 *
	 * This ensures that the given instant is stored in both WordPress post date columns:
	 * `post_date` in the site's configured timezone and `post_date_gmt` in UTC.
	 *
	 * @since 5.0.3
	 * @since 5.2.1 Uses WordPress timezone semantics for manual UTC offsets.
	 *
	 * @param DateTime|null $dt The date-time object to normalize.
	 * @return array{local:string,gmt:string}|null Normalized post date strings, or null if no date is given.
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
	 * This method follows WordPress' wp_timezone() behavior. Manual UTC offsets are kept
	 * as fixed offsets instead of being guessed as real regions with DST rules.
	 *
	 * @since 5.2.1 Uses WordPress timezone semantics for manual UTC offsets.
	 *
	 * @return \DateTimeZone The site's timezone object. Defaults to UTC if unresolved.
	 */
	public static function getSiteTimezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$tz_string = get_option( 'timezone_string' );

		if ( $tz_string ) {
			// Normal case: WP stores a valid timezone like "Europe/Bucharest"
			return new DateTimeZone( $tz_string );
		}

		// Legacy fallback: mirror wp_timezone_string() for older WordPress versions.
		$offset = (float) get_option( 'gmt_offset' );
		$hours = (int) $offset;
		$minutes = $offset - $hours;

		$sign = ( $offset < 0 ) ? '-' : '+';
		$absHour = abs( $hours );
		$absMins = abs( $minutes * 60 );
		$tzOffset = sprintf( '%s%02d:%02d', $sign, $absHour, $absMins );

		try {
			return new DateTimeZone( $tzOffset );
		} catch ( Throwable $e ) {
			return new DateTimeZone( 'UTC' );
		}
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
	 * Get black friday activation status.
	 *
	 * @since 5.0.7
	 *
	 * @return bool if black Friday active.
	 */
	public static function isBlackFridayActive(): bool {
		// LA timezone
		$tz = new DateTimeZone( 'America/Los_Angeles' );

		// Current time in LA
		$now = new DateTime( 'now', $tz );

		// Black Friday 2025 window
		$start = new DateTime( '2025-11-23 00:00:00', $tz );
		$end   = new DateTime( '2025-12-01 23:59:59', $tz );

		return $now >= $start && $now <= $end;
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
