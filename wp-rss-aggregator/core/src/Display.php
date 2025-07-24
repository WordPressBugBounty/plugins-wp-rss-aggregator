<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Display\DisplaySettings;

class Display {

	public ?int $id;
	public string $name;
	/** @var list<int> */
	public array $sources;
	/** @var list<string> */
	public array $folders;
	public DisplaySettings $settings;
	public string $v4Slug;

	/**
	 * Constructor.
	 *
	 * @param int|null             $id The ID of the display in the database.
	 * @param string               $name The display name.
	 * @param list<int>            $sources The IDs of the sources to display.
	 * @param list<int>            $folders The IDs of the folders to display.
	 * @param DisplaySettings|null $settings The display settings.
	 */
	public function __construct(
		?int $id,
		string $name = '',
		array $sources = array(),
		array $folders = array(),
		?DisplaySettings $settings = null,
		string $v4Slug = ''
	) {
		$this->id = $id;
		$this->name = $name;
		$this->sources = $sources;
		$this->folders = $folders;
		$this->settings = $settings ?? new DisplaySettings();
		$this->v4Slug = $v4Slug;
	}

	/**
	 * Creates a copy with a different ID.
	 *
	 * @param int|null $id The new ID.
	 * @return self The new instance.
	 */
	public function withId( ?int $id ): self {
		$clone = clone $this;
		$clone->id = $id;

		return $clone;
	}

	/**
	 * Creates a copy with a different name.
	 *
	 * @param string $name The new name.
	 * @return Display The new instance.
	 */
	public function withName( string $name ): self {
		$clone = clone $this;
		$clone->name = $name;
		return $clone;
	}

	/**
	 * Creates a copy of the display with some added sources.
	 *
	 * @param int[] $sources The source IDs to add.
	 * @return self The new instance.
	 */
	public function withAddedSources( array $sources ): self {
		if ( empty( $sources ) ) {
			return $this;
		}

		$clone = clone $this;
		$clone->sources = array_unique( array_merge( $this->sources, $sources ) );
		return $clone;
	}

	/**
	 * Creates a copy of the display with some added folders.
	 *
	 * @param int[] $folders The folder IDs to add.
	 * @return self The new instance.
	 */
	public function withAddedFolders( array $folders ): self {
		if ( empty( $folders ) ) {
			return $this;
		}

		$clone = clone $this;
		$clone->folders = array_unique( array_merge( $this->folders, $folders ) );
		return $clone;
	}

	/**
	 * Creates a copy of the display with some removed sources.
	 *
	 * @param list<int> $sources The source IDs to remove.
	 * @return self The new instance.
	 */
	public function withoutSources( array $sources ): self {
		if ( empty( $sources ) ) {
			return $this;
		}

		$clone = clone $this;
		$clone->sources = array_values( array_diff( $this->sources, $sources ) );
		return $clone;
	}

	/**
	 * Creates a copy of the display with some removed folders.
	 *
	 * @param list<int> $folders The folder IDs to remove.
	 * @return self The new instance.
	 */
	public function withoutFolders( array $folders ): self {
		if ( empty( $folders ) ) {
			return $this;
		}

		$clone = clone $this;
		$clone->folders = array_values( array_diff( $this->folders, $folders ) );
		return $clone;
	}

	/**
	 * Patches the source's data in bulk.
	 *
	 * @param iterable<string,mixed> $data The data to patch.
	 * @return self The same source instance.
	 */
	public function patch( iterable $data ): self {
		foreach ( $data as $key => $value ) {
			if ( $key === 'settings' ) {
				if ( is_iterable( $value ) ) {
					$this->settings->patch( $value );
				}
				continue;
			}

			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}

		return $this;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'id' => $this->id,
			'name' => $this->name,
			'sources' => $this->sources,
			'folders' => $this->folders,
			'settings' => $this->settings,
		);
	}

	/** @param array<string,mixed> $array */
	public static function fromArray( array $array ): Display {
		$id = $array['id'] ?? null;
		$name = $array['name'] ?? '';
		$sources = $array['sources'] ?? array();
		$folders = $array['folders'] ?? array();
		$settingsArr = $array['settings'] ?? array();
		$settings = new DisplaySettings( $settingsArr );

		return new self( $id, $name, $sources, $folders, $settings );
	}
}
