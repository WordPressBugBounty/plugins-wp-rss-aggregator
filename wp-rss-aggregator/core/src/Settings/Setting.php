<?php

namespace RebelCode\Aggregator\Core\Settings;

use RebelCode\Aggregator\Core\Settings;

class Setting {

	private Settings $settings;
	public string $name;
	public $default = null;
	private array $empty = array();
	private $middleware = null;

	public function __construct( Settings $settings, string $name ) {
		$this->settings = $settings;
		$this->name = $name;
	}

	public function setDefault( $default ): self {
		$this->default = $default;
		return $this;
	}

	public function middleware( callable $fn ): self {
		$this->middleware = $fn;
		return $this;
	}

	/**
	 * Adds values to consider as "empty". When the setting has this value,
	 * the default value is used instead.
	 *
	 * @param mixed $empty
	 */
	public function empty( array $empty ): self {
		$this->empty = $empty;
		return $this;
	}

	/** @return mixed */
	public function get() {
		$value = $this->settings->get( $this->name, $this->default );

		if ( count( $this->empty ) > 0 && in_array( $value, $this->empty ) ) {
			return $this->default;
		}

		return $value;
	}

	/** @return mixed */
	public function prepare( $value ) {
		if ( $this->middleware === null ) {
			return $value;
		}
		return call_user_func( $this->middleware, $value );
	}
}
