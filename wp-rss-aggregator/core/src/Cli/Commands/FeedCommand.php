<?php

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Importer;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Utils\Arrays;

class FeedCommand extends BaseCommand {

	protected Importer $importer;

	public function __construct( CliIo $io, Importer $importer ) {
		parent::__construct( $io );
		$this->importer = $importer;
	}

	/**
	 * Validates an RSS feed.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL of the RSS feed to validate.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss feed validate "https://www.bbc.co.uk/news/uk/rss.xml"
	 *
	 * @param list<string> $args
	 */
	public function validate( array $args ): void {
		$url = $args[0];
		$result = $this->importer->validate( $url );

		if ( $result->isOk() ) {
			$isValid = $result->get();
			if ( $isValid ) {
				$this->io->success( __( 'Valid', 'wp-rss-aggregator' ) );
			} else {
				$this->io->error( __( 'Invalid', 'wp-rss-aggregator' ) );
			}
		} else {
			$this->io->error( $result->error() );
		}
	}

	/**
	 * Finds RSS feeds from a given website URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL of the website where RSS feeds will be searched.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss feed find "https://www.bbc.co.uk"
	 *
	 * @param list<string> $args
	 */
	public function find( array $args ): void {
		$url = $args[0];
		$result = $this->importer->rssReader->findFeeds( $url );

		if ( $result->isOk() ) {
			$feeds = $result->get();
			$this->io->println( 'Found ' . count( $feeds ) . ' feeds:' );
			foreach ( $feeds as $feed ) {
				$this->io->println( "$feed->title: $feed->url ($feed->numItems items)" );
			}
		} else {
			$this->io->error( $result->error() );
		}
	}


	/**
	 * Previews what items would be imported for an RSS feed.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL of the RSS feed or website. If not a direct RSS feed URl, the RSS feed will be auto-discovered.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss feed preview "https://www.bbc.co.uk"
	 *
	 * @param list<string> $args
	 */
	public function preview( array $args ): void {
		$url = $args[0];

		$src = new Source();
		$src->url = $url;

		$result = $this->importer->fetch( $src );

		if ( $result->isOk() ) {
			$items = Arrays::fromIterable( $result->get() );

			$this->io->println( 'Found ' . count( $items ) . ' items:' );
			foreach ( $items as $item ) {
				$this->io->println( "* $item->title" );
			}
		} else {
			$this->io->error( $result->error() );
		}
	}
}
