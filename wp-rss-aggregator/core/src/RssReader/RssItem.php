<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

use DateTime;

/** @psalm-immutable */
interface RssItem extends RssNode {

	/** Retrieves the feed that the item belongs to. */
	public function getFeed(): RssFeed;

	/** Retrieves the item's identifier, usually referred to as a "GUID". */
	public function getId(): ?string;

	/** Retrieves the item's permalink. */
	public function getPermalink(): ?string;

	/** Retrieves the item's title. */
	public function getTitle(): ?string;

	/** Retrieves the item's excerpt. */
	public function getExcerpt(): ?string;

	/** Retrieve's the item's content. */
	public function getContent(): ?string;

	/** Retrieves the date when the item was created. */
	public function getDateCreated(): ?DateTime;

	/** Retrieves the date when the item was last modified. */
	public function getDateModified(): ?DateTime;

	/**
	 * Retrieves the item's authors.
	 *
	 * @return list<RssAuthor>
	 */
	public function getAuthors(): array;

	/**
	 * Retrieves the item's categories.
	 *
	 * @return list<RssCategory>
	 */
	public function getCategories(): array;

	/**
	 * Retrieves the item's links.
	 *
	 * @return list<string>
	 */
	public function getLinks(): array;

	/**
	 * Retrieves the item's enclosures.
	 *
	 * @return list<RssEnclosure>
	 */
	public function getEnclosures(): array;
}
