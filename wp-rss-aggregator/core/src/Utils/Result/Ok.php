<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils\Result;

use RebelCode\Aggregator\Core\Utils\Result;
use Throwable;
use LogicException;

/**
 * @template T
 * @extends Result<T>
 */
class Ok extends Result {

	/** @var T */
	protected $value;

	/**
	 * @param T $value
	 */
	public function __construct( $value ) {
		$this->value = $value;
	}

	/**
	 * @return T
	 */
	public function get() {
		return $this->value;
	}

	/**
	 * @template D
	 * @param D $default
	 * @return T|D
	 */
	public function getOr( $default ) {
		return $this->value;
	}

	/** @inheritDoc */
	public function getOrThrow( ?callable $factory = null ) {
		return $this->value;
	}

	public function error(): Throwable {
		throw new LogicException( 'Cannot get error from Ok result' );
	}

	public function isOk(): bool {
		return true;
	}

	public function isErr(): bool {
		return false;
	}
}
