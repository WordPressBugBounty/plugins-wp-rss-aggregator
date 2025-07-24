<?php

namespace RebelCode\Aggregator\Core\Rpc;

use DomainException;

/** Represents a request for a single RPC call. */
class RpcAction {

	public string $id;
	/** @var list<mixed> */
	public array $args;

	/**
	 * Creates a new action.
	 *
	 * @param string      $id The ID of action.
	 * @param list<mixed> $args The arguments for the action.
	 */
	public function __construct( string $id, array $args ) {
		$this->id = $id;
		$this->args = $args;
	}

	/**
	 * Creates a special value that, when used as an action argument, will be
	 * replaced by the server with the result of a previously executed action.
	 *
	 * @param int        $actionNum The number of the action whose result to use, 0-based.
	 * @param int|string $index Optional array index or object prop to get from
	 *        the action's result value.
	 * @return array The argument value to use in the action.
	 */
	public static function result( int $actionNum, $index = null ): array {
		return array(
			'__rpcResult' => $actionNum,
			'__rpcIndex' => $index,
		);
	}

	/** @param array<string,mixed> $array */
	public static function fromArray( array $array ): self {
		$id = $array['id'] ?? null;
		$args = $array['args'] ?? null;

		if ( ! is_string( $id ) || ! is_array( $args ) ) {
			throw new DomainException( 'Invalid RPC action' );
		}

		return new self( $id, $args );
	}
}
