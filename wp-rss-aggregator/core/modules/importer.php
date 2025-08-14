<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Store\WpPostsStore;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\RejectListStore;
use RebelCode\Aggregator\Core\Store\ProgressStore;
use RebelCode\Aggregator\Core\RssReader\SimplePie\SpRssReader;
use RebelCode\Aggregator\Core\Importer\RssImageFinder;
use RebelCode\Aggregator\Core\Importer\IrPostBuilder;

wpra()->addModule(
	'importer',
	array( 'db', 'settings' ),
	function ( Database $db, Settings $settings ) {
		$sslCertPath = $settings->register( 'sslCertPath' )->setDefault( implode( '/', array( WPINC, 'certificates', 'ca-bundle.crt' ) ) )->get();
		if ( ! empty( $sslCertPath ) && ! path_is_absolute( $sslCertPath ) ) {
			$sslCertPath = ABSPATH . $sslCertPath;
		}

		$enablefeedCache = $settings->register( 'enableFeedCache' )->setDefault( false )->get();
		$feedUserAgent = $settings->register( 'feedUserAgent' )->setDefault( 'Mozilla/5.0 (Linux 10.0; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36' )->get();

		$rssReader = new SpRssReader(
			apply_filters( 'wpra.importer.rssReader.timeout', 30 ),
			$sslCertPath,
			$feedUserAgent,
			$enablefeedCache,
			apply_filters( 'wpra.importer.rssReader.cache.dir', sys_get_temp_dir() . '/wprss/feed-cache' ),
			apply_filters( 'wpra.importer.rssReader.cache.ttl', 10 * MINUTE_IN_SECONDS ),
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
				apply_filters( 'wpra.importer.imageFinder.cache.ttl', 30 * MINUTE_IN_SECONDS )
			)
		);

		return new Importer(
			$rssReader,
			$srcsStore,
			$wpPosts,
			$rejListStore,
			$irPostBuilder,
			$progressStore,
		);
	}
);
