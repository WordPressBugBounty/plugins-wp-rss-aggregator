<?php

namespace RebelCode\Aggregator\Core\Rpc;

use RebelCode\Aggregator\Core\Utils\Result;
use Throwable;

/**
 * Uses a WordPress filter to handle RPC action methods. No arg hydration is
 * performed, unlike the class handler.
 */
class RpcFilterHandler implements RpcHandler {

	private string $pattern;

	/**
	 * @param string $pattern A printf-style pattern string. Any %s tokens will
	 *        be replaced with the RPC action's method.
	 */
	public function __construct( string $pattern ) {
		$this->pattern = $pattern;
	}

	public function handle( string $method, array $args ) {
		$callback = apply_filters( sprintf( $this->pattern, '*' ), null );
		$callback = apply_filters( sprintf( $this->pattern, $method ), $callback );

		if ( ! is_callable( $callback ) ) {
			return Result::Err( "Unknown rpc method: \"{$method}\"" );
		}

		try {
			$return = call_user_func_array( $callback, $args );
			return Result::Ok( $return );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}
}
