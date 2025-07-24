<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\IrPost\IrAuthor;
use RebelCode\Aggregator\Core\Store\WpPostsStore;

class MergedFeed {

	private WpPostsStore $posts;
	private string $url;
	private string $title;
	private int $numItems;

	public function __construct( WpPostsStore $posts, string $url, string $title, int $numItems ) {
		$this->posts = $posts;
		$this->url = $url;
		$this->title = $title;
		$this->numItems = $numItems;
	}

	/**
	 * Renders and outputs the feed with the given posts. This will also output
	 * the appropriate headers.
	 *
	 * @param iterable<IrPost> $posts The posts to render in the feed.
	 */
	public function print(): void {
		$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

		header( "$protocol 200 OK" );
		header( 'Content-Type: application/rss+xml' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo trim( $this->render() );
	}

	/**
	 * Renders the feed with the given posts.
	 *
	 * @param iterable<IrPost> $posts The posts to render in the feed.
	 */
	public function render(): string {
		$charset = esc_attr( get_option( 'blog_charset' ) );
		$siteUrl = trailingslashit( get_site_url() );
		$selfUrl = esc_attr( get_feed_link( $this->url ) );
		$date = date( DATE_ATOM );
		$version = WPRA_VERSION;

		return <<<XML
        <?xml version="1.0" encoding="{$charset}" ?>
        <feed xmlns="http://www.w3.org/2005/Atom">
            <title>{$this->title}</title>
            <subtitle>{$this->title}</subtitle>
            <link href="{$selfUrl}" rel="self"/>
            <id>{$siteUrl}</id>
            <updated>{$date}</updated>
            <generator uri="https://wprssaggregator.com" version="{$version}">
                WP RSS Aggregator
            </generator>
            {$this->renderPosts()}
        </feed>
        XML;
	}

	protected function renderPosts(): string {
		$posts = $this->posts
			->getList( '', array(), $this->numItems )
			->getOr( array() );

		$result = '';
		foreach ( $posts as $post ) {
			$result .= $this->renderPost( $post );
		}

		return $result;
	}

	protected function renderPost( IrPost $post ): string {
		$url = esc_attr( $post->url );
		$pubDate = $post->datePublished ? $post->datePublished->format( DATE_ATOM ) : '';
		$modDate = $post->dateModified ? $post->dateModified->format( DATE_ATOM ) : '';

		return <<<XML
        <entry>
            <id>{$post->guid}</id>
            <title type="html">{$post->title}</title>
            <link href="{$url}" rel="alternate" />
            <updated>{$pubDate}</updated>
            <published>{$modDate}</published>
            <summary>{$post->excerpt}</summary>
            <content type="html">
                <![CDATA[{$post->content}]]>
            </content>
            {$this->renderAuthor($post->author)}
            {$this->renderSource($post)}
        </entry>
        XML;
	}

	protected function renderAuthor( ?IrAuthor $author ): string {
		if ( $author === null ) {
			return '';
		}

		$name = '';
		$email = '';
		$uri = '';

		if ( $author->name !== null ) {
			$name = "<name>{$author->name}</name>";
		}

		if ( $author->email !== null ) {
			$email = "<email>{$author->email}</email>";
		}

		if ( $author->link !== null ) {
			$uri = "<uri>{$author->link}</uri>";
		}

		return "<author>{$name}{$email}{$uri}</author>";
	}

	protected function renderSource( IrPost $post ): string {
		$srcName = $post->getSingleMeta( ImportedPost::SOURCE_NAME, '' );
		$srcUrl = $post->getSingleMeta( ImportedPost::SOURCE_URL, '' );

		if ( ! $srcName || ! $srcUrl ) {
			return '';
		}

		$nameTag = '';
		$urlTag = '';

		if ( $srcName ) {
			$nameTag = "<title>{$srcName[0]}</title>";
		}

		if ( $srcUrl ) {
			$urlTag = "<id>{$srcUrl[0]}</id>";
		}

		return "<source>{$nameTag}{$urlTag}</source>";
	}
}
