<?php

namespace RebelCode\Aggregator\Core\RssReader;

class RssFeedInfo {

	public string $title;
	public string $url;
	public int $numItems;

	/**
	 * Whether the title came from the feed, rather than being a placeholder such as "Unnamed feed" or the URL.
	 *
	 * @since 5.7.0
	 */
	public bool $hasTitle;

	/**
	 * @since 5.7.0 Added the `$hasTitle` parameter.
	 *
	 * @param string $title The feed title, or a placeholder when the feed has none.
	 * @param string $uri The feed URL.
	 * @param int    $numItems The number of items in the feed.
	 * @param bool   $hasTitle Whether `$title` came from the feed.
	 */
	public function __construct( string $title, string $uri, int $numItems, bool $hasTitle = true ) {
		$this->title = $title;
		$this->url = $uri;
		$this->numItems = $numItems;
		$this->hasTitle = $hasTitle;
	}

	public function toArray(): array {
		return array(
			'url' => $this->url,
			'title' => $this->title,
			'numItems' => $this->numItems,
			'hasTitle' => $this->hasTitle,
		);
	}
}
