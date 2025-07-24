<?php

namespace RebelCode\Aggregator\Core\Licensing;

use RebelCode\Aggregator\Core\Utils\ArraySerializable;

class Customer implements ArraySerializable {

	public string $name;
	public string $email;

	public function __construct( string $name, string $email ) {
		$this->name = $name;
		$this->email = $email;
	}

	public function toArray(): array {
		return array(
			'name' => $this->name,
			'email' => $this->email,
		);
	}

	public static function fromArray( array $array ): self {
		return new self( $array['name'] ?? '', $array['email'] ?? '' );
	}
}
