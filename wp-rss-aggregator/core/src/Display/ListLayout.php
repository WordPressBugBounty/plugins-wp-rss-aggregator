<?php

namespace RebelCode\Aggregator\Core\Display;

use RebelCode\Aggregator\Core\Display\DisplayState;
use RebelCode\Aggregator\Core\Display\LayoutInterface;
use RebelCode\Aggregator\Core\Display\LayoutTrait;
use RebelCode\Aggregator\Core\IrPost;

class ListLayout implements LayoutInterface {

	use LayoutTrait;

	public function getStyleId(): ?string {
		return 'wpra-displays';
	}

	public function getScriptId(): ?string {
		return 'wpra-displays';
	}

	/** @param iterable<IrPost> $posts */
	public function render( iterable $posts, DisplayState $state ): string {
		if ( $this->ds->enableBullets ) {
			$listClass = 'wpra-item-list--bullets wpra-item-list--' . $this->ds->bulletStyle;
		}

		if ( $this->ds->enableBullets && $this->ds->bulletStyle === 'numbers' ) {
			$listType = 'ol';
		} else {
			$listType = 'ul';
		}

		$listStart = ( $state->page - 1 ) * $this->ds->numItems + 1;
		$listItems = $this->renderItems( $posts, fn ( IrPost $post ) => $this->item( $post ) );

		return <<<HTML
            <div class="wp-rss-aggregator wpra-list-template {$this->ds->htmlClass}">
                <{$listType} class="rss-aggregator wpra-item-list {$listClass}" start="{$listStart}">
                    {$listItems}
                </{$listType}>
            </div>
        HTML;
	}

	private function item( IrPost $post ): string {
		return <<<HTML
            <li class="wpra-item feed-item {$this->ds->htmlClass}">
                {$this->renderTitle($post)}

                <div class="wprss-feed-meta">
                    {$this->renderSource($post)}
                    {$this->renderDate($post)}
                    {$this->renderAuthor($post)}
                </div>

                {$this->renderAudioPlayer($post)}
            </li>
        HTML;
	}
}
