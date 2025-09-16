<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Importer;

use RebelCode\Aggregator\Plus\Source\AuthorMethod;
use RebelCode\Aggregator\Core\Utils\Strings;
use RebelCode\Aggregator\Core\Utils\Html;
use RebelCode\Aggregator\Core\Tier;
use RebelCode\Aggregator\Core\Source\FtImageToUse;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\RssReader\RssNamespace;
use RebelCode\Aggregator\Core\RssReader\RssItem;
use RebelCode\Aggregator\Core\RssReader\RssEnclosureType;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Licensing;
use RebelCode\Aggregator\Core\IrPost\IrImage;
use RebelCode\Aggregator\Core\IrPost\IrAuthor;
use RebelCode\Aggregator\Core\IrPost;
use RebelCode\Aggregator\Core\Importer\RssImageFinder;
use RebelCode\Aggregator\Core\ImportedPost;
use Generator;
use DateTime;

class IrPostBuilder {

	public RssImageFinder $imgFinder;
	public Licensing $licensing;

	public function __construct( RssImageFinder $finder, Licensing $licensing ) {
		$this->imgFinder = $finder;
		$this->licensing = $licensing;
	}

	public function build( RssItem $item, Source $src ): IrPost {
		$srcs = $src->id !== null && $src->id > 0 ? array( $src->id ) : array();

		$post = new IrPost( '', null, $srcs );
		$post = apply_filters( 'wpra.importer.post.initial', $post, $item, $src );

		$post->url = $item->getPermalink() ?? '';
		$post->url = apply_filters( 'wpra.importer.post.url', $post->url, $item, $src, $post );

		$post->guid = $item->getId() ?? $this->buildGuid( $post->url );
		$post->guid = apply_filters( 'wpra.importer.post.guid', $post->guid, $item, $src, $post );

		$post->type = apply_filters( 'wpra.importer.post.type', 'wprss_feed_item', $item, $src, $post );
		$post->status = apply_filters( 'wpra.importer.post.status', 'publish', $item, $src, $post );
		$post->format = apply_filters( 'wpra.importer.post.format', 'standard', $item, $src, $post );
		$post->commentsOpen = apply_filters( 'wpra.importer.post.commentsOpen', false, $item, $src, $post );

		$post->title = $this->buildTitle( $item );
		$post->title = apply_filters( 'wpra.importer.post.title', $post->title, $item, $src, $post );

		$post->content = $this->buildContent( $item );
		$post->content = apply_filters( 'wpra.importer.post.content', $post->content, $item, $src, $post );

		$post->excerpt = $this->buildExcerpt( $item );
		$post->excerpt = apply_filters( 'wpra.importer.post.excerpt', $post->excerpt, $item, $src, $post );

		$post->author = $this->buildAuthor( $item, $src );
		$post->author = apply_filters( 'wpra.importer.post.author', $post->author, $item, $src, $post );

		[$pubDate, $modDate] = $this->buildDates( $post, $item, $src );
		$post->datePublished = apply_filters( 'wpra.importer.post.datePublished', $pubDate, $item, $src, $post );
		$post->dateModified = apply_filters( 'wpra.importer.post.dateModified', $modDate, $item, $src, $post );

		$post->images = $this->buildImages( $post->content, $item, $src );
		$post->images = apply_filters( 'wpra.importer.post.images', $post->images, $item, $src, $post );

		$post->ftImage = $this->buildFtImage( $post->images, $item, $src );
		$post->ftImage = apply_filters( 'wpra.importer.post.ftImage', $post->ftImage, $item, $src, $post );

		$post->terms = apply_filters( 'wpra.importer.post.terms', array(), $item, $src, $post );

		$post->meta = $this->buildMetaData( $item, $src, $post );
		$post->meta = apply_filters( 'wpra.importer.post.meta', $post->meta, $post, $item, $src );

		do_action( 'wpra.importer.post.templates', $post, $item, $src );

		return apply_filters( 'wpra.importer.post', $post, $item, $src );
	}

	public function buildGuid( string $url ): string {
		$url = trim( $url );
		$url = htmlspecialchars_decode( $url );
		$url = sanitize_url( $url );

		return self::trackingUrlFix(
			$url,
			array(
				// Google News fix
				'!^(https?://)?' . preg_quote( 'news.google.com', '!' ) . '.*!' => 'url',
				// Google Alerts fix
				'!^(https?://)?(www\.)?' . preg_quote( 'google.com/url', '!' ) . '.*!' => 'url',
				// Bing News fix
				'!^(https?://)?(www\.)?' . preg_quote( 'bing.com/news', '!' ) . '.*!' => 'url',
			)
		);
	}

	public function buildTitle( RssItem $item ): string {
		$title = Strings::normalize( $item->getTitle() ?? '' );
		$title = Html::decodeEntities( $title );
		$title = Html::stripTags( $title );
		return $title;
	}

	public function buildContent( RssItem $item ): string {
		$content = trim( $item->getContent() ?? $item->getExcerpt() ?? '' );
		$content = Html::decodeEntities( $content );
		return $content;
	}

	public function buildExcerpt( RssItem $item ): string {
		$excerpt = $item->getExcerpt() ?? '';
		$excerpt = trim( $excerpt );
		$excerpt = Html::decodeEntities( $excerpt );
		$excerpt = Html::stripTags( $excerpt );
		return $excerpt;
	}

	public function buildAuthor( RssItem $item, Source $src ): ?IrAuthor {
		$ss = $src->settings;
		$fallback = IrAuthor::fromWpUserId( $ss->fallbackAuthorId );

		switch ( $ss->whichAuthor ) {
			case 'user':
				return $fallback;

			case 'feed':
				$author = $this->findAuthorInItem( $item );

				if ( $author !== null ) {
					Logger::debug( 'Found author in feed.' );
					$wpUser = $author->findMatchingWpUser();
					if ( $wpUser !== null ) {
						Logger::debug( 'Found matching WP user for feed author.' );
						return IrAuthor::fromWpUser( $wpUser );
					}
					Logger::debug( 'No matching WP user for feed author. Proceeding with authorMethod.' );
				} else {
					Logger::debug( 'No author found in feed.' );
					if ( $ss->mustHaveAuthor ) {
						Logger::debug( 'mustHaveAuthor is true and no feed author. Returning null.' );
						return null;
					} else {
						return $fallback;
					}
				}

				switch ( $ss->authorMethod ) {
					case 'default':
						Logger::debug( 'Using default author method' );
						return IrAuthor::getDefault();

					case 'create':
						Logger::debug( 'Using create author method' );
						return $author;

					default:
					case 'fallback':
						Logger::debug( 'Using fallback author method' );
						return $fallback;
				}
		}

		Logger::warning( "Invalid author setting \"{$ss->whichAuthor}\"" );
		return null;
	}

	/** @return array{0:DateTime,1:DateTime} */
	public function buildDates( IrPost $post, RssItem $item, Source $src ): array {
		$now = new DateTime();
		$pubDate = $item->getDateCreated();
		$modDate = $item->getDateModified();

		if ( $pubDate === null && $modDate === null ) {
			$pubDate = $modDate = $now;
		} else {
			$pubDate ??= $modDate;
			$modDate ??= $pubDate;
		}

		[$pubDate, $modDate] = apply_filters( 'wpra.importer.post.dates', array( $pubDate, $modDate ), $post, $item, $src );

		if ( $pubDate > $now ) {
			if ( $src->settings->allowFutureDates || $post->status === 'future' ) {
				$post->status = 'future';
			} else {
				$pubDate = $now;
			}
		}

		if ( $modDate < $pubDate ) {
			$modDate = $pubDate;
		}

		return array( $pubDate, $modDate );
	}

	/** @return list<IrImage> */
	public function buildImages( string $content, RssItem $item, Source $src ): array {
		if ( $this->licensing->getTier() === Tier::Free ) {
			return array();
		}
		if ( ! $src->settings->downloadImages && ! $src->settings->assignFtImage ) {
			return array();
		}
		return iterator_to_array( $this->imgFinder->findAllImages( $content, $item, $src ) );
	}

	/** @param list<IrImage> $images */
	public function buildFtImage( array $images, RssItem $item, Source $src ): ?IrImage {
		if ( ! $src->settings->assignFtImage || FtImageToUse::NO_IMAGE === $src->settings->whichFtImage ) {
			return null;
		}

		$ftImage = null;
		$fallbackId = $src->settings->fallbackFtImageId;

		switch ( $src->settings->whichFtImage ) {
			case FtImageToUse::AUTO:
				$ftImage = $this->imgFinder->findBestImage( $images );
				break;

			case FtImageToUse::CONTENT_FIRST:
				foreach ( $images as $image ) {
					if ( $image->source === IrImage::FROM_CONTENT ) {
						$ftImage = $image;
						break;
					}
				}
				break;

			case FtImageToUse::CONTENT_LAST:
				for ( $i = count( $images ) - 1; $i >= 0; $i-- ) {
					$image = $images[ $i ];
					if ( $image->source === IrImage::FROM_CONTENT ) {
						$ftImage = $image;
					}
				}
				break;

			case FtImageToUse::CONTENT_BEST:
				$ftImage = $this->imgFinder->findBestImage( $images, IrImage::FROM_CONTENT );
				break;

			case FtImageToUse::FEED_IMAGE:
				$ftImage = $this->imgFinder->findBestImage( $images, IrImage::FROM_FEED );
				break;

			case FtImageToUse::ITUNES:
				$ftImage = $this->imgFinder->findBestImage( $images, IrImage::FROM_ITUNES );
				break;

			case FtImageToUse::MEDIA:
				$ftImage = $this->imgFinder->findBestImage( $images, IrImage::FROM_MEDIA );
				break;

			case FtImageToUse::ENCLOSURE:
				$ftImage = $this->imgFinder->findBestImage( $images, IrImage::FROM_ENCLOSURE );
				break;

			case FtImageToUse::SOCIAL:
				foreach ( $this->imgFinder->findSocial( $item ) as $img ) {
					$ftImage = $img;
					break;
				}
				break;

			case FtImageToUse::USER:
				break;
		}

		if ( $ftImage === null && $fallbackId > 0 ) {
			return IrImage::fromWpImageId( $fallbackId, IrImage::FROM_USER );
		}

		return $ftImage;
	}

	/** @return array<string,mixed> */
	public function buildMetaData( RssItem $item, Source $src, IrPost $post ): array {
		$meta = array(
			ImportedPost::GUID => $post->guid,
			ImportedPost::URL => $post->url,
			ImportedPost::SOURCE => array( $src->id ),
			ImportedPost::SOURCE_NAME => $item->getFeed()->getSourceName() ?? $src->name,
			ImportedPost::SOURCE_URL => $item->getFeed()->getSourceUrl() ?? $src->url,
			ImportedPost::IMPORT_DATE => ( new DateTime() )->format( DateTime::ATOM ),
			ImportedPost::AUDIO_URL => array(),
			ImportedPost::ENCLOSURE_URL => array(),
		);

		$author = $this->findAuthorInItem( $item );
		if ( $author !== null ) {
			$meta[ ImportedPost::AUTHOR_NAME ] = $author->name;
			$meta[ ImportedPost::AUTHOR_EMAIL ] = $author->email;
			$meta[ ImportedPost::AUTHOR_URL ] = $author->link;
		}

		if ( $post->ftImage ) {
			$post->meta[ ImportedPost::FT_IMAGE_URL ] = $post->ftImage->url;
		}

		foreach ( $item->getEnclosures() as $enclosure ) {
			$url = $enclosure->getUrl();
			$type = $enclosure->getType();

			if ( stripos( $type, RssEnclosureType::AUDIO ) === 0 ) {
				$meta[ ImportedPost::AUDIO_URL ][] = $url;
			}

			$meta[ ImportedPost::ENCLOSURE_URL ][] = $url;
		}

		return $meta;
	}

	public function buildEnclosures( IrPost $post, RssItem $item ): void {
		$post->meta[ ImportedPost::AUDIO_URL ] = array();

		foreach ( $item->getEnclosures() as $enclosure ) {
			$url = $enclosure->getUrl();
			$type = $enclosure->getType();

			if ( stripos( $type, RssEnclosureType::AUDIO ) === 0 ) {
				$post->meta[ ImportedPost::AUDIO_URL ][] = $url;
			}

			$post->meta[ ImportedPost::ENCLOSURE_URL ][] = $url;
		}
	}

	/** Finds the author for an RSS item. */
	public function findAuthorInItem( RssItem $item ): ?IrAuthor {
		foreach ( $item->getAuthors() as $author ) {
			$author = IrAuthor::fromRssAuthor( $author );

			if ( $author->name || $author->email ) {
				return $author;
			}
		}

		foreach ( self::getCredits( $item ) as $credit ) {
			$author = IrAuthor::fromRssNode( $credit );

			if ( $author->name || $author->email ) {
				return $author;
			}
		}

		return null;
	}

	/**
	 * Gets <media:credit> tags from an RSS item.
	 *
	 * @return Generator<RssNode>
	 */
	private function getCredits( RssItem $input ): Generator {
		// <media:credit>
		yield from $input->getChildrenByType( 'credit', RssNamespace::MEDIA_RSS );

		// <media:credit> tags nested under <media:content>
		$content = $input->getChildrenByType( 'content', RssNamespace::MEDIA_RSS );
		foreach ( $content as $node ) {
			yield from $node->getChildrenByType( 'credit', RssNamespace::MEDIA_RSS );
		}
	}

	/**
	 * Checks if a URL is a tracking URL based on host, and if so, it returns
	 * the canonical URL of the resources, determined by the named query argument.
	 *
	 * This is used to normalize item URLs before using them as GUIDs, for cases
	 * where the item URL contains dynamic tracking data, such as with Google
	 * News, and would result in a different GUID for the same item. Example:
	 *
	 * http://news.google.com/news/url?sa=t&fd=R&ct2=us&ei=V3e9U6izMMnm1QaB1YHoDA&url=http://abcd...
	 * http://news.google.com/news/url?sa=t&fd=R&ct2=us&ei=One9U-HQLsTp1Aal-oDQBQ&url=http://abcd...
	 *
	 * @param array<string,string> $patterns An associative array of URL patterns mapping to query arg names.
	 *        The URL is checked against each pattern. If it matches, the value
	 *        of the corresponding query arg is returned.
	 */
	protected static function trackingUrlFix( string $url, array $patterns ): string {
		$parsedUrl = parse_url( urldecode( html_entity_decode( $url ) ) );

		if ( empty( $parsedUrl ) || ! isset( $parsedUrl['query'] ) ) {
			return $url;
		}

		$matchArg = null;
		foreach ( $patterns as $pattern => $argName ) {
			if ( preg_match( $pattern, $url ) ) {
				$matchArg = $argName;
				break;
			}
		}

		if ( $matchArg === null ) {
			return $url;
		}

		$query = array();
		parse_str( $parsedUrl['query'], $query );

		if ( ! is_array( $query ) || ! isset( $query[ $matchArg ] ) ) {
			return $url;
		}

		return urldecode( $query[ $matchArg ] );
	}
}
