<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use Throwable;
use SimplePie_Item;
use RebelCode\Aggregator\Core\RssReader\RssItem;
use RebelCode\Aggregator\Core\RssReader\RssFeed;
use DateTimeZone;
use DateTime;

/**
 * Adapter for SimplePie RSS feed items.
 *
 * @psalm-immutable
 */
class SpRssItem extends SpRssNode implements RssItem {

	protected SimplePie_Item $item;

	/** Constructor. */
	public function __construct( SimplePie_Item $item, RssFeed $feed ) {
		$this->item = $item;
		parent::__construct( '', 'item', $this->item->data, $feed->getNamespaces(), $feed );
	}

	/** @inheritDoc */
	public function getFeed(): RssFeed {
		assert( $this->parent instanceof RssFeed );
		return $this->parent;
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getId(): ?string {
		return $this->item->get_id( false, false );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getPermalink(): ?string {
		return $this->item->get_permalink();
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getTitle(): ?string {
		return wp_specialchars_decode( $this->item->get_title(), ENT_QUOTES );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getExcerpt(): ?string {
		return wp_specialchars_decode( $this->item->get_description(), ENT_QUOTES );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getContent(): ?string {
		return wp_specialchars_decode( $this->item->get_content(), ENT_QUOTES );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getAuthors(): array {
		$result = array();
		foreach ( $this->item->get_authors() ?? array() as $author ) {
			$result[] = new SpRssAuthor( $author );
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getCategories(): array {
		$result = array();
		foreach ( $this->item->get_categories() ?? array() as $category ) {
			$result[] = new SpRssCategory( $category );
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getDateCreated(): ?DateTime {
		return $this->createDate( $this->item->get_gmdate( 'U' ) );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getDateModified(): ?DateTime {
		return $this->createDate( $this->item->get_updated_gmdate( 'U' ) );
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall, InvalidReturnType
	 */
	public function getLinks(): array {
		return $this->item->get_links() ?? array();
	}

	/**
	 * @inheritDoc
	 * @psalm-suppress ImpureMethodCall
	 */
	public function getEnclosures(): array {
		$result = array();
		foreach ( $this->item->get_enclosures() ?? array() as $enclosure ) {
			$result[] = new SpRssEnclosure( $enclosure );
		}

		return $result;
	}

	/**
	 * @param int|string|null $timestamp
	 * @return DateTime|null
	 */
	protected function createDate( $timestamp ): ?DateTime {
		$int = is_string( $timestamp ) ? intval( $timestamp ) : $timestamp;

		if ( empty( $int ) ) {
			return null;
		}

		try {
			return new DateTime( '@' . $int, new DateTimeZone( 'UTC' ) );
		} catch ( Throwable $err ) {
			return null;
		}
	}

	/** Used for printing the value when debugging. */
	public function __debugInfo() {
		$data = $this->item->data;
		unset( $data['feed'] ); // Do not print the parent feed - prevents recursive output.
		return $data;
	}
}
