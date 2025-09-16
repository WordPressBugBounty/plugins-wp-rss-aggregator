<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

use RebelCode\Aggregator\Core\Utils\Types;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Size;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;
use RebelCode\Aggregator\Core\Tier;
use RebelCode\Aggregator\Core\Logger;
use DateTime;

class SourceSettings implements ArraySerializable {

	public ?DateTime $futureActivate = null;
	public ?DateTime $futurePause = null;

	// -------------------------------------
	// Import settings
	// -------------------------------------
	public int $importLimit = 0;
	public int $ageLimit = 0;
	public string $ageLimitUnit = 'days';
	public string $importOrder = ImportOrder::DESC;
	public bool $uniqueTitles = false;
	public string $reconcileStrategy = ReconcileStrategy::PRESERVE;
	public bool $curatePosts = false;
	public bool $canonicalLink = false;
	public array $automations = array();

	// -------------------------------------
	// Display settings
	// -------------------------------------
	public bool $linkToEnclosure = false;

	// -------------------------------------
	// Post settings
	// -------------------------------------
	public string $postType = 'post';
	public string $postStatus = 'publish';
	public string $postFormat = 'standard';
	public ?int $postSite = null;
	public bool $commentsOpen = true;

	// -------------------------------------
	// Content settings
	// -------------------------------------
	public bool $trimContent = false;
	public int $contentNumWords = 50;

	public array $contentCleaners = array();
	public bool $enablePreContent = false;
	public bool $enablePostContent = false;
	public string $preContentTemplate = '';
	public string $postContentTemplate = '';
	public bool $preContentSingleOnly = false;
	public bool $postContentSingleOnly = false;

	// -------------------------------------
	// Excerpt settings
	// -------------------------------------
	public bool $enableExcerpt = false;
	public string $whichExcerpt = 'import';
	public int $excerptNumWords = 50;
	public string $excerptSuffix = '';
	public bool $genMissingExcerpt = true;
	public int $excerptGenNumWords = 50;
	public string $excerptGenSuffix = '';

	// -------------------------------------
	// Image settings
	// -------------------------------------
	public bool $downloadImages = false;
	public bool $downloadAllImgSizes = false;
	public bool $assignFtImage = true;
	public string $whichFtImage = 'auto';
	public int $fallbackFtImageId = 0;
	public bool $deDupeFtImage = true;
	public bool $mustHaveFtImage = false;
	/** @var Size */
	public Size $minImageSize;

	// -------------------------------------
	// Date settings
	// -------------------------------------
	public string $whichPostDate = 'published_date';
	public bool $allowFutureDates = false;

	// -------------------------------------
	// Author settings
	// -------------------------------------
	public string $whichAuthor = 'feed';
	public string $authorMethod = 'default';
	public bool $mustHaveAuthor = false;
	public int $fallbackAuthorId = 0;

	// -------------------------------------
	// Taxonomy settings
	// -------------------------------------
	public array $taxonomies = array();

	// -------------------------------------
	// Audio player settings
	// -------------------------------------
	public bool $enableAudioPlayer = true;
	public string $audioPlayerPos = 'before';
	public bool $enablePowerPress = false;

	// -------------------------------------
	// Attribution settings
	// -------------------------------------
	public bool $enableAttribution = true;
	public bool $attributionSingleOnly = true;
	public string $attributionTemplate = '';
	public string $attributionPosition = 'before';

	// -------------------------------------
	// Full text settings
	// -------------------------------------
	public bool $enableFullText = false;
	public bool $fullTextBatchMode = false;

	// -------------------------------------
	// Custom mapping settings
	// -------------------------------------
	public array $customMapping = array();

	// -------------------------------------
	// WordAi settings
	// -------------------------------------
	public bool $waiEnableTitle = false;
	public bool $waiEnableContent = false;
	public bool $waiRevisions = false;
	public string $waiUniqueness = 'regular';
	public bool $waiSpintax = false;
	public bool $waiProtectWords = false;
	public bool $waiCustomSynonyms = false;

	// -------------------------------------
	// SpinnerChief settings
	// -------------------------------------
	public bool $scEnableContent = false;
	public bool $scEnableTitle = false;
	public bool $scRevisions = false;

	public function __construct() {
		$this->minImageSize = new Size( 80, 80 );
	}

	/**
	 * @param mixed $value
	 */
	public function set( string $key, $value ): self {
		if ( ! property_exists( self::class, $key ) ) {
			Logger::warning( "Unknown source setting: \"$key\"" );
		} else {
			$this->$key = Types::autoCast( $value, $this->$key );
		}

		return $this;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function patch( array $data ): self {
		$tier = wpra()->get( 'licensing' )->getTier();

		foreach ( $data as $key => $val ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}

			switch ( $key ) {
				case 'futureActivate':
				case 'futurePause':
					if ( ! empty( $val ) ) {
						$this->$key = Time::createAndCatch( (string) $val );
					}
					break;

				case 'minImageSize':
				$this->minImageSize = Size::fromArray( $val );
				break;

				default:
					$val = apply_filters( "wpra.source.settings.patch.$key", $val, $this );
					$this->$key = $val;
					break;
			}
		}
		return $this;
	}

	public function toArray(): array {
		return Arrays::toArrayAll( get_object_vars( $this ) );
	}

	/** @param array<string,mixed> $array */
	public static function fromArray( array $array ): self {
		$s = new self();
		$s->patch( $array );
		return $s;
	}
}
