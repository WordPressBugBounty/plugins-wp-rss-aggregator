<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Upgrade;

use RebelCode\Aggregator\Core\Utils\Result;
use Throwable;

/**
 * Runs versioned upgrade steps that were missed between two plugin versions.
 *
 * @since 5.4.0
 */
class UpgradeRunner {

	public const COMPLETED_STEPS_OPTION = 'wpra_completed_upgrade_steps';

	/** @var list<UpgradeStep> */
	private array $steps;

	/**
	 * @param list<UpgradeStep> $steps
	 *
	 * @since 5.4.0
	 */
	public function __construct( array $steps ) {
		$this->steps = $steps;
		usort(
			$this->steps,
			fn ( UpgradeStep $a, UpgradeStep $b ): int => version_compare( $a->version, $b->version )
		);
	}

	/**
	 * @return Result<list<string>>
	 *
	 * @since 5.4.0
	 */
	public function run( string $prev, string $curr ): Result {
		$completed = $this->getCompletedSteps();
		$ran = array();

		foreach ( $this->steps as $step ) {
			if ( ! $this->shouldRun( $step, $prev, $curr, $completed ) ) {
				continue;
			}

			try {
				$step->run();
			} catch ( Throwable $t ) {
				return Result::Err( $t );
			}

			$completed[ $step->id ] = true;
			$ran[] = $step->id;
			$this->saveCompletedSteps( $completed );
		}

		return Result::Ok( $ran );
	}

	/**
	 * @param array<string,bool> $completed
	 *
	 * @since 5.4.0
	 */
	private function shouldRun( UpgradeStep $step, string $prev, string $curr, array $completed ): bool {
		if ( isset( $completed[ $step->id ] ) ) {
			return false;
		}

		return version_compare( $prev, $step->version, '<' )
			&& version_compare( $step->version, $curr, '<=' );
	}

	/**
	 * Gets the completed upgrade-step IDs from the WordPress options table.
	 *
	 * @return array<string,true>
	 *
	 * @since 5.4.0
	 */
	private function getCompletedSteps(): array {
		$option = get_option( self::COMPLETED_STEPS_OPTION, array() );
		if ( ! is_array( $option ) ) {
			return array();
		}

		$completed = array();
		foreach ( $option as $stepId => $value ) {
			if ( is_string( $stepId ) && $value ) {
				$completed[ $stepId ] = true;
			}
		}

		return $completed;
	}

	/**
	 * Persists completed upgrade-step IDs.
	 *
	 * @param array<string,bool> $completed
	 *
	 * @since 5.4.0
	 */
	private function saveCompletedSteps( array $completed ): void {
		update_option( self::COMPLETED_STEPS_OPTION, $completed, false );
	}
}
