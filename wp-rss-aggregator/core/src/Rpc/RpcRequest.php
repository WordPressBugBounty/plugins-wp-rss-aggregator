<?php

namespace RebelCode\Aggregator\Core\Rpc;

use Exception;

class RpcRequest {

	public const BATCH = 'batch';
	public const STREAM = 'stream';

	public int $version;
	public string $type;
	public array $actions;

	/** @param list<RpcAction> $actions */
	public function __construct( int $version, string $type, array $actions ) {
		$this->version = $version;
		$this->type = $type;
		$this->actions = $actions;
	}

	public static function parseJson( string $json ): self {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( json_last_error_msg() );
		}

		if ( ! is_array( $data ) || ! is_int( $data['version'] ?? null ) ) {
			throw new Exception( 'Missing version in RPC request' );
		}

		$version = $data['version'];
		$type = $data['type'] ?? '';

		if ( $type !== self::BATCH && $type !== self::STREAM ) {
			throw new Exception( 'Invalid RPC request' );
		}

		if ( ! is_array( $data['actions'] ?? null ) ) {
			throw new Exception( 'Invalid RPC request' );
		}

		$actions = array();
		foreach ( $data['actions'] as $item ) {
			if ( ! is_array( $item ) ) {
				throw new Exception( 'Invalid v1 RPC request' );
			}
			$actions[] = RpcAction::fromArray( $item );
		}

		if ( $type === self::STREAM && count( $actions ) !== 1 ) {
			throw new Exception( 'Stream requests may only have 1 action.' );
		}

		return new self( $version, $type, $actions );
	}
}
