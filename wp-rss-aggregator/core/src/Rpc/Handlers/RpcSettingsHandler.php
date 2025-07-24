<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Settings;
use RebelCode\Aggregator\Core\Utils\Result;

class RpcSettingsHandler {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return Result<Settings>
	 */
	public function patch( array $values ): Result {
		$this->settings->patch( $values )->save();
		return Result::Ok( $this->settings->toArray() );
	}

	public function import( string $json ): Result {
		$data = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return Result::Err( json_last_error_msg() );
		}
		return $this->patch( $data );
	}
}
