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

		$feed = new MergedFeed( $importer->wpPosts, $url, $title, $numItems );

		add_action(
			'init',
			function () use ( $feed, $url ) {
				add_feed( $url, fn () => $feed->print() );

				$rules = get_option( 'rewrite_rules' );
				if ( ! is_array( $rules ) || ! isset( $rules[ $url ] ) ) {
					flush_rewrite_rules();
				}
			}
		);

		return $feed;
	}
);
