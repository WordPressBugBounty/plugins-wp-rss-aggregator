<?php

namespace RebelCode\Aggregator\Core\Rpc;

interface RpcHandler {

	/**
	 * Handles an RPC method.
	 *
	 * @return mixed
	 */
	public function handle( string $method, array $args );
}
