<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use SimplePie_Category;
use RebelCode\Aggregator\Core\RssReader\RssCategory;

/**
 * Adapter for SimplePie RSS feed item categories.
 *
 * @psalm-immutable
 */
class SpRssCategory implements RssCategory {

	protected SimplePie_Category $category;

	/** Constructor. */
	public function __construct( SimplePie_Category $category ) {
		$this->category = $category;
	}

	/** @inheritDoc */
	public function getTerm(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return wp_specialchars_decode( $this->category->get_term(), ENT_QUOTES );
	}

	/** @inheritDoc */
	public function getLabel(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return $this->category->get_label( true );
	}
}
