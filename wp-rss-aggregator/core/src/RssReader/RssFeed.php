<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

/** @psalm-immutable */
interface RssFeed extends RssNode {

	/**
	 * Retrieves the root node to use for retrieving attributes and children.
	 *
	 * For RSS 2.0 feeds, this method should return a node that represents the <channel> element that is nested under
	 * the <rss> element.
	 *
	 * For Atom feeds, this method should return a node that represents the top-level <feed> element.
	 */
	public function getRootNode(): RssNode;

	/** Retrieves the name of the source of the content of the RSS feed. This is typically the name of the website. */
	public function getSourceName(): ?string;

	/** Retrieves the URL of the source of the content of the RSS feed. This is typically the URL of the website. */
	public function getSourceUrl(): ?string;

	/** Retrieves the URL of the RSS feed's channel image. */
	public function getImageUrl(): ?string;

	/**
	 * Retrieves the items in the RSS feed.
	 *
	 * @return iterable<RssItem>
	 */
	public function getItems( int $start = 0, ?int $count = null ): iterable;

	/** Retrieves the number of items in the RSS feed. */
	public function getNumItems(): int;

	/**
	 * Retrieves the XML namespaces used in the feed, as an associative array that maps the namespace name to its URL.
	 *
	 * Example:
	 * ```php
	 * [
	 *   'atom' => 'http://www.w3.org/2005/Atom',
	 *   'content' => 'http://purl.org/rss/1.0/modules/content/',
	 * ]
	 * ```
	 *
	 * @return array<string,string>
	 */
	public function getNamespaces(): array;

	/** Retrieves the update frequency that is recommended by the feed itself, if any. */
	public function getSuggestedUpdateFreq(): ?RssSynUpdate;
}
