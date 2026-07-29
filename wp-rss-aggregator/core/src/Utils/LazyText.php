<?php

namespace RebelCode\Aggregator\Core\Utils;

class LazyText {

	/**
	 * Resolves a string or lazy translation callback.
	 *
	 * @since 5.3.0
	 *
	 * @param string|callable():string $text Text or callback.
	 */
	public static function resolve( $text ): string {
		if ( ! is_string( $text ) && is_callable( $text ) ) {
			return (string) call_user_func( $text );
		}

		return (string) $text;
	}
}
