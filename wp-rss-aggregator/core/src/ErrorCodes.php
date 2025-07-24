<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

/**
 * @todo Check if it makes sense to have standardized error objects that use predefined codes.
 * @todo We could potentially use this to generate documentation for the error codes.
 * @todo The UI could also use this to display better error messages and provide self-help instructions.
 */
class ErrorCodes {

	/**
	 * The WordPress user for a post author could not be created because the author has no name or email information.
	 */
	public static function E_IRA_001(): string {
		return 'Cannot create a WordPress user for post author: name and email are missing.';
	}
}
