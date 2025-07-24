<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

class Bools {

	/**
	 * For converting various value types into booleans. Ported from v4.
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): bool {
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			return in_array( $value, array( 'true', '1', 'open', 'yes', 'on', 'y', 't' ) );
		}
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
