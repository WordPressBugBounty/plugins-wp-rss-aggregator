<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

/** @psalm-immutable */
interface RssAuthor {

	/** Retrieves the author's full name. */
	public function getName(): ?string;

	/** Retrieves the author's email address. */
	public function getEmail(): ?string;

	/** Retrieves the URI to the author's website. */
	public function getUri(): ?string;
}
