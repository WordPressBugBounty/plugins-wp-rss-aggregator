<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

use DateTime;
use InvalidArgumentException;

/** @psalm-immutable */
class RssSynUpdate {

	public const HOURLY = 'hourly';
	public const DAILY = 'daily';
	public const WEEKLY = 'weekly';
	public const MONTHLY = 'monthly';
	public const YEARLY = 'yearly';

	/** @psalm-var RssSynUpdate::* */
	protected string $period;
	protected int $frequency;
	protected ?DateTime $base;

	/**
	 * Constructor.
	 *
	 * @psalm-param RssSynUpdate::* $period
	 * @param int           $frequency The frequency value.
	 * @param DateTime|null $base The base date/time, used to calculate the next update date/time.
	 */
	public function __construct( string $period, int $frequency = 1, ?DateTime $base = null ) {
		$this->period = $period;
		$this->frequency = $frequency;
		$this->base = $base;
	}

	/** Retrieves the period, as one of the values of the constants in this class. */
	public function getPeriod(): string {
		return $this->period;
	}

	/** Retrieves the frequency value. */
	public function getFrequency(): int {
		return $this->frequency;
	}

	/** Retrieves the base date/time, which used to calculate the next update date/time. */
	public function getBase(): ?DateTime {
		return $this->base;
	}

	/** Retrieves the frequency value in seconds. */
	public function asSeconds(): int {
		switch ( $this->period ) {
			case self::HOURLY:
				return $this->frequency * 3600;
			case self::DAILY:
				return $this->frequency * 86400;
			case self::WEEKLY:
				return $this->frequency * 604800;
			case self::MONTHLY:
				return $this->frequency * 2592000;
			case self::YEARLY:
				return $this->frequency * 31536000;
			default:
				throw new InvalidArgumentException( "Invalid period: {$this->period}" );
		}
	}

	/**
	 * Checks if a period string is valid.
	 *
	 * @psalm-mutation-free
	 * @psalm-assert RssSynUpdate::* $period
	 */
	public static function isValidPeriod( string $period ): bool {
		return in_array(
			$period,
			array(
				self::HOURLY,
				self::DAILY,
				self::WEEKLY,
				self::MONTHLY,
				self::YEARLY,
			),
			true
		);
	}

	/**
	 * Returns a valid period from a string, or null if the string is invalid.
	 *
	 * @psalm-mutation-free
	 * @psalm-return RssSynUpdate::*
	 */
	public static function periodFromString( string $period ): ?string {
		$period = strtolower( $period );
		return self::isValidPeriod( $period ) ? $period : null;
	}
}
