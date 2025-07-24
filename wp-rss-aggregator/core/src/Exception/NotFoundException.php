<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when something is not found.
 *
 * This exception can be detected by code to handle the case where a thing was not found. For instance, it is detected
 * by the {@link Server} to return a 404 response.
 */
class NotFoundException extends RuntimeException {

	/** The value that was used to refer to the thing that was not found. */
	public ?string $ref;

	/**
	 * Constructor.
	 *
	 * @param string $ref The value that was used to refer to the thing that was not found.
	 */
	public function __construct( $message = '', ?string $ref = null, int $code = 0, Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->ref = $ref;
	}
}
