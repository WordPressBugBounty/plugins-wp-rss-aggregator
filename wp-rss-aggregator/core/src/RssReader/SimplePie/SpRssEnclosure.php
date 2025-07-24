<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use RebelCode\Aggregator\Core\RssReader\RssEnclosure;
use RebelCode\Aggregator\Core\RssReader\RssEnclosureType;
use SimplePie_Enclosure;

/** Adapter for SimplePie RSS feed item enclosures. */
class SpRssEnclosure implements RssEnclosure {

	protected SimplePie_Enclosure $enclosure;
	/** @psalm-var RssEnclosureType::* */
	protected ?string $_typeCache = null;

	/** Constructor. */
	public function __construct( SimplePie_Enclosure $enclosure ) {
		$this->enclosure = $enclosure;
	}

	public function getType(): string {
		return $this->_typeCache === null
			? $this->_typeCache = static::getTypeFromMime( $this->enclosure->get_type() ?? '' )
			: $this->_typeCache;
	}

	/** @inheritDoc */
	public function getUrl(): ?string {
		/** @psalm-suppress ImpureMethodCall */
		return $this->enclosure->get_link();
	}

	public function getDuration(): ?int {
		return $this->enclosure->get_duration();
	}

	public function getLength(): ?int {
		$length = $this->enclosure->get_length();
		if ( $length === null ) {
			return null;
		}
		return (int) $length;
	}

	public function getKeywords(): array {
		return $this->enclosure->get_keywords() ?? array();
	}

	/**
	 * Detects the enclosure type from a MIME type.
	 *
	 * @psalm-mutation-free
	 * @param string $mimeType
	 * @return RssEnclosureType::*
	 */
	public static function getTypeFromMime( string $mimeType ): string {
		$type = strtolower( $mimeType );
		$parts = explode( '/', $type, 2 );

		if ( count( $parts ) > 0 ) {
			switch ( $parts[0] ) {
				case 'image':
					return RssEnclosureType::IMAGE;
				case 'audio':
					return RssEnclosureType::AUDIO;
				case 'video':
					return RssEnclosureType::VIDEO;
			}
		}

		return RssEnclosureType::OTHER;
	}
}
