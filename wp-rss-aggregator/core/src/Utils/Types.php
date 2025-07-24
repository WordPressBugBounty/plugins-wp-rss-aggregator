<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

class Types {

	/** @param mixed $var */
	public static function getType( $var ): string {
		return is_object( $var ) ? get_class( $var ) : gettype( $var );
	}

	/**
	 * @template T
	 * @param mixed $input
	 * @param T     $target
	 * @return T
	 */
	public static function autoCast( $input, $target ) {
		switch ( gettype( $target ) ) {
			case 'string':
				return (string) $input;
			case 'integer':
				return (int) $input;
			case 'double':
				return (float) $input;
			case 'boolean':
				return filter_var( $input, FILTER_VALIDATE_BOOLEAN );
			case 'array':
				return (array) $input;
			case 'object':
				$tClass = get_class( $target );

				if ( is_object( $input ) ) {
					$iClass = get_class( $input );

					if ( $iClass === $tClass ) {
						return $input;
					}
				}

				if ( method_exists( $tClass, 'fromArray' ) ) {
					if ( is_string( $input ) ) {
						$array = json_decode( $input, true );
					} else {
						$array = (array) $input;
					}

					return call_user_func( array( $tClass, 'fromArray' ), $array );
				}

				return $input;
			default:
				return $input;
		}
	}
}
