<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

/** @psalm-immutable */
interface RssCategory {

	/** Retrieves the category's slug-like identifier. */
	public function getTerm(): ?string;

	/** Retrieves the category's human-friendly name. */
	public function getLabel(): ?string;
}
