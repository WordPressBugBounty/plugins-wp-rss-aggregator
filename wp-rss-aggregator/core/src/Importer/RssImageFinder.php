<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Importer;

use RebelCode\Aggregator\Core\Utils\Uri;
use RebelCode\Aggregator\Core\Utils\Size;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\RssReader\RssNode;
use RebelCode\Aggregator\Core\RssReader\RssItem;
use RebelCode\Aggregator\Core\RssReader\RssEnclosureType;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\IrPost\IrImage;
use NoRewindIterator;
use Masterminds\HTML5;
use Generator;
use DOMDocument;
use AppendIterator;

class RssImageFinder {

	protected int $cacheTtl;

	/**
	 * @param int $cacheTtl The TTL for the image size cache, in seconds.
	 */
	public function __construct( int $cacheTtl ) {
		$this->cacheTtl = $cacheTtl;
	}

	/**
	 * Finds the largest image with the best aspect ratio.
	 *
	 * @param iterable<IrImage> $images The images to search.
	 * @param string|null       $source Optionally restrict the search to images from
	 *              this source. See the constants in the IrImage class.
	 * @return IrImage|null The best image, or null if no images were given.
	 */
	public function findBestImage( iterable $images, ?string $source = null ): ?IrImage {
		$bestImg = null;
		$bestSize = new Size( 0, 0 );

		foreach ( $images as $image ) {
			if ( $source !== null && $image->source !== $source ) {
				continue;
			}

			if ( $image->size ) {
				$aRatio = $image->size->getAspectRatio();
				$area = $image->size->getArea();

				if ( $bestImg === null || ( $aRatio > 1.0 && $aRatio < 2.0 && $area > $bestSize->getArea() ) ) {
					$bestImg = $image;
					$bestSize = $image->size;
				}
			}
		}

		return $bestImg;
	}

	/**
	 * Finds all images in an RSS item.
	 *
	 * @return Generator<int,IrImage> A list of images.
	 */
	public function findAllImages( string $content, RssItem $item, Source $source ): Generator {
		$images = new AppendIterator();
		$images->append( new NoRewindIterator( $this->findInHtml( $content, $source->settings->downloadAllImgSizes ) ) );
		$images->append( new NoRewindIterator( $this->findRss2( $item ) ) );
		$images->append( new NoRewindIterator( $this->findMedia( $item ) ) );
		$images->append( new NoRewindIterator( $this->findEnclosures( $item ) ) );
		$images->append( new NoRewindIterator( $this->findItunes( $item ) ) );
		$images->append( new NoRewindIterator( $this->findChannel( $item ) ) );

		return $this->processImageUrls( $images, $item, $source );
	}

	/**
	 * Finds images in HTML content.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findInHtml( string $content, bool $doSrcSet = false ): Generator {
		if ( empty( $content ) ) {
			return;
		}

		$parser = new HTML5( array( 'disable_html_ns' => true ) );
		$dom = $parser->loadHTML( $content );
		$imgs = $dom->getElementsByTagName( 'img' );

		for ( $i = 0; $i < $imgs->length; $i++ ) {
			$img = $imgs->item( $i );
			if ( $img === null ) {
				continue;
			}

			$srcNode = $img->attributes->getNamedItem( 'src' );
			$srcSetNode = $img->attributes->getNamedItem( 'srcset' );

			if ( $srcNode !== null ) {
				$src = trim( $srcNode->nodeValue ?? '' );
				yield new IrImage( $src, IrImage::FROM_CONTENT );
			}

			if ( $doSrcSet && $srcSetNode !== null ) {
				$srcSet = trim( $srcSetNode->nodeValue ?? '' );
				$srcList = explode( ',', $srcSet );

				foreach ( $srcList as $entry ) {
					$entry = trim( $entry );
					$pieces = preg_split( '!(\s+)!m', $entry );

					if ( $pieces !== false && count( $pieces ) > 0 ) {
						yield new IrImage( $pieces[0], IrImage::FROM_CONTENT );
					}
				}
			}
		}
	}

	/**
	 * Finds the feed's channel image.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findChannel( RssItem $item ): Generator {
		$feedImgUrl = $item->getFeed()->getImageUrl();
		if ( $feedImgUrl ) {
			yield new IrImage( $feedImgUrl, IrImage::FROM_FEED );
		}
	}

	/**
	 * Finds RSS 2.0 <image>.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findRss2( RssItem $item ): Generator {
		foreach ( $item->getChildrenByType( '', 'image' ) as $image ) {
			$url = $image->getValue();
			if ( $url ) {
				yield new IrImage( $url, IrImage::FROM_RSS2 );
			}
		}
	}

	/**
	 * Finds <media:*> images.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findMedia( RssItem $item ): Generator {
		// look for any <media:* type="image/..." url="..."> tags
		$mediaTags = $item->getChildrenByNs( 'media' );
		foreach ( $mediaTags as $mediaTag ) {
			yield from $this->findMediaRecursive( $mediaTag );
		}

		// look specifically for <media:thumbnail> tags
		$groupTags = $item->getChildrenByType( 'media', 'group' );
		$contentTags = $item->getChildrenByType( 'media', 'content' );

		$toCheck = array_merge( array( $item ), $groupTags, $contentTags );

		foreach ( $toCheck as $node ) {
			foreach ( $node->getChildrenByType( 'media', 'thumbnail' ) as $thumbnail ) {
				$url = $thumbnail->getAttr( '', 'url' );
				if ( $url ) {
					yield new IrImage( $url, IrImage::FROM_MEDIA );
				}
			}
		}
	}

	private function findMediaRecursive( RssNode $node ): Generator {
		$type = trim( $node->getAttr( '', 'type' ) ?? '' );
		$url = trim( $node->getAttr( '', 'url' ) ?? '' );

		if ( stripos( $type, 'image/' ) === 0 && ! empty( $url ) ) {
			yield new IrImage( $url, IrImage::FROM_MEDIA );
		}

		foreach ( $node->getChildrenByNs( 'media' ) as $child ) {
			yield from $this->findMediaRecursive( $child );
		}
	}

	/**
	 * Finds <enclosure> images.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findEnclosures( RssItem $item ): Generator {
		foreach ( $item->getEnclosures() as $enclosure ) {
			$url = $enclosure->getUrl();
			$type = $enclosure->getType();

			if ( $url && ( $type === RssEnclosureType::IMAGE || $type === RssEnclosureType::OTHER ) ) {
				yield new IrImage( $url, IrImage::FROM_ENCLOSURE );
			}
		}
	}

	/**
	 * Finds <itunes:image> images.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findItunes( RssItem $item ): Generator {
		// <itunes:image> tags
		foreach ( $item->getChildrenByType( 'itunes', 'image' ) as $image ) {
			$url = $image->getAttr( '', 'href' );
			if ( $url ) {
				yield new IrImage( $url, IrImage::FROM_ITUNES );
			}
		}
	}

	/**
	 * Finds social images by getting the article HTML and scraping the <meta> tags.
	 *
	 * @return Generator<int,IrImage>
	 */
	public function findSocial( RssItem $item ): Generator {
		$url = $item->getPermalink();
		if ( empty( $url ) ) {
			return;
		}

		$response = wp_remote_get( $url );
		$html = wp_remote_retrieve_body( $response );

		$parser = new HTML5( array( 'disable_html_ns' => true ) );
		$dom = $parser->loadHTML( $html );

		if ( ! ( $dom instanceof DOMDocument ) ) {
			return;
		}

		$metaList = $dom->getElementsByTagName( 'meta' );

		for ( $i = 0; $i < $metaList->length; $i++ ) {
			$metaTag = $metaList->item( $i );
			if ( $metaTag === null ) {
				continue;
			}

			$propAttr = $metaTag->attributes->getNamedItem( 'property' );
			$propValue = $propAttr ? $propAttr->nodeValue : '';

			$nameAttr = $metaTag->attributes->getNamedItem( 'name' );
			$nameValue = $nameAttr ? $nameAttr->nodeValue : '';

			if ( $propValue === 'og:image' || $nameValue === 'twitter:image' ) {
				$contentAttr = $metaTag->attributes->getNamedItem( 'content' );
				$contentVal = $contentAttr ? $contentAttr->nodeValue : '';

				if ( ! empty( $contentVal ) ) {
					yield new IrImage( $contentVal, IrImage::FROM_SOCIAL );
				}
			}
		}
	}

	/**
	 * Processes image URLs.
	 *
	 * @param iterable<IrImage> $images The list of images.
	 * @param RssItem           $item The RSS item.
	 * @param Source            $source The source.
	 * @return Generator<IrImage> The processed images.
	 */
	public function processImageUrls( iterable $images, RssItem $item, Source $source ): Generator {
		$srcUrl = $item->getFeed()->getSourceUrl();

		if ( $srcUrl ) {
			$scheme = parse_url( $srcUrl, PHP_URL_SCHEME ) ?: null;
			$host = parse_url( $srcUrl, PHP_URL_HOST ) ?: null;

			if ( $host === null || $scheme === null ) {
				Logger::warning( __( 'Failed to parse the feed\'s source URL.', 'wp-rss-aggregator' ) );
				$baseUrl = '';
			} else {
				$baseUrl = "$scheme://$host";
			}
		} else {
			Logger::warning( __( 'Feed does not have a source URL.', 'wp-rss-aggregator' ) );
			$baseUrl = '';
		}

		foreach ( $images as $image ) {
			if ( ! ( $image instanceof IrImage ) ) {
				continue;
			}

			if ( ! Uri::isAbsolute( $image->url ) ) {
				$image->url = $baseUrl . $image->url;
			}

			$image->url = Uri::modifyQuery(
				$image->url,
				function ( array $query ) {
					unset( $query['w'] );
					unset( $query['h'] );
					unset( $query['crop'] );
					return $query;
				}
			);

			$size = $this->getImageSize( $image->url );

			if ( $size->isAtLeast( $source->settings->minImageSize ) ) {
				$image->size = $size;
				yield $image;
				continue;
			}
		}
	}

	public function getImageSize( string $url ): Size {
		$cacheDir = sys_get_temp_dir() . '/wprss/image-cache';
		if ( ! file_exists( $cacheDir ) ) {
			mkdir( $cacheDir, 0777, true );
		}

		$hash = md5( $url );
		$cacheFile = "$cacheDir/$hash.json";

		$result = null;

		if ( file_exists( $cacheFile ) ) {
			$cache = json_decode( file_get_contents( $cacheFile ), true );

			if ( time() < $cache['expiry'] ) {
				$result = new Size( $cache['size']['width'], $cache['size']['height'] );
			} else {
				@unlink( $cacheFile );
			}
		}

		if ( $result === null ) {
			$imgSize = @getimagesize( $url );

			if ( $imgSize !== false ) {
				$result = new Size( $imgSize[0], $imgSize[1] );
			} else {
				$result = new Size();
			}

			$cacheJson = json_encode(
				array(
					'expiry' => time() + $this->cacheTtl,
					'size' => $result->toArray(),
				)
			);

			file_put_contents( $cacheFile, $cacheJson );
		}

		return $result;
	}
}
