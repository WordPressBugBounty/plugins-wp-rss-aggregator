<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

use RebelCode\Aggregator\Core\Utils\ArraySerializable;
use RebelCode\Aggregator\Core\Utils\Result;

/** Represents a schedule for a recurring event. */
interface Schedule extends ArraySerializable {

	/**
	 * Gets the next occurrence.
	 *
	 * This method may be affected by the current PHP timezone. Make sure to set the correct timezone before calling
	 * this method to make sure that you get accurate results.
	 *
	 * @param int|null $prev The timestamp to start from. If null, the current time will be used.
	 * @return int The timestamp next occurrence.
	 */
	public function getNext( ?int $prev = null ): int;

	/** Gets a human-friendly explanation of the schedule. */
	public function explain(): string;

	/**
	 * Converts the schedule into a string.
	 *
	 * @return string The string representation of the schedule.
	 */
	public function toString(): string;

	/**
	 * Parses a schedule from a string.
	 *
	 * @param string $str The string to parse.
	 * @return Result<self> The parsed schedule, or an {@link Err} if the string could not be parsed.
	 */
	public static function parse( string $str ): Result;
}
