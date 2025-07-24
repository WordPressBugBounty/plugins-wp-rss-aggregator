<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use RebelCode\Aggregator\Core\Utils\Result\Ok;
use RebelCode\Aggregator\Core\Utils\Result\Err;
use Throwable;
use RuntimeException;
use WP_Error;

/**
 * @template-covariant T
 */
abstract class Result {

	/**
	 * Returns the result data. Should only be called if {@link isOk()} returns true.
	 *
	 * @return T
	 * @throws \LogicException If the result is an error.
	 */
	abstract public function get();

	/**
	 * Returns the result error. Should only be called if {@link isOk()} returns false.
	 *
	 * @return Throwable
	 * @throws \LogicException If the result is an ok.
	 */
	abstract public function error(): Throwable;

	/**
	 * @template U
	 * @param U $default
	 * @return T|U
	 */
	abstract public function getOr( $default );

	/**
	 * @param callable(\Throwable):\Throwable $factory A function that takes the error exception and returns a new one.
	 * @return T
	 * @throws Throwable If the result is an error.
	 */
	abstract public function getOrThrow( ?callable $factory = null );

	/**
	 * @psalm-assert-if-true Ok<T> $this
	 * @psalm-assert-if-false Err<T> $this
	 */
	abstract public function isOk(): bool;

	/**
	 * @psalm-assert-if-true Ok<T> $this
	 * @psalm-assert-if-false Err<T> $this
	 */
	abstract public function isErr(): bool;

	/**
	 * Extracts the first element from a Result list.
	 *
	 * @template I
	 * @psalm-assert Result<iterable<I>> $this
	 *
	 * @param string|null $errMsg Optional error message to use if the list is empty.
	 * @return Result<I>
	 */
	public function first( ?Throwable $emptyErr = null ): Result {
		if ( $this->isOk() ) {
			$iter = Arrays::iterator( $this->get() );

			if ( $iter->valid() ) {
				return self::Ok( $iter->current() );
			} else {
				$err = $emptyErr ?? new RuntimeException( __( 'The result list is empty', 'wp-rss-aggregator' ) );
				return self::Err( $err );
			}
		} else {
			return self::Err( $this->error() );
		}
	}

	/**
	 * @template U
	 * @param U $data
	 * @return Result<U>
	 */
	public static function Ok( $data ): Result {
		if ( $data instanceof Result ) {
			return $data;
		} else {
			return new Ok( $data );
		}
	}

	/**
	 * @param string|Throwable|null $error
	 * @return Result<mixed>
	 */
	public static function Err( $error ): Result {
		if ( $error instanceof Result ) {
			return $error;
		} elseif ( is_string( $error ) ) {
			return new Err( new RuntimeException( $error ) );
		} else {
			return new Err( $error ?? new RuntimeException( __( 'An unknown error occurred', 'wp-rss-aggregator' ) ) );
		}
	}

	/**
	 * @template U
	 * @param U|WP_Error $arg
	 * @return Result<U>
	 */
	public static function wrapWpError( $arg ): Result {
		if ( is_wp_error( $arg ) ) {
			return self::Err( $arg->get_error_message() );
		} else {
			return self::Ok( $arg );
		}
	}

	/**
	 * Pipes a series of result-returning functions.
	 *
	 * Each function receives all of the previous result values as arguments. The first argument is the previous value,
	 * the second argument is the one before that, etc.
	 *
	 * If none of the functions return an Err, the last function's result is returned.
	 * If a function returns an Err, that Err is returned. No further functions are called.
	 *
	 * @param list<callable(mixed...):mixed> $fns
	 * @return Result
	 */
	public static function pipe( array $fns ): Result {
		$values = array();
		$result = self::Ok( true );

		foreach ( $fns as $fn ) {
			$retVal = call_user_func_array( $fn, $values );

			if ( $retVal instanceof Result ) {
				$result = $retVal;
			} else {
				$result = self::Ok( $retVal );
			}

			if ( $result->isOk() ) {
				array_unshift( $values, $result->get() );
			} else {
				return $result;
			}
		}

		return $result;
	}
}
