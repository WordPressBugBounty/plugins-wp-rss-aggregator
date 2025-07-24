<?php

namespace RebelCode\Aggregator\Core\Display;

use RebelCode\Aggregator\Core\IrPost;

interface LayoutInterface {

	/** @param iterable<IrPost> $posts */
	public function render( iterable $posts, DisplayState $state ): string;

	/** Gets the ID of the style to be enqueued for this layout. */
	public function getStyleId(): ?string;

	/** Gets the ID of the script to be enqueued for this layout. */
	public function getScriptId(): ?string;
}
