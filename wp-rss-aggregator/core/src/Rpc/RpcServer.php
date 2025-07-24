<?php

namespace RebelCode\Aggregator\Core\Rpc;

use Throwable;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Capabilities;
use Generator;
use Exception;
use ArrayAccess;

/**
 * The RPC server handles requests as follows:
 *
 * The ID of each request action is expected to be in the form "<handler>.<method>".
 * The <handler> part is used to select the corresponding handler, and the
 * <method> part becomes the new ID of the action. The action is then delegated
 * to that handler, which can interpret that action ID as it sees fit.
 */
class RpcServer {

	/** The current version of the server. */
	public const VERSION = 1;
	public const NONCE_ACTION = 'wpra_rpc_request';

	/** @var array<string,RpcHandler> */
	private array $handlers;
	/** @var array<string,callable(object):mixed> */
	private array $transforms;

	/**
	 * Creates a new server.
	 *
	 * @param array<string,RpcHandler>             $handlers A mapping of handler IDs to
	 *                    handler instances.
	 * @param array<string,callable(object):mixed> $transforms A mapping of
	 *        class names to functions that take an object as argument and
	 *        return the new value to use as the result.
	 */
	public function __construct( array $handlers, array $transforms = array() ) {
		$this->handlers = $handlers;
		$this->transforms = $transforms;
	}

	/**
	 * Adds a handler.
	 *
	 * @param string     $id The handler ID.
	 * @param RpcHandler $handler The handler instance.
	 */
	public function addHandler( string $id, RpcHandler $handler ): self {
		$this->handlers[ $id ] = $handler;
		return $this;
	}

	/**
	 * Adds a result transform.
	 *
	 * @param string                $type The result type name.
	 * @param callable(mixed):mixed $fn A function that takes the result value
	 *        and returns the value to be returned in the response.
	 */
	public function addTransform( string $type, callable $fn ): self {
		$this->transforms[ $type ] = $fn;
		return $this;
	}

	/** Gets the nonce that clients need to send in requests to be authorized. */
	public function getNonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Creates a request from POST input, handles it, and sends back the
	 * response as JSON.
	 */
	public function serve(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Capabilities::SEE_AGGREGATOR ) ) {
			wp_send_json( array( 'error' => __( 'Unauthorized', 'wprss' ) ), 403 );
			exit;
		}

		$json = filter_input( INPUT_POST, 'rpc', FILTER_DEFAULT );
		ob_start();

		try {
			$request = RpcRequest::parseJson( $json );
			$request = apply_filters( 'wpra.rpc.request', $request );

			// enable this header as a column in your browser's Network tab
			// to be able to identify requests
			$actionIds = array_map( fn ( RpcAction $a ) => $a->id, $request->actions );
			header( 'X-WPRA-RPC-Action: ' . join( ', ', $actionIds ) );

			$results = $this->run( $request );
			$results = apply_filters( 'wpra.rpc.success', $results );
		} catch ( Throwable $exception ) {
			$exception = apply_filters( 'wpra.rpc.exception', $exception );
			$results = array( Result::Err( $exception ) );
		}

		$data = array();
		$hasError = false;
		foreach ( $results as $result ) {
			$isError = $result->isErr();
			$hasError = $hasError || $isError;
			$data[] = array(
				'type' => $isError ? 'error' : 'ok',
				'value' => $this->transform( $result ),
			);
		}

		$output = '';
		if ( WP_DEBUG ) {
			while ( ob_get_level() > 0 ) {
				$output .= ob_get_clean();
			}
		}

		$body = apply_filters(
			'wpra.rpc.body',
			(object) array(
				'version' => self::VERSION,
				'results' => $data,
				'output' => $output,
			)
		);

		wp_send_json( $body, $isError ? 500 : 200 );
		wp_die();
	}

	/** @return list<Result> */
	public function run( RpcRequest $request ): array {
		switch ( $request->type ) {
			case RpcRequest::BATCH:
				return $this->runBatch( $request->actions );
			case RpcRequest::STREAM:
				$err = $this->runAction( $request->actions[0], array(), true );
				return array( $err );
			default:
				return array( Result::Err( 'Invalid RPC request type' ) );
		}
	}

	/**
	 * @param list<RpcAction> $actions
	 * @return list<Result>
	 */
	private function runBatch( array $actions ): array {
		$results = array();
		$didErr = false;

		foreach ( $actions as $action ) {
			if ( $didErr ) {
				$results[] = Result::Err( 'Did not run due to a previous error.' );
				continue;
			}

			$result = $this->runAction( $action, $results );
			$didErr = $result->isErr();
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * @param RpcAction   $action The action to run.
	 * @param list<mixed> $prevResults The previous results, which may be used
	 *        in the action's arguments.
	 * @param bool        $stream If true and the result handler is a generated, the
	 *               results from the generator are streamed to the client using a
	 *               long polling connection.
	 */
	private function runAction( RpcAction $action, array $prevResults = array(), bool $stream = false ): Result {
		if ( $action->id === 'noop' ) {
			return Result::Ok( null );
		}

		if ( ! str_contains( $action->id, '.' ) ) {
			return Result::Err( "Invalid RPC action: \"{$action->id}\"" );
		}

		[$handlerId, $method] = explode( '.', $action->id, 2 );

		$handler = $this->handlers[ $handlerId ] ?? null;

		if ( $handler === null ) {
			return Result::Err( "Unknown RPC handler ID: \"$handlerId\"" );
		}

		$args = array();
		foreach ( $action->args as $arg ) {
			$args[] = $this->prepareArg( $arg, $prevResults );
		}

		try {
			$result = $handler->handle( $method, $args );
			$value = $result->getOr( null );

			if ( $value instanceof Generator ) {
				if ( $stream ) {
					$this->stream( $value );
					die( 0 );
				}
				// turn generators into arrays now, so they can be iterated
				// multiple times (ex. when an action uses this result as arg)
				$value = iterator_to_array( $value );
				$result = Result::Ok( $value );
			}

			return $result;
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Streams the results of a generator using a long poll connection.
	 *
	 * @param Generator<mixed> $generator
	 */
	public function stream( Generator $generator ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );

		foreach ( $generator as $result ) {
			$result = Result::Ok( $result );
			$json = json_encode(
				array(
					'type' => $result->isErr() ? 'error' : 'ok',
					'value' => $this->transform( $result ),
				)
			);
			echo $json;
			flush();
		}

		flush();
	}

	/**
	 * Prepares an action argument, using a previous action's result if the
	 * magic argument is detected.
	 *
	 * @param mixed       $arg The argument value.
	 * @param list<mixed> $results The previously generated results.
	 * @return mixed
	 * @throws Exception If a magic arg has an invalid result index.
	 */
	private function prepareArg( $arg, array $results ) {
		if ( is_array( $arg ) && array_key_exists( '__rpcResult', $arg ) ) {
			$targetNum = (int) $arg['__rpcResult'];
			$index = $arg['__rpcIndex'] ?? null;

			if ( $targetNum < 0 || $targetNum >= count( $results ) ) {
				throw new Exception( "Invalid action number {$targetNum} in arg" );
			}

			$value = $results[ $targetNum ]->get();

			if ( $index !== null ) {
				if ( ! is_int( $index ) && ! is_string( $index ) ) {
					throw new Exception( 'Invalid result index in arg' );
				}
				if ( is_array( $value ) || $value instanceof ArrayAccess ) {
					$value = $value[ $index ];
				} elseif ( is_object( $value ) ) {
					$value = $value->$index;
				} else {
					$type = gettype( $value );
					throw new Exception( "Cannot index {$type} result from action {$targetNum} in arg" );
				}
			}

			return $value;
		}

		return $arg;
	}

	/**
	 * @param mixed $subject
	 * @return mixed
	 */
	private function transform( $subject ) {
		if ( $subject instanceof Result ) {
			if ( $subject->isErr() ) {
				$ex = $subject->error();
				return array(
					'message' => $ex->getMessage(),
					'cause' => array(
						'file' => $ex->getFile(),
						'line' => $ex->getLine(),
						'trace' => $ex->getTrace(),
					),
				);
			}

			$subject = $subject->get();
		}

		if ( is_iterable( $subject ) ) {
			$list = array();
			foreach ( $subject as $key => $value ) {
				$list[ $key ] = $this->transform( $value );
			}
			return $list;
		}

		if ( is_object( $subject ) ) {
			$type = get_class( $subject );
			$fn = $this->transforms[ $type ] ?? null;

			if ( $fn === null ) {
				$newValue = get_object_vars( $subject );
			} else {
				$newValue = call_user_func( $fn, $subject );
			}

			return $this->transform( $newValue );
		}

		return $subject;
	}
}
