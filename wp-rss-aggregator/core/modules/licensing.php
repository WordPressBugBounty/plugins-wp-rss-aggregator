<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\WpSdk\Wp\CronJob;
use EDD_SL_Plugin_Updater;

wpra()->addModule(
	'licensing',
	array(),
	function () {
		$storeUrl = 'https://wprssaggregator.com';
		$licensing = new Licensing( $storeUrl, getPlans() );

		$cronJob = new CronJob(
			'wpra.licensing.update',
			array(),
			'daily',
			array(
				fn () => $licensing->update(),
			)
		);
		$cronJob->ensureScheduled();
		$cronJob->registerHandlers();

		return $licensing;
	}
);

function getPlans() {
	return array(
		array(
			'eddIds' => array( 0 ),
			'name' => _x( 'Free', 'Name of the free plan', 'wp-rss-aggregator' ),
			'desc' => _x( 'Free, for trying things out.', 'Description of the free plan', 'wp-rss-aggregator' ),
			'features' => array(
				__( 'Unlimited sources', 'wp-rss-aggregator' ),
				__( 'Unlimited feeds', 'wp-rss-aggregator' ),
				__( 'All import options', 'wp-rss-aggregator' ),
				__( 'Import text, audio, & video', 'wp-rss-aggregator' ),
			),
			'tier' => Tier::Free,
			'price' => 0,
			'mostPopular' => false,
		),
		array(
			'eddIds' => array( 465794, 774060 ),
			'name' => _x( 'Basic', 'Name of the basic plan', 'wp-rss-aggregator' ),
			'desc' => _x(
				'Display RSS feeds anywhere on your site and customize them to match your site’s design.',
				'Description of the basic plan',
				'wp-rss-aggregator',
			),
			'tier' => Tier::Basic,
			'features' => array(
				__( 'All layout designs', 'wp-rss-aggregator' ),
				__( 'Manual curation', 'wp-rss-aggregator' ),
				__( 'Full customization', 'wp-rss-aggregator' ),
				__( 'Automatic filtering', 'wp-rss-aggregator' ),
				__( 'Source management', 'wp-rss-aggregator' ),
			),
			'price' => 79,
			'mostPopular' => false,
		),
		array(
			'eddIds' => array( 730097, 774059 ),
			'name' => _x( 'Plus', 'Name of the plus plan', 'wp-rss-aggregator' ),
			'desc' => _x(
				'Aggregate RSS feeds as blog posts and publish their excerpts to your blog or CPT.',
				'Description of the plus plan',
				'wp-rss-aggregator',
			),
			'tier' => Tier::Plus,
			'features' => array(
				__( 'Import as Posts', 'wp-rss-aggregator' ),
				__( 'Schedule publishing', 'wp-rss-aggregator' ),
				__( 'Add custom content', 'wp-rss-aggregator' ),
				__( 'Import taxonomies', 'wp-rss-aggregator' ),
			),
			'price' => 149,
			'mostPopular' => false,
		),
		array(
			'eddIds' => array( 470409, 774058 ),
			'name' => _x( 'Pro', 'Name of the pro plan', 'wp-rss-aggregator' ),
			'desc' => _x(
				'Curate RSS feeds as Posts or any CPT and give your visitors all the content they’re after.',
				'Description of the pro plan',
				'wp-rss-aggregator',
			),
			'tier' => Tier::Pro,
			'features' => array(
				__( 'Full text import', 'wp-rss-aggregator' ),
				__( 'Include all media', 'wp-rss-aggregator' ),
				__( 'Custom Mapping', 'wp-rss-aggregator' ),
			),
			'price' => 179,
			'mostPopular' => true,
		),
		array(
			'eddIds' => array( 694456, 773957 ),
			'name' => _x( 'Elite', 'Name of the Elite plan', 'wp-rss-aggregator' ),
			'desc' => _x(
				'Import unlimited content from RSS feeds and generate your own original versions.',
				'Description of the all access plan',
				'wp-rss-aggregator',
			),
			'tier' => Tier::Elite,
			'features' => array(
				__( 'AI integrations', 'wp-rss-aggregator' ),
				__( 'Title spinning', 'wp-rss-aggregator' ),
				__( 'Content spinning', 'wp-rss-aggregator' ),
			),
			'price' => 249,
			'mostPopular' => false,
		),
	);
}
