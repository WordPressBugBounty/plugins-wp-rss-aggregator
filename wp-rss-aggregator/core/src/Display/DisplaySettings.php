<?php

namespace RebelCode\Aggregator\Core\Display;

use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;

class DisplaySettings implements ArraySerializable {

	/** @var list<int> For old v4 shortcodes and blocks */
	public array $excludeSrcs = array();

	public string $layout = 'list';
	public int $numItems = 15;
	public string $htmlClass = '';

	public array $filters = array();

	// Titles
	public bool $enableTitles = true;
	public ?int $titleMaxLength = null;
	public bool $linkTitles = true;

	// Sources
	public bool $enableSources = true;
	public string $sourcePrefix = '';
	public bool $linkSource = true;

	// Dates
	public bool $enableDates = true;
	public string $datePrefix = '';
	public string $dateFormat = 'F j, Y';
	public bool $useRelDateFormat = false;

	// Authors
	public bool $enableAuthors = true;
	public string $authorPrefix;

	// Links
	public bool $linksNoFollow = true;
	public string $linkTarget = '_blank';
	public bool $linkToEmbeds = false;

	// Pagination
	public bool $enablePagination = true;
	public string $paginationStyle = 'numbered';

	// Images
	public bool $enableImages = true;
	public bool $linkImages = true;
	public int $imageWidth = 150;
	public int $imageHeight = 150;
	public bool $fallbackToSrcImage = true;

	// Excerpts
	public bool $enableExcerpts = true;
	public ?int $excerptMaxWords = null;
	public string $excerptEllipsis = '...';
	public bool $enableReadMore = true;
	public string $readMoreText;

	// Bullets
	public bool $enableBullets = true;
	public string $bulletStyle = 'default';

	// Audio player
	public bool $enableAudioPlayer = true;

	// Excerpts & Thumbnails
	public string $etStyle = 'news';

	// Grid
	public bool $gridUseImageAsBg = false;
	public bool $gridFitImages = true;
	public bool $gridEnableEmbeds = true;
	public bool $gridEnableBorders = true;
	public bool $gridItemClickable = false;
	public bool $gridAlignLastToBottom = false;
	public int $gridMaxColumns = 3;
	public bool $gridInfoBlocks = false;
	public bool $gridStackInfoItems = false;
	/** @var list<array{enabled:bool,type:string}> */
	public array $gridComponents = array(
		array(
			'enabled' => true,
			'type' => 'image',
		),
		array(
			'enabled' => true,
			'type' => 'title',
		),
		array(
			'enabled' => true,
			'type' => 'excerpt',
		),
		array(
			'enabled' => false,
			'type' => 'audio',
		),
		array(
			'enabled' => false,
			'type' => 'info',
		),
	);
	/** @var list<array{enabled:bool,type:string}> */
	public array $gridInfoComponents = array(
		array(
			'enabled' => true,
			'type' => 'date',
		),
		array(
			'enabled' => true,
			'type' => 'source',
		),
		array(
			'enabled' => false,
			'type' => 'author',
		),
	);

	/** @param array<string,mixed> $settings */
	public function __construct( array $settings = array() ) {
		$this->authorPrefix = _x( 'By ', 'Default value for the "Author prefix" display setting', 'wp-rss-aggregator' );
		$this->readMoreText = _x( 'Read more', 'Default value for the "Read more text" display setting', 'wp-rss-aggregator' );
		$this->patch( $settings );
	}

	/** @param iterable<string,mixed> $settings */
	public function patch( iterable $settings ): self {
		foreach ( $settings as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}

			switch ( $key ) {
				case 'imageHeight':
					$this->imageHeight = (int) $value;
					break;
				default:
					$value = apply_filters( "wpra.display.settings.patch.$key", $value, $this );
					$this->$key = $value;
					break;
			}
		}

		return $this;
	}

	public function toArray(): array {
		return Arrays::toArrayAll( get_object_vars( $this ) );
	}
}
