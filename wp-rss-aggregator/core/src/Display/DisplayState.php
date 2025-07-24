<?php

namespace RebelCode\Aggregator\Core\Display;

class DisplayState {

	public int $page = 1;
	public int $numPages = 1;
	public int $totalItems = 0;

	public function __construct( int $page, int $numPages, int $totalItems ) {
		$this->page = $page;
		$this->numPages = $numPages;
		$this->totalItems = $totalItems;
	}
}
