<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\RssReader\RssFeed;
use RebelCode\Aggregator\Core\RssReader\RssFeedInfo;
use RebelCode\Aggregator\Core\Utils\Result;

interface RssReader {

	/**
	 * Reads an RSS feed from a URI and creates an `RssFeed` object.
	 *
	 * @param string $uri The URI of the RSS feed.
	 * @param bool   $autoDiscover Whether to auto-discover feeds from the URI.
	 * @return Result<RssFeed>
	 */
	public function read( string $uri, bool $autoDiscover = false ): Result;

	/**
	 * Finds RSS feeds for a given URI and returns a list `RssFeedInfo` objects.
	 *
	 * @param string $uri The URI of the RSS feed.
	 * @return Result<RssFeedInfo[]>
	 */
	public function findFeeds( string $uri ): Result;
}
