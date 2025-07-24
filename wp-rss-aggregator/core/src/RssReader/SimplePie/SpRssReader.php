<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use SimplePie_File;
use SimplePie;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Html;
use RebelCode\Aggregator\Core\RssReader\RssFeedInfo;
use RebelCode\Aggregator\Core\RssReader;

/** Adapter for a SimplePie RSS feed reader. */
class SpRssReader implements RssReader {

	private ?int $timeout;
	private string $sslCertPath;
	private string $userAgent;
	private bool $enableCache;
	private ?string $cacheDir;
	private ?int $cacheTtl;

	/**
	 * Constructor.
	 *
	 * @param int|null $timeout The timeout in seconds for fetching feeds.
	 * @param string   $cacheDir The directory to use for caching feeds.
	 * @param int|null $cacheTtl The time to live for cached feeds, in seconds, or null to use SimplePie's 3600 default.
	 */
	public function __construct(
		?int $timeout = null,
		string $sslCertPath = '',
		string $userAgent = '',
		bool $enableCache = false,
		string $cacheDir = '',
		?int $cacheTtl = null
	) {
		$this->timeout = $timeout;
		$this->sslCertPath = trim( $sslCertPath );
		$this->userAgent = trim( $userAgent );
		$this->enableCache = $enableCache;
		$this->cacheDir = $cacheDir;
		$this->cacheTtl = $cacheTtl;
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
			$message = $this->getNiceError( $errors[0] );
			return Result::Err( $message );
		}
	}

	public function findFeeds( string $uri ): Result {
		$spFeed = $this->createSimplePie( $uri, true );
		$spFeed->init();
		$feeds = $spFeed->get_all_discovered_feeds();

		if ( ! is_iterable( $feeds ) ) {
			return Result::Ok( array() );
		}

		if ( count( $feeds ) === 0 && count( $spFeed->get_items() ?? array() ) > 0 ) {
			$feeds[] = new SimplePie_File( $uri );
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
	 * @psalm-suppress ImpureMethodCall
	 *
	 * @param string|SimplePie_File $source A string URL or SimplePie_File object.
	 */
	protected function createSimplePie( $source, bool $autoDiscover = false ): SimplePie {
		if ( ! class_exists( SimplePie::class ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}

		$feed = new SimplePie();

		if ( $source instanceof SimplePie_File ) {
			$feed->set_file( $source );
		} else {
			$feed->set_feed_url( $source );
		}

		/** @psalm-suppress UndefinedConstant */
		$feed->set_autodiscovery_level( $autoDiscover ? SIMPLEPIE_LOCATOR_ALL : SIMPLEPIE_LOCATOR_NONE );
		$feed->set_timeout( $this->timeout ?? 10 );

		if ( strlen( $this->userAgent ) > 0 ) {
			$feed->set_useragent( $this->userAgent );
		}

		if ( strlen( $this->sslCertPath ) > 0 ) {
			$feed->set_curl_options(
				array(
					CURLOPT_CAINFO => $this->sslCertPath,
				)
			);
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

		return $feed;
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
}
