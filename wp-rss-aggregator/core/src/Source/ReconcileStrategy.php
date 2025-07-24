<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

/** The possible values for the {@link Source::reconcileStrategy()} setting. */
interface ReconcileStrategy {

	/** Existing items are overwritten by the incoming item. */
	public const OVERWRITE = 'overwrite';
	/** Existing items are preserved, and incoming items are discarded. */
	public const PRESERVE = 'preserve';
}
