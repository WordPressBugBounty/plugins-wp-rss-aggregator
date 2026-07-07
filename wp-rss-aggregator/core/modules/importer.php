<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Store\WpPostsStore;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\RejectListStore;
use RebelCode\Aggregator\Core\Store\ProgressStore;
use RebelCode\Aggregator\Core\RssReader\SimplePie\SpRssReader;
use RebelCode\Aggregator\Core\Importer\RssImageFinder;
use RebelCode\Aggregator\Core\Importer\IrPostBuilder;
use RebelCode\Aggregator\Core\Importer\SourceFetchPolicy;

wpra()->addModule(
	'importer.sourceFetchPolicy',
	array(),
	function () {
		$sourceFetchRules = array(
			array(
				'hosts' => array( 'reddit.com', '*.reddit.com' ),
				'requestHeaders' => array(
					'Accept' => '*/*',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
				'previewCacheTtl' => MINUTE_IN_SECONDS,
				'skipFeedDiscovery' => true,
			),
		);

		/**
		 * Filters source-specific fetch rules for rate-limited or strict sources.
		 *
		 * Use this filter to isolate host-specific transport and preview-cache
		 * behaviour from the importer and RSS reader.
		 *
		 * @since 5.2.1
		 *
		 * Each rule supports:
		 * - hosts: A host or list of hosts. Wildcards like *.example.com are supported.
		 * - requestHeaders: Extra request headers to use for matching sources.
		 * - curlOptions: Extra cURL options to apply for matching sources.
		 * - previewCacheTtl: Preview cache TTL in seconds for matching sources.
		 * - skipFeedDiscovery: Whether direct-looking feed URLs should bypass discovery.
		 */
		return new SourceFetchPolicy( apply_filters( 'wpra.importer.sourceFetchRules', $sourceFetchRules ) );
	}
);

wpra()->addModule(
	'importer',
	array( 'db', 'settings', 'licensing', 'importer.sourceFetchPolicy' ),
	function ( Database $db, Settings $settings, Licensing $licensing, SourceFetchPolicy $sourceFetchPolicy ) {
		$sslCertPath = $settings->register( 'sslCertPath' )->setDefault( implode( '/', array( WPINC, 'certificates', 'ca-bundle.crt' ) ) )->get();
		if ( ! empty( $sslCertPath ) && ! path_is_absolute( $sslCertPath ) ) {
			$sslCertPath = ABSPATH . $sslCertPath;
		}

		$enablefeedCache = $settings->register( 'enableFeedCache' )->setDefault( false )->get();
		$feedUserAgent = $settings->register( 'feedUserAgent' )
			->setDefault( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.5845.97 Safari/537.36' )
			->empty( array( '' ) )
			->get();

		$rssReader = new SpRssReader(
			apply_filters( 'wpra.importer.rssReader.timeout', 30 ),
			$sslCertPath,
			$feedUserAgent,
			$enablefeedCache,
			apply_filters( 'wpra.importer.rssReader.cache.dir', sys_get_temp_dir() . '/wprss/feed-cache' ),
			apply_filters( 'wpra.importer.rssReader.cache.ttl', 10 * MINUTE_IN_SECONDS ),
			$sourceFetchPolicy
		);

		$srcsStore = new SourcesStore( $db, $db->tableName( 'sources' ) );
		$srcsStore->createTable();

		$rejListStore = new RejectListStore( $db, $db->tableName( 'reject_list' ) );
		$rejListStore->createTable();

		$wpPosts = new WpPostsStore( $db, $db->wpdb->posts, $db->wpdb->postmeta, $rejListStore );

		$progressStore = new ProgressStore( $db, $db->tableName( 'progress' ) );
		$progressStore->createTable();

		$irPostBuilder = new IrPostBuilder(
			new RssImageFinder(
				apply_filters( 'wpra.importer.imageFinder.cache.ttl', 30 * MINUTE_IN_SECONDS ),
				apply_filters( 'wpra.importer.imageFinder.userAgent', $feedUserAgent ),
				apply_filters( 'wpra.importer.imageFinder.request.timeout', 15 ),
				apply_filters( 'wpra.importer.imageFinder.request.maxResponseSize', 5 * MB_IN_BYTES )
			),
			$licensing,
		);

		return new Importer(
			$rssReader,
			$srcsStore,
			$wpPosts,
			$rejListStore,
			$irPostBuilder,
			$progressStore,
			$sourceFetchPolicy
		);
	}
);
