<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

/** Interface for objects that can be converted into a string. */
interface Stringable {

	public function __toString(): string;
}
