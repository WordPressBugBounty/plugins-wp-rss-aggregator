<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\WpPostsStore;
use RebelCode\Aggregator\Core\IrPost\IrAuthor;

class MergedFeed {

	private WpPostsStore $posts;
	private string $url;
	private string $title;
	private int $numItems;
	private string $order;
	private ?SourcesStore $sources;
	/** @var array<int, Source|null> Source lookups cached for the current render. */
	private array $sourceCache = array();

	public function __construct( WpPostsStore $posts, string $url, string $title, int $numItems, string $order = 'desc', ?SourcesStore $sources = null ) {
		$this->posts = $posts;
		$this->url = $url;
		$this->title = $title;
		$this->numItems = $numItems;
		$this->order = self::normalizeOrder( $order );
		$this->sources = $sources;
	}

	/** @param mixed $order */
	public static function normalizeOrder( $order ): string {
		return is_string( $order ) && strtolower( trim( $order ) ) === 'asc' ? 'asc' : 'desc';
	}

	/**
	 * add_feed() adds the slug to WordPress's feed alternation, as in `feed/(feed|rdf|rss|rss2|atom|wprss)/?$`,
	 * and never stores a rule keyed by the slug itself.
	 *
	 * @param mixed $rules The stored `rewrite_rules` option.
	 */
	public static function rewriteRulesHaveFeed( $rules, string $slug ): bool {
		if ( ! is_array( $rules ) ) {
			return false;
		}

		$pattern = '/\((?:[^()]*\|)?' . preg_quote( $slug, '/' ) . '(?:\|[^()]*)?\)\/\?\$$/';
		foreach ( array_keys( $rules ) as $regex ) {
			if ( preg_match( $pattern, (string) $regex ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders and outputs the feed with the given posts. This will also output
	 * the appropriate headers.
	 *
	 * @param iterable<IrPost> $posts The posts to render in the feed.
	 */
	public function print(): void {
		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) )
			: 'HTTP/1.1';
		$protocol = in_array( $protocol, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true )
			? $protocol
			: 'HTTP/1.1';

		header( "$protocol 200 OK" );
		header( 'Content-Type: ' . $this->contentType() );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML feed output is intentionally emitted as raw XML.
		echo trim( $this->render() );
	}

	protected function charset(): string {
		$charset = get_option( 'blog_charset' );

		return is_string( $charset ) && $charset !== '' ? $charset : 'UTF-8';
	}

	protected function contentType(): string {
		return 'application/atom+xml; charset=' . $this->charset();
	}

	protected function stripInvalidChars( string $text ): string {
		return preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text ) ?? '';
	}

	/** Escapes text for use in XML element content or attribute values. */
	protected function xml( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = $this->stripInvalidChars( $text );

		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Renders the feed with the given posts.
	 *
	 * @param iterable<IrPost> $posts The posts to render in the feed.
	 */
	public function render(): string {
		$charset = $this->xml( $this->charset() );
		$siteUrl = $this->xml( trailingslashit( get_site_url() ) );
		$selfUrl = $this->xml( get_feed_link( $this->url ) );
		$title = $this->xml( $this->title );
		$date = date( DATE_ATOM );
		$version = WPRA_VERSION;

		return <<<XML
        <?xml version="1.0" encoding="{$charset}" ?>
        <feed xmlns="http://www.w3.org/2005/Atom">
            <title>{$title}</title>
            <subtitle>{$title}</subtitle>
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
		$this->sourceCache = array();
		$posts = $this->posts
			->getList( '', array(), $this->numItems, 1, 'date', $this->order, 'publish', true )
			->getOr( array() );

		$result = '';
		foreach ( $posts as $post ) {
			$result .= $this->renderPost( $post );
		}

		return $result;
	}

	protected function renderPost( IrPost $post ): string {
		$guid = $this->xml( $post->guid );
		$title = $this->xml( $post->title );
		$url = $this->xml( esc_url_raw( $post->url ) );
		$excerpt = $this->xml( $post->excerpt );
		$content = str_replace( ']]>', ']]]]><![CDATA[>', $this->stripInvalidChars( $post->content ) );
		$published = $post->datePublished ?? $post->dateModified;
		$updated = $post->dateModified ?? $post->datePublished;
		$pubTag = $published ? '<published>' . $published->format( DATE_ATOM ) . '</published>' : '';
		$modTag = $updated ? '<updated>' . $updated->format( DATE_ATOM ) . '</updated>' : '';

		return <<<XML
        <entry>
            <id>{$guid}</id>
            <title type="text">{$title}</title>
            <link href="{$url}" rel="alternate" />
            {$modTag}
            {$pubTag}
            <summary>{$excerpt}</summary>
            <content type="html">
                <![CDATA[{$content}]]>
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
		$uri = '';

		if ( $author->name !== null ) {
			$name = '<name>' . $this->xml( $author->name ) . '</name>';
		}

		if ( $author->link !== null ) {
			$uri = '<uri>' . $this->xml( esc_url_raw( $author->link ) ) . '</uri>';
		}

		return "<author>{$name}{$uri}</author>";
	}

	private function findSource( int $id ): ?Source {
		if ( ! array_key_exists( $id, $this->sourceCache ) ) {
			$result = $this->sources->getById( $id );
			$this->sourceCache[ $id ] = $result->isOk() ? $result->get() : null;
		}

		return $this->sourceCache[ $id ];
	}

	protected function renderSource( IrPost $post ): string {
		$srcName = (string) $post->getSingleMeta( ImportedPost::SOURCE_NAME, '' );
		$srcUrl = (string) $post->getSingleMeta( ImportedPost::SOURCE_URL, '' );

		if ( ( $srcName === '' || $srcUrl === '' ) && $this->sources !== null ) {
			$srcId = $post->sources[0] ?? (int) $post->getSingleMeta( ImportedPost::SOURCE, 0 );
			$source = $srcId ? $this->findSource( (int) $srcId ) : null;

			if ( $source !== null ) {
				$srcName = $srcName !== '' ? $srcName : $source->name;
				$srcUrl = $srcUrl !== '' ? $srcUrl : $source->url;
			}
		}

		$nameTag = $srcName !== '' ? '<title>' . $this->xml( $srcName ) . '</title>' : '';
		$urlTag = $srcUrl !== '' ? '<id>' . $this->xml( esc_url_raw( $srcUrl ) ) . '</id>' : '';

		if ( $nameTag === '' && $urlTag === '' ) {
			return '';
		}

		return "<source>{$nameTag}{$urlTag}</source>";
	}
}
