<?php

namespace RebelCode\Aggregator\Core\RssReader;

class RssFeedInfo {

	public string $title;
	public string $url;
	public int $numItems;

	public function __construct( string $title, string $uri, int $numItems ) {
		$this->title = $title;
		$this->url = $uri;
		$this->numItems = $numItems;
	}

	public function toArray(): array {
		return array(
			'url' => $this->url,
			'title' => $this->title,
			'numItems' => $this->numItems,
		);
	}
}
