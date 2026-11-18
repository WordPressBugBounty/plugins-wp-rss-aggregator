<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'mergedFeed',
	array( 'settings', 'importer' ),
	function ( Settings $settings, Importer $importer ) {
		$defaultTitle = 'Latest imported feed items on ' . get_bloginfo( 'name' );

		$url = $settings->register( 'mergedFeedUrl' )->setDefault( 'wprss' )->empty( array( '' ) )->get();
		$title = $settings->register( 'mergedFeedTitle' )->setDefault( $defaultTitle )->empty( array( '' ) )->get();
		$numItems = $settings->register( 'mergedFeedNumItems' )->setDefault( 15 )->empty( array( 0 ) )->get();

		$order = $settings->register( 'mergedFeedOrder' )
			->setDefault( 'desc' )
			->empty( array( '' ) )
			->middleware( fn ( $value ) => MergedFeed::normalizeOrder( $value ) )
			->get();

		$feed = new MergedFeed( $importer->wpPosts, $url, $title, $numItems, $order, $importer->sources );

		add_action(
			'init',
			function () use ( $feed, $url ) {
				add_feed( $url, fn () => $feed->print() );

				if ( get_option( 'permalink_structure' ) && ! MergedFeed::rewriteRulesHaveFeed( get_option( 'rewrite_rules' ), $url ) ) {
					flush_rewrite_rules();
				}
			}
		);

		return $feed;
	}
);
