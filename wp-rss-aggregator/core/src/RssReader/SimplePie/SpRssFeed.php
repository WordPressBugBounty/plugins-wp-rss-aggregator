<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use DateTime;
use SimpleXMLElement;
use RebelCode\Aggregator\Core\RssReader\RssFeed;
use RebelCode\Aggregator\Core\RssReader\RssNamespace;
use RebelCode\Aggregator\Core\RssReader\RssNode;
use RebelCode\Aggregator\Core\RssReader\RssSynUpdate;
use SimplePie;
use Throwable;

/**
 * Adapter for SimplePie RSS feeds.
 *
 * @psalm-immutable
 */
class SpRssFeed extends SpRssNode implements RssFeed {

	protected SimplePie $feed;

	/** Constructor. */
	public function __construct( SimplePie $feed ) {
		$this->feed = $feed;
		parent::__construct( '', 'feed', $this->feed->data, static::findNamespaces( $feed->raw_data ?: '' ) );
	}

	/** @inheritDoc */
	public function getRootNode(): RssNode {
		$type = $this->feed->get_type();
		$rootData = null;

		if ( $type & SIMPLEPIE_TYPE_ATOM_10 ) {
			// Atom 1.0
			$rootData = $this->feed->data['child'][ RssNamespace::ATOM_1_0 ]['feed'][0] ?? null;
		} elseif ( $type & SIMPLEPIE_TYPE_ATOM_03 ) {
			// Atom 0.3
			$rootData = $this->feed->data['child'][ RssNamespace::ATOM_0_3 ]['feed'][0]['child'] ?? null;
		} elseif ( $type & SIMPLEPIE_TYPE_RSS_RDF ) {
			// RDF/RSS 1.0
			$rootData = $this->feed->data['child'][ RssNamespace::RDF ]['RDF'][0]['child'] ?? null;
		} elseif ( $type & SIMPLEPIE_TYPE_RSS_SYNDICATION ) {
			// RSS 0.9, RSS 1.0, RSS 2.0
			$rootData = $this->feed->data['child']['']['rss'][0]['child']['']['channel'][0] ?? null;
		}

		return $rootData
			? new SpRssNode( '', 'feed', $rootData, $this->getNamespaces() )
			: $this;
	}

	/** @inheritDoc */
	public function getChildrenByType( ?string $tag = null, ?string $ns = null ): array {
		$root = $this->getRootNode();
		return $root === $this
			? array()
			: $root->getChildren( $tag, $ns );
	}

	/** @inheritDoc */
	public function getSourceName(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return $this->feed->get_title();
	}

	/** @inheritDoc */
	public function getSourceUrl(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return $this->feed->get_link();
	}

	/** @inheritDoc */
	public function getImageUrl(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return $this->feed->get_image_link();
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getItems( int $start = 0, ?int $count = null ): iterable {
		$end = $count ? $start + $count : 0;

		/** @psalm-suppress ImpureMethodCall */
		$this->feed->enable_order_by_date();
		/** @psalm-suppress ImpureMethodCall */
		$items = $this->feed->get_items( $start, $end ) ?? array();

		foreach ( $items as $item ) {
			yield new SpRssItem( $item, $this );
		}
	}

	public function getNumItems(): int {
		return $this->feed->get_item_quantity();
	}

	/** @inheritDoc */
	public function getNamespaces(): array {
		/** @psalm-suppress ImpureMethodCall */
		return static::findNamespaces( $this->feed->raw_data ?: '' );
	}

	/** @inheritDoc */
	public function getSuggestedUpdateFreq(): ?RssSynUpdate {
		$root = $this->getRootNode();

		$freq = $root->getChild( 'sy', 'updateFrequency' );
		$freq = $freq ? (int) $freq->getValue() : null;

		$period = $root->getChild( 'sy', 'updatePeriod' );
		$period = $period ? RssSynUpdate::periodFromString( $period->getValue() ) : null;

		try {
			$base = $root->getChild( 'sy', 'updateBase' );
			$base = $base ? new DateTime( $base->getValue() ) : null;
		} catch ( Throwable $err ) {
			$base = null;
		}

		return ( $freq && $period )
			? new RssSynUpdate( $period, $freq, $base )
			: null;
	}

	public static function findNamespaces( string $xml ): array {
		try {
			$elem = new SimpleXMLElement( $xml );
			return $elem->getNamespaces( true );
		} catch ( Throwable $err ) {
			return array();
		}
	}
}
