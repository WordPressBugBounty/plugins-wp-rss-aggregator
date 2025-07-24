<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

interface ArraySerializable {

	/**
	 * Serializes the instance to an array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array;
}
