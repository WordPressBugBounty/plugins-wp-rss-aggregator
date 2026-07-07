<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use SimplePie_File;
use SimplePie;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Html;
use RebelCode\Aggregator\Core\RssReader\RssFeedInfo;
use RebelCode\Aggregator\Core\RssReader;
use RebelCode\Aggregator\Core\Importer\SourceFetchPolicy;

/** Adapter for a SimplePie RSS feed reader. */
class SpRssReader implements RssReader {

	private ?int $timeout;
	private string $sslCertPath;
	private string $userAgent;
	private bool $enableCache;
	private ?string $cacheDir;
	private ?int $cacheTtl;

	/** @since 5.2.1 */
	private SourceFetchPolicy $sourceFetchPolicy;

	/**
	 * Constructor.
	 *
	 * @since 5.2.1 Added the source fetch policy dependency.
	 *
	 * @param int|null              $timeout The timeout in seconds for fetching feeds.
	 * @param string                $sslCertPath The SSL certificate path.
	 * @param string                $userAgent The feed request user agent.
	 * @param bool                  $enableCache Whether SimplePie cache is enabled.
	 * @param string                $cacheDir The directory to use for caching feeds.
	 * @param int|null              $cacheTtl The time to live for cached feeds, in seconds, or null to use SimplePie's 3600 default.
	 * @param SourceFetchPolicy|null $sourceFetchPolicy Optional source fetch policy.
	 */
	public function __construct(
		?int $timeout = null,
		string $sslCertPath = '',
		string $userAgent = '',
		bool $enableCache = false,
		string $cacheDir = '',
		?int $cacheTtl = null,
		?SourceFetchPolicy $sourceFetchPolicy = null
	) {
		$this->timeout = $timeout;
		$this->sslCertPath = trim( $sslCertPath );
		$this->userAgent = trim( $userAgent );
		$this->enableCache = $enableCache;
		$this->cacheDir = $cacheDir;
		$this->cacheTtl = $cacheTtl;
		$this->sourceFetchPolicy = $sourceFetchPolicy ?? new SourceFetchPolicy();
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 * @return Result<SpRssFeed>
	 */
	public function read( string $uri, bool $autoDiscover = false ): Result {
		$feed = $this->createSimplePie( $uri, $autoDiscover );

		$feed->init();
		$feed->handle_content_type();

		$errors = (array) $feed->error();

		if ( empty( $errors ) ) {
			return Result::Ok( new SpRssFeed( $feed ) );
		} else {
			$directFeed = $this->tryDirectReadFallback( $uri, $errors[0] );
			if ( $directFeed !== null ) {
				return Result::Ok( $directFeed );
			}

			$message = $this->getNiceError( $errors[0] );
			return Result::Err( $message );
		}
	}

	/**
	 * Finds RSS feeds for a URI.
	 *
	 * @since 5.2.1 Policy-matched direct feed URLs can short-circuit discovery.
	 *
	 * @param string $uri The URI to inspect.
	 * @return Result<RssFeedInfo[]>
	 */
	public function findFeeds( string $uri ): Result {
		$uri = trim( $uri );

		if ( $this->sourceFetchPolicy->shouldSkipFeedDiscovery( $uri ) && $this->isLikelyDirectFeedUri( $uri ) ) {
			return Result::Ok(
				array(
					new RssFeedInfo( $uri, $uri, 0 ),
				)
			);
		}

		$spFeed = $this->createSimplePie( $uri, true );
		$spFeed->init();
		$feeds = $spFeed->get_all_discovered_feeds();

		if ( ! is_iterable( $feeds ) ) {
			return Result::Ok( array() );
		}

		if ( count( $feeds ) === 0 && count( $spFeed->get_items() ?? array() ) > 0 ) {
			$feeds[] = new SimplePie_File( $uri );
		}

		if ( count( $feeds ) === 0 ) {
			$directFeed = $this->tryDirectFeedFallback( $uri );
			if ( $directFeed !== null ) {
				return Result::Ok( array( $directFeed ) );
			}
		}

		$results = array();
		foreach ( $feeds as $feed ) {
			$spFeed = $this->createSimplePie( $feed );
			$spFeed->init();
			$spFeed->handle_content_type();

			$title = $spFeed->get_title() ?? _x( 'Unnamed feed', 'The title to show for found RSS feeds without a name', 'wp-rss-aggregator' );
			$title = Html::decodeEntities( $title );
			$numItems = $spFeed->get_item_quantity();
			$results[] = new RssFeedInfo( $title, $feed->url, $numItems );
		}

		return Result::Ok( $results );
	}

	/**
	 * Attempts to treat a URI as a direct feed after discovery fails.
	 *
	 * @since 5.2.1
	 *
	 * @param string $uri The URI to inspect.
	 * @return RssFeedInfo|null The direct feed info, or null when not parseable.
	 */
	protected function tryDirectFeedFallback( string $uri ): ?RssFeedInfo {
		if ( ! $this->isLikelyDirectFeedUri( $uri ) ) {
			return null;
		}

		$spFeed = $this->createSimplePie( $uri, false );
		$spFeed->force_feed( true );
		$spFeed->init();
		$spFeed->handle_content_type();

		$numItems = $spFeed->get_item_quantity();
		$title = $spFeed->get_title();

		if ( $numItems === 0 && $title === null ) {
			return null;
		}

		$title = $title ?? _x( 'Unnamed feed', 'The title to show for found RSS feeds without a name', 'wp-rss-aggregator' );

		return new RssFeedInfo( Html::decodeEntities( $title ), $uri, $numItems );
	}

	/**
	 * Attempts to read a failed URL as a direct feed.
	 *
	 * @since 5.2.1
	 *
	 * @param string $uri The URI to read.
	 * @param string $previousError The SimplePie error from the first read.
	 * @return SpRssFeed|null The RSS feed, or null when the fallback should not be used.
	 */
	protected function tryDirectReadFallback( string $uri, string $previousError ): ?SpRssFeed {
		if ( ! $this->isLikelyDirectFeedUri( $uri ) || ! $this->shouldTryDirectReadFallback( $previousError ) ) {
			return null;
		}

		$spFeed = $this->createSimplePie( $uri, false );
		$spFeed->force_feed( true );
		$spFeed->init();
		$spFeed->handle_content_type();

		$errors = (array) $spFeed->error();
		$numItems = $spFeed->get_item_quantity();
		$title = $spFeed->get_title();

		if ( ! empty( $errors ) || ( $numItems === 0 && $title === null ) ) {
			return null;
		}

		return new SpRssFeed( $spFeed );
	}

	/**
	 * Checks whether a read error is eligible for the direct feed fallback.
	 *
	 * @since 5.2.1
	 *
	 * @param string $error The SimplePie error.
	 * @return bool True when the error can be retried as a direct feed.
	 */
	protected function shouldTryDirectReadFallback( string $error ): bool {
		$error = strtolower( $error );

		return str_contains( $error, 'feed could not be found' )
			&& str_contains( $error, 'content-type' );
	}

	/**
	 * @psalm-suppress ImpureMethodCall
	 *
	 * @since 5.2.1 Applies source fetch policy cURL options.
	 *
	 * @param string|SimplePie_File $source A string URL or SimplePie_File object.
	 * @param bool                  $autoDiscover Whether auto-discovery is enabled.
	 * @return SimplePie The configured SimplePie instance.
	 */
	protected function createSimplePie( $source, bool $autoDiscover = false ): SimplePie {
		if ( ! class_exists( SimplePie::class ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}

		$feed = new SimplePie();
		$sourceUrl = $this->getSourceUrl( $source );

		if ( $source instanceof SimplePie_File ) {
			$feed->set_file( $source );
		} elseif ( $this->shouldPrefetchSource( $sourceUrl ) ) {
			$file = $this->createSimplePieFile( $sourceUrl );
			$feed->set_file( $file );
		} else {
			$feed->set_feed_url( $source );
		}

		/** @psalm-suppress UndefinedConstant */
		$feed->set_autodiscovery_level( $autoDiscover ? SIMPLEPIE_LOCATOR_ALL : SIMPLEPIE_LOCATOR_NONE );
		$feed->set_timeout( $this->timeout ?? 10 );

		if ( strlen( $this->userAgent ) > 0 ) {
			$feed->set_useragent( $this->userAgent );
		}

		$curlOptions = array();

		if ( strlen( $this->sslCertPath ) > 0 ) {
			$curlOptions[ CURLOPT_CAINFO ] = $this->sslCertPath;
		}

		$curlOptions = $this->mergeCurlOptions( $curlOptions, $this->sourceFetchPolicy->getCurlOptions( $sourceUrl ) );

		if ( ! empty( $curlOptions ) ) {
			$feed->set_curl_options( $curlOptions );
		}

		if ( $this->enableCache && ! empty( $this->cacheDir ) ) {
			if ( ! file_exists( $this->cacheDir ) ) {
				mkdir( $this->cacheDir, 0777, true );
			}

			$feed->enable_cache( true );
			$feed->set_cache_location( $this->cacheDir );

			if ( $this->cacheTtl ) {
				$feed->set_cache_duration( $this->cacheTtl );
			}
		} else {
			$feed->enable_cache( false );
		}

		$stripHtmlTags = array(
			'base',
			'blink',
			'body',
			'doctype',
			'embed',
			'font',
			'form',
			'frame',
			'frameset',
			'html',
			'iframe',
			'input',
			'marquee',
			'meta',
			'noscript',
			'object',
			'param',
			'script',
			'style',
		);

		$feed->strip_htmltags( self::removeAllowedImportHtmlTags( $stripHtmlTags ) );

		return $feed;
	}

	/**
	 * Removes importer-allowed HTML tags from the SimplePie strip list.
	 *
	 * Uses the `wpra.importer.allowedHtmlTags` filter as the single source of
	 * truth for tags that should survive feed reading and importer saves.
	 *
	 * @since 5.2.1
	 *
	 * @param string[] $stripHtmlTags The SimplePie tag names to strip.
	 * @return string[] The filtered tag names to strip.
	 */
	protected static function removeAllowedImportHtmlTags( array $stripHtmlTags ): array {
		$allowedHtmlTags = apply_filters( 'wpra.importer.allowedHtmlTags', array() );

		if ( ! is_array( $allowedHtmlTags ) ) {
			return $stripHtmlTags;
		}

		$allowedTagNames = array();
		foreach ( $allowedHtmlTags as $tag => $attrs ) {
			if ( is_string( $tag ) && is_array( $attrs ) ) {
				$allowedTagNames[] = strtolower( $tag );
			}
		}

		if ( empty( $allowedTagNames ) ) {
			return $stripHtmlTags;
		}

		return array_values( array_diff( $stripHtmlTags, $allowedTagNames ) );
	}

	/**
	 * Checks if a source should be fetched before SimplePie initialization.
	 *
	 * @since 5.2.1
	 *
	 * @param string $sourceUrl The source URL.
	 * @return bool True if the source should be prefetched.
	 */
	protected function shouldPrefetchSource( string $sourceUrl ): bool {
		return ! empty( $this->sourceFetchPolicy->getRequestHeaders( $sourceUrl ) );
	}

	/**
	 * Creates a SimplePie file for a source URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $sourceUrl The source URL.
	 * @return SimplePie_File The fetched file.
	 */
	protected function createSimplePieFile( string $sourceUrl ): SimplePie_File {
		$headers = $this->sourceFetchPolicy->getRequestHeaders( $sourceUrl );
		$curlOptions = $this->sourceFetchPolicy->getCurlOptions( $sourceUrl );

		return new SimplePie_File(
			$sourceUrl,
			$this->timeout ?? 10,
			5,
			$headers,
			strlen( $this->userAgent ) > 0 ? $this->userAgent : null,
			false,
			$curlOptions
		);
	}

	/**
	 * Checks whether a URI looks like a direct feed URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $uri The URI to check.
	 * @return bool True if the URI likely points directly to a feed.
	 */
	protected function isLikelyDirectFeedUri( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( $scheme !== 'http' && $scheme !== 'https' ) {
			return false;
		}

		$host = $parts['host'] ?? '';
		if ( ! is_string( $host ) || trim( $host ) === '' ) {
			return false;
		}

		$path = strtolower( rtrim( $parts['path'] ?? '', '/' ) );
		$query = $parts['query'] ?? '';

		if ( preg_match( '/\.(atom|rdf|rss|xml)$/', $path ) ) {
			return true;
		}

		if ( preg_match( '#(^|/)(atom|feed|feeds|rss)$#', $path ) ) {
			return true;
		}

		if ( is_string( $query ) && $query !== '' ) {
			parse_str( $query, $params );
			foreach ( array( 'atom', 'feed', 'rss' ) as $param ) {
				if ( array_key_exists( $param, $params ) ) {
					return true;
				}
			}
		}

		return false;
	}

	protected function getNiceError( string $error ): string {
		$errorlc = strtolower( $error );
		if ( str_starts_with( $errorlc, 'curl error ' ) ) {
			$rest = substr( $error, 11 );
			$codeStr = substr( $rest, 0, strpos( $rest, ':' ) );

			if ( is_numeric( $codeStr ) ) {
				$code = (int) $codeStr;
			} else {
				$code = 0;
			}

			if ( $code === 22 || $code === 6 ) {
				return __( 'The feed could not be fetched. Kindly check if the feed URL is correct.', 'wprss' );
			}
		}

		return $error;
	}

	/**
	 * Gets a URL from a SimplePie source argument.
	 *
	 * @since 5.2.1
	 *
	 * @param string|SimplePie_File $source A string URL or SimplePie_File object.
	 * @return string The source URL, or an empty string when unavailable.
	 */
	protected function getSourceUrl( $source ): string {
		return ( $source instanceof SimplePie_File ) ? $source->url : ( is_string( $source ) ? $source : '' );
	}

	/**
	 * Merges cURL options.
	 *
	 * @since 5.2.1
	 *
	 * @param array<int,mixed> $options Existing cURL options.
	 * @param array<int,mixed> $incoming Incoming cURL options.
	 * @return array<int,mixed>
	 */
	protected function mergeCurlOptions( array $options, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( isset( $options[ $key ] ) && is_array( $options[ $key ] ) && is_array( $value ) ) {
				$options[ $key ] = array_values( array_merge( $options[ $key ], $value ) );
			} else {
				$options[ $key ] = $value;
			}
		}

		return $options;
	}
}
