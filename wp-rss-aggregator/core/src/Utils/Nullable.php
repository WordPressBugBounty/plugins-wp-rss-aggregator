<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

abstract class Nullable {

	/**
	 * Normalizes a value that can be null or considered {@link empty()} into a null value.
	 *
	 * @template T
	 * @param T|null $value The value to normalize.
	 * @return T|null The normalized value.
	 */
	public static function normalize( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( empty( $value ) ) {
			return null;
		} else {
			return $value;
		}
	}
}
