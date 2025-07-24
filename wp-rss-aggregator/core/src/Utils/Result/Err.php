<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils\Result;

use RebelCode\Aggregator\Core\Utils\Result;
use Throwable;

/**
 * @template T
 * @extends Result<T>
 */
class Err extends Result {

	protected Throwable $error;

	public function __construct( Throwable $error ) {
		$this->error = $error;
	}

	/**
	 * @return never
	 * @throws T
	 */
	public function get(): void {
		throw $this->error;
	}

	/**
	 * @template D
	 * @param D $default
	 * @return D
	 */
	public function getOr( $default ) {
		return $default;
	}

	/** @inheritDoc */
	public function getOrThrow( ?callable $factory = null ) {
		if ( $factory === null ) {
			throw $this->error;
		} else {
			throw $factory( $this->error );
		}
	}

	public function error(): Throwable {
		return $this->error;
	}

	/** @return never-returns */
	public function throw(): void {
		throw $this->error;
	}

	public function isOk(): bool {
		return false;
	}

	public function isErr(): bool {
		return true;
	}
}
