<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

class Size implements ArraySerializable {

	public int $width;
	public int $height;

	public function __construct( int $width = 0, int $height = 0 ) {
		$this->width = $width;
		$this->height = $height;
	}

	/** Retrieves the aspect ratio for the size. */
	public function getAspectRatio(): float {
		return ( $this->height === 0 ) ? 0 : (float) $this->width / (float) $this->height;
	}

	/** Retrieves the area for the size. */
	public function getArea(): float {
		return $this->width * $this->height;
	}

	/** Checks if this size is at least as large as another size, across both dimensions. */
	public function isAtLeast( Size $other ): bool {
		return $this->width >= $other->width && $this->height >= $other->height;
	}

	/**
	 * Creates an array with the dimensions of this size.
	 *
	 * @return array{width: int, height: int} An array with two keys: "width" and "height".
	 */
	public function toArray(): array {
		return array(
			'width' => $this->width,
			'height' => $this->height,
		);
	}

	public function __toString(): string {
		return sprintf( '%dx%d', $this->width, $this->height );
	}

	/**
	 * Creates a size instance from an array that contains two keys: "width" and "height".
	 *
	 * @param array<string,int> $array The array.
	 */
	public static function fromArray( array $array ): Size {
		return new self( (int) ( $array['width'] ?? 0 ), (int) ( $array['height'] ?? 0 ) );
	}
}
