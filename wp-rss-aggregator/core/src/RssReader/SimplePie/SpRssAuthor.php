<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use RebelCode\Aggregator\Core\RssReader\RssAuthor;
use SimplePie_Author;

/**
 * Adapter for SimplePie RSS feed item authors.
 */
class SpRssAuthor implements RssAuthor {

	protected SimplePie_Author $author;

	/** Constructor. */
	public function __construct( SimplePie_Author $author ) {
		$this->author = $author;
	}

	/** @inheritDoc */
	public function getName(): ?string {
		return $this->author->get_name();
	}

	/** @inheritDoc */
	public function getEmail(): ?string {
		return $this->author->get_email();
	}

	/** @inheritDoc */
	public function getUri(): ?string {
		return $this->author->get_link();
	}
}
