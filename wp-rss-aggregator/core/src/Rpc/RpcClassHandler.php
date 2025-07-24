<?php

namespace RebelCode\Aggregator\Core\Rpc;

use Exception;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Types;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * An RPC handler is responsible for calling methods on an object and handling
 * the arguments.
 */
class RpcClassHandler implements RpcHandler {

	public object $object;
	/** @param array<string,callable(mixed):mixed> */
	public array $hydrators;

	/**
	 * @param object                              $object The object to call the methods on.
	 * @param array<string,callable(array):object $hydrators A mapping of class
	 *        names to functions, each taking a single argument and return the
	 *        new value. The functions may freely throw exceptions.
	 */
	public function __construct( object $object, array $hydrators = array() ) {
		$this->object = $object;
		$this->hydrators = $hydrators;
	}

	/** @param list<mixed> $args */
	public function handle( string $method, array $args ): Result {
		$class = get_class( $this->object );

		try {
			$refMethod = new ReflectionMethod( $this->object, $method );

			if ( ! $refMethod->isPublic() || str_starts_with( $refMethod->getName(), '_' ) ) {
				throw new Exception( "Unknown RPC action \"$class.{$method}\"" );
			}

			$pArgs = $this->hydrateArgs( $method, $args );
			$result = call_user_func_array( array( $this->object, $method ), $pArgs );

			return Result::Ok( $result );
		} catch ( Throwable $error ) {
			return Result::Err( $error );
		}
	}

	/**
	 * Hydrates method call arguments where necessary.
	 *
	 * @param string      $method The method name.
	 * @param list<mixed> $args The request arguments.
	 * @return list<mixed> The hydrated arguments.
	 * @throws ReflectionException
	 */
	private function hydrateArgs( string $method, array $args ): array {
		$refMethod = new ReflectionMethod( $this->object, $method );
		$params = $refMethod->getParameters();

		foreach ( $args as $i => $arg ) {
			$param = $params[ $i ] ?? null;
			if ( $param === null ) {
				break;
			}

			$pType = $param->getType();
			if ( $pType === null || ! ( $pType instanceof ReflectionNamedType ) ) {
				$args[ $i ] = $arg;
				continue;
			}

			$pTypeName = $pType->getName();
			$aTypeName = Types::getType( $arg );

			if ( $aTypeName !== $pTypeName ) {
				$hydrator = $this->hydrators[ $pTypeName ] ?? null;
				if ( $hydrator !== null ) {
					$arg = $hydrator( $arg );
				}
			}

			$args[ $i ] = $arg;
		}

		return $args;
	}
}
