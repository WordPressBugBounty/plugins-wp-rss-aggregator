<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Utils\ArraySerializable;
use RebelCode\Aggregator\Core\Settings\Setting;

class Settings implements ArraySerializable {

	private string $option;
	/** @var array<string,Setting> */
	private array $settings;
	/** @var array<string,mixed> */
	private array $data;

	/** @param array<string,Setting> $settings */
	public function __construct( string $option, array $settings = array() ) {
		$this->option = $option;
		$this->settings = $settings;
		$this->data = array();
	}

	public function load(): void {
		$this->data = get_option( $this->option, $this->settings );
	}

	public function save(): bool {
		return update_option( $this->option, $this->toArray() );
	}

	public function register( string $name ): Setting {
		return $this->settings[ $name ] = new Setting( $this, $name );
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( $name, $default = null ) {
		if ( array_key_exists( $name, $this->data ) ) {
			return $this->data[ $name ];
		}
		if ( array_key_exists( $name, $this->settings ) ) {
			return $this->settings[ $name ]->default;
		}
		return $default;
	}

	/** @param array<string,mixed> $array */
	public function patch( array $array ): self {
		foreach ( $array as $key => $value ) {
			if ( ! array_key_exists( $key, $this->settings ) ) {
				continue;
			}

			$value = $this->settings[ $key ]->prepare( $value );
			$this->data[ $key ] = $value;
		}

		return $this;
	}

	public function toArray(): array {
		$array = array();
		foreach ( $this->settings as $key => $setting ) {
			$array[ $key ] = $setting->get();
		}
		return $array;
	}
}
