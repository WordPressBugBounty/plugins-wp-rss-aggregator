<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use ArrayIterator;
use DateTime;
use Generator;
use Iterator;
use OutOfRangeException;
use Throwable;
use Traversable;

class Arrays {

	/** @var bool Indicates a value that must be skipped. */
	protected bool $skip = false;

	private function __construct() {
	}

	/** Creates a skip value, used in mapping methods to skip a value. */
	public static function skip(): self {
		$instance = new self();
		$instance->skip = true;
		return $instance;
	}

	/**
	 * Gets the first element in a list. Useful when the list is expected to only contain a single item.
	 *
	 * @template T
	 * @param iterable<T>    $iterable The iterable to get the first element from.
	 * @param Throwable|null $throwable Optional error to return if the iterable is empty.
	 * @return Result<T> A result containing the first element, or the error if the iterable is empty.
	 */
	public static function first( iterable $iterable, ?Throwable $throwable = null ): Result {
		$iter = self::iterator( $iterable );

		if ( $iter->valid() ) {
			return Result::Ok( $iter->current() );
		} else {
			return Result::Err( $throwable ?? new OutOfRangeException( 'Cannot get first element from an empty list' ) );
		}
	}

	/**
	 * Wraps an iterable in an Iterator.
	 */
	public static function iterator( iterable $iterable ): Iterator {
		if ( is_array( $iterable ) ) {
			return new ArrayIterator( $iterable );
		} else {
			return $iterable;
		}
	}

	/**
	 * Maps all values in an iterable to an array using a callback.
	 *
	 * @template T
	 * @param iterable<T> $iterable The iterable to map.
	 * @param callable    $callback A callable that takes a value from the iterable and returns the mapped value. The
	 *                              callable also receives the key of the value and the iterable itself. The callable
	 *                              may return an {@link Arrays::skip()} value to skip an iteration.
	 * @return array An array of mapped values. Keys are preserved.
	 */
	public static function map( iterable $iterable, callable $callback ): array {
		return iterator_to_array( self::gmap( $iterable, $callback ) );
	}

	/**
	 * Maps all values in an iterable to a Generator using a callback.
	 *
	 * @template In
	 * @template Out
	 * @param iterable<In>     $iterable The iterable to map.
	 * @param callable(In):Out $valueCb A callable that takes a value from the iterable and returns the mapped value.
	 *                         The callable also receives the key of the value and the iterable itself. The callable
	 *                         may return an {@link Arrays::skip()} value to skip an iteration.
	 * @return Generator<Out> The Generator of mapped values. Keys are preserved.
	 */
	public static function gmap( iterable $iterable, ?callable $valueCb = null, ?callable $keyCb = null ): Generator {
		foreach ( $iterable as $key => $value ) {
			$newKey = $keyCb ? $keyCb( $key, $value, $iterable ) : $key;
			$newValue = $valueCb ? $valueCb( $value, $key, $iterable ) : $value;

			if ( ( $newKey instanceof Arrays && $newKey->skip ) || ( $newValue instanceof Arrays && $newValue->skip ) ) {
				continue;
			}

			yield $newKey => $newValue;
		}
	}

	/**
	 * Filters the vlaues in an iterable using a callback.
	 *
	 * @template K
	 * @template V
	 * @param iterable<K,V>                      $iterable The iterable to filter.
	 * @param callable(V, K, iterable<K,V>):bool $fn A function that takes the value, key, and iterable and returns
	 *        a boolean. True values will keep the item in the result. False values will omit the item from the result.
	 * @return Generator<K,V> An array of filtered values. Keys are preserved.
	 */
	public static function gfilter( iterable $iterable, callable $fn ): Generator {
		foreach ( $iterable as $key => $value ) {
			if ( $fn( $value, $key, $iterable ) ) {
				yield $key => $value;
			}
		}
	}

	/**
	 * @template T
	 * @template R
	 * @param iterable<T>                  $iterable
	 * @param callable(R, T, int|string):R $fn
	 * @param R                            $initial
	 * @return R
	 */
	public static function greduce( iterable $iterable, callable $fn, $initial = null ) {
		$result = $initial;

		foreach ( $iterable as $key => $value ) {
			$result = $fn( $result, $value, $key, $iterable );
		}

		return $result;
	}

	public static function gjoin( string $delim, iterable $iterable ): string {
		$res = self::greduce( $iterable, fn ( string $r, $val ) => $r . $delim . $val, '' );
		$dLen = strlen( $delim );

		if ( strlen( $res ) >= $dLen ) {
			return substr( $res, $dLen );
		} else {
			return $res;
		}
	}

	/**
	 * Finds the first index of a value in an iterable that satisfies a callback.
	 *
	 * @template T
	 * @param iterable<T>      $iterable The iterable to search.
	 * @param callable(T):bool $fn A function that takes a value and returns a boolean.
	 * @return int|null The index of the first value for which the callback returned true, null if the
	 *                  iterable was exhausted.
	 */
	public static function indexOf( iterable $iterable, callable $fn ): ?int {
		foreach ( $iterable as $key => $value ) {
			if ( $fn( $value ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Replaces all occurrences of a value in an array with a new value.
	 *
	 * @param array $array The array to search and replace in.
	 * @param mixed $search The value to search for.
	 * @param mixed $replace The new value to use in place of the original value.
	 * @return array A new array with the replaced values.
	 */
	public static function replace( array $array, $search, $replace ): array {
		foreach ( $array as $idx => $val ) {
			if ( $val === $search ) {
				$array[ $idx ] = $replace;
			}
		}
		return $array;
	}

	/**
	 * Creates an array from an iterable. If the iterable is already an array, it will be returned as given.
	 *
	 * @template T
	 * @param iterable<T> $iterable The iterable value to convert to an array.
	 * @param bool        $preserveKeys Whether to preserve keys or not. See {@link iterator_to_array()}.
	 * @return array<T>
	 */
	public static function fromIterable( iterable $iterable, bool $preserveKeys = true ): array {
		if ( $iterable instanceof Traversable ) {
			return iterator_to_array( $iterable, $preserveKeys );
		} else {
			return (array) $iterable;
		}
	}

	/**
	 * Make sure an array is an array all the way down.
	 *
	 * @param array $arg The array.
	 */
	public static function toArrayAll( array $arg ): array {
		foreach ( $arg as $key => $val ) {
			if ( is_array( $val ) ) {
				$arg[ $key ] = self::toArrayAll( $val );
			} elseif ( $val instanceof ArraySerializable || ( is_object( $val ) && method_exists( $val, 'toArray' ) ) ) {
				$arg[ $key ] = $val->toArray();
			} elseif ( $val instanceof DateTime ) {
				$arg[ $key ] = $val->format( DATE_ATOM );
			} else {
				$arg[ $key ] = $val;
			}
		}

		return $arg;
	}
}
