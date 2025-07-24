<?php

namespace RebelCode\Aggregator\Core\Display;

use RebelCode\Aggregator\Core\Utils\Strings;
use RebelCode\Aggregator\Core\Utils\Html;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\IrPost;
use RebelCode\Aggregator\Core\ImportedPost;

/** Common rendering logic for layouts. */
trait LayoutTrait {

	private DisplaySettings $ds;
	private SourcesStore $sourcesStore;

	public function __construct( DisplaySettings $ds, SourcesStore $sourcesStore ) {
		$this->ds = $ds;
		$this->sourcesStore = $sourcesStore;
	}

	/** Computes an item's URL according to the display settings. */
	private function getItemUrl( IrPost $post ): string {
		$embedUrl = $post->getEmbedUrl();

		if ( ! $this->ds->linkToEmbeds || empty( $embedUrl ) ) {
			return $post->url;
		}

		$linkTarget = $this->ds->linkTarget ?: '';

		if ( $linkTarget === 'lightbox' && $post->isYouTube() ) {
			$embedUrl .= '?autoplay=1';
		}

		return $embedUrl;
	}

	/** Renders a link tag, according to the display settings. */
	private function renderLink( string $children, string $href, string $class = '', string $style = '' ): string {
		$attrs = $this->linkAttrs( $href, $class );

		return sprintf( '<a %s style="%s">%s</a>', $attrs, esc_attr( $style ), $children );
	}

	private function linkAttrs( string $href, string $class = '' ): string {
		$target = '';
		switch ( $this->ds->linkTarget ?: '' ) {
			default:
			case 'self':
			case '_self':
				$target = '_self';
				break;
			case 'blank':
			case '_blank':
				$target = '_blank';
				break;
			case 'lightbox':
				$target = '_self';
				$class = rtrim( $class . ' colorbox' );
				break;
		}

		$linksNoFollow = $this->ds->linksNoFollow ?: false;
		$rel = ( $linksNoFollow ) ? 'noopener noreferrer nofollow' : '';

		return sprintf(
			'href="%s" class="%s" target="%s" rel="%s"',
			esc_attr( $href ),
			esc_attr( $class ),
			$target,
			$rel,
		);
	}

	/** Renders an item's title, if enabled and applicable. */
	private function renderTitle( IrPost $post, bool $links = true ): string {
		if ( ! $this->ds->enableTitles ) {
			return '';
		}

		$title = $post->title;
		if ( $this->ds->titleMaxLength !== null && $this->ds->titleMaxLength > 0 ) {
			$numWords = max( 0, $this->ds->titleMaxLength );
			$title = Strings::trimWords( $title, $numWords );
		}

		if ( ! $this->ds->linkTitles || ! $links ) {
			return $title;
		}

		return $this->renderLink( $title, $this->getItemUrl( $post ) );
	}

	/** Renders an item's excerpt, if enabled and applicable. */
	private function renderExcerpt( IrPost $post, bool $links = true ): string {
		if ( ! $this->ds->enableExcerpts ) {
			return '';
		}

		$excerpt = $post->excerpt ?: $post->content;
		$render_link = '';
		if ( ! empty( $this->ds->excerptMaxWords ) ) {
			$numWords = max( 0, $this->ds->excerptMaxWords );
			$excerpt = Strings::trimWords( $excerpt, $numWords, $this->ds->excerptEllipsis );
		}

		if ( $this->ds->enableReadMore && $links ) {
			$url = $this->getItemUrl( $post );
			$render_link = $this->renderLink( $this->ds->readMoreText, esc_url( $url ), 'more-link' );
		}

		return wp_strip_all_tags( $excerpt ) . $render_link;
	}

	/** Renders an item's author, if enabled and applicable. */
	private function renderAuthor( IrPost $post ): string {
		if ( ! $this->ds->enableAuthors ) {
			return '';
		}

		$authorName = trim( $post->getSingleMeta( ImportedPost::AUTHOR_NAME, '' ) );

		if ( empty( $authorName ) && $post->author !== null ) {
			$authorName = trim( $post->author->name ?? '' );
		}

		if ( empty( $authorName ) ) {
			return '';
		}

		return sprintf(
			'<span class="feed-author">%s</span>',
			rtrim($this->ds->authorPrefix) . ' ' . $authorName
		);
	}

	/** Renders the item's date, if applicable and enabled. */
	private function renderDate( IrPost $post, bool $block = false ): string {
		$date = $post->getDate();
		if ( ! $this->ds->enableDates || $date === null ) {
			return '';
		}

		if ( $this->ds->useRelDateFormat ) {
			$class = 'time-ago';
			$now = time();
			$post = $date->getTimestamp();
			$diff = human_time_diff( $now, $post );

			if ( $now > $post ) {
				$dateStr = sprintf( _x( '%s ago', 'Time ago format', 'wprss' ), $diff );
			} else {
				$dateStr = sprintf( _x( '%s from now', 'Time ago format (future)', 'wprss' ), $diff );
			}
		} else {
			$class = 'feed-date';
			$dateStr = wp_date( $this->ds->dateFormat, $date->getTimestamp() );
		}

		$tag = $block ? 'div' : 'span';

		return <<<HTML
            <{$tag} class="{$class}">
                {$this->ds->datePrefix} {$dateStr}
            </{$tag}>
        HTML;
	}

	/** Renders an item's source, if enabled and applicable. */
	private function renderSource( IrPost $post, bool $block = false, bool $links = true ): string {
		if ( ! $this->ds->enableSources ) {
			return '';
		}

		$name = '';
		$url = '';
		$sourceId = $post->getSingleMeta( ImportedPost::SOURCE, null );

		if ( $sourceId !== null ) {
			$sourceResult = $this->sourcesStore->getById( (int) $sourceId );
			if ( $sourceResult->isOk() ) {
				$source = $sourceResult->get();
				if ( $source && !empty($source->name) ) {
					$name = $source->name;
					$url = $source->url; // Use the canonical URL from the Source object
				}
			}
		}

		// Fallback to meta if name is still empty
		if ( empty($name) ) {
			$name = $post->getSingleMeta( ImportedPost::SOURCE_NAME, '' );
			// If URL was also not found from Source object, try from meta
			if (empty($url)) {
				$url = $post->getSingleMeta( ImportedPost::SOURCE_URL, '' );
			}
		}

		if ( empty($name) ) {
			return '';
		}

		$srcName = $name;
		if ( $this->ds->linkSource && $links && !empty($url) ) {
			$srcName = $this->renderLink( $name, $url );
		}

		$tag = $block ? 'div' : 'span';

		return <<<HTML
            <{$tag} class="feed-source">
                {$this->ds->sourcePrefix} {$srcName}
            </{$tag}>
        HTML;
	}

	/** Renders an item's audio player, if enabled and applicable. */
	private function renderAudioPlayer( IrPost $post ): string {
		$audioUrl = $post->getSingleMeta( ImportedPost::AUDIO_URL, '' );

		if ( ! $this->ds->enableAudioPlayer || empty( $audioUrl ) ) {
			return '';
		}

		$escapedUrl = esc_attr( $audioUrl );

		return <<<HTML
            <div class="wpra-feed-audio">
                <audio preload="none" controls src="{$escapedUrl}"></audio>
            </div>
        HTML;
	}

	private function renderItems( iterable $posts, callable $fn ): string {
		$result = '';
		foreach ( $posts as $post ) {
			$result .= $fn( $post );
		}

		if ( $result === '' ) {
			return sprintf( '<p>%s</p>', __( 'No feed items found.', 'wprss' ) );
		}

		return $result;
	}
}
