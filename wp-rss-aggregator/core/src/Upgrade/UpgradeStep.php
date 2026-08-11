<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Upgrade;

/**
 * Defines a single versioned upgrade step.
 *
 * @since 5.4.0
 */
class UpgradeStep {

	public string $id;
	public string $version;
	/** @var callable():void */
	private $callback;

	/**
	 * @param non-empty-string $id Stable completed-step option key.
	 * @param non-empty-string $version Target plugin version for this step.
	 * @param callable():void $callback
	 *
	 * @since 5.4.0
	 */
	public function __construct( string $id, string $version, callable $callback ) {
		$this->id = $id;
		$this->version = $version;
		$this->callback = $callback;
	}

	/**
	 * Runs the upgrade step callback.
	 *
	 * @since 5.4.0
	 */
	public function run(): void {
		call_user_func( $this->callback );
	}
}
