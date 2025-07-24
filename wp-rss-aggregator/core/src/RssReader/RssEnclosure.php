<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

interface RssEnclosure {

	/** Retrieves the enclosure's URL. */
	public function getUrl(): ?string;

	/**
	 * Retrieves the enclosure's type.
	 *
	 * @psalm-return RssEnclosureType::*
	 */
	public function getType(): string;

	/** Retrieves the enclosure's length, in bytes. */
	public function getLength(): ?int;

	/** Retrieves the enclosure's duration. */
	public function getDuration(): ?int;

	/**
	 * Retrieves the enclosure's keywords.
	 *
	 * @return list<string>
	 */
	public function getKeywords(): array;
}
