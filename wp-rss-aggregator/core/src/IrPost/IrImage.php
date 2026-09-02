<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\IrPost;

use WP_Post;
use WP_Error;
use RebelCode\Aggregator\Core\Utils\Size;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;
use RebelCode\Aggregator\Core\ImportedMedia;

class IrImage implements ArraySerializable {

	/** For images found in a post's content. */
	public const FROM_CONTENT = 'content';
	/** For images found in the RSS feed's channel. */
	public const FROM_FEED = 'feed';
	/** For images found in RSS 2.0 `<image>` tags. */
	public const FROM_RSS2 = 'rss2';
	/** For images found in <itunes:image> tags. */
	public const FROM_ITUNES = 'itunes';
	/** For images found in <media:thumbnail> tags. */
	public const FROM_MEDIA = 'media';
	/** For images found in <enclosure> tags. */
	public const FROM_ENCLOSURE = 'enclosure';
	/** For images found by scraping the article for social media meta tags. */
	public const FROM_SOCIAL = 'social';
	/** For images added by the user. */
	public const FROM_USER = 'user';
	/** For images retrieved from the local WordPress media library.  */
	public const FROM_WP = 'wordpress';

	/** Only set when the image is created from a WordPress attachment ID. */
	public ?int $id = null;
	public string $url = '';
	public string $source;
	public ?Size $size = null;
	/** @var IrImage[] */
	public array $sizes = array();
	public string $requestUserAgent = '';
	/** @since 5.5.0 */
	public string $altText = '';

	/**
	 * Constructor.
	 *
	 * @param string    $url The URL of the image.
	 * @param string    $source The source of the image.
	 * @param Size|null $size The size of the image.
	 * @param IrImage[] $sizes Alternative image sizes for this image.
	 * @param string    $altText The alt text of the image.
	 * @since 5.5.0 Supports image alt text.
	 */
	public function __construct( string $url, string $source, Size $size = null, array $sizes = array(), string $altText = '' ) {
		$this->url = $url;
		$this->size = $size;
		$this->source = $source;
		$this->sizes = $sizes;
		$this->altText = self::sanitizeAltText( $altText );
	}

	/**
	 * Downloads the image and returns a result.
	 *
	 * @param int $postId Optional ID of the post to associate the image with. Use zero to only download the image.
	 * @return Result<int> The result, containing the ID of the downloaded image if successful.
	 * @since 5.5.0 Writes attachment alt text when available.
	 */
	public function download( int $postId = 0 ): Result {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Already exists by ID.
		if ( $this->id !== null ) {
			$existing = get_post( $this->id );
			if ( $existing instanceof WP_Post ) {
				return Result::Ok( $existing->ID );
			}
			return Result::Err( "Image #{$this->id} does not exist in the media library." );
		}

		// Base64 / Data URI.
		if ( strpos( $this->url, 'data:image' ) === 0 ) {
			return $this->download_base64_image( $postId );
		}

		// Already imported by URL.
		$existing = query_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => ImportedMedia::SOURCE_URL,
						'value' => $this->url,
					),
				),
			)
		);

		if ( count( $existing ) > 0 && is_object( $existing[0] ) ) {
			$this->updateAttachmentAltText( (int) $existing[0]->ID );
			return Result::Ok( $existing[0]->ID );
		}

		$desc = $postId > 0
			? sprintf( '[Aggregator] Downloaded image for imported item #%d', $postId )
			: 'Imported by WP RSS Aggregator';

		// Normal sideload with a filename derived from the original image URL.
		$id = $this->withImageRequestArgs(
			$this->url,
			function () use ( $postId, $desc ) {
				return $this->sideload_image( $this->url, $postId, $desc );
			}
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			$this->updateAttachmentAltText( (int) $id );
			return Result::Ok( (int) $id );
		}

		// Retry after normalizing common HTML/JSON escaping in the image URL.
		$this->url = trim( html_entity_decode( $this->url ) );
		$id = $this->withImageRequestArgs(
			$this->url,
			function () use ( $postId, $desc ) {
				return $this->sideload_image( $this->url, $postId, $desc );
			}
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			$this->updateAttachmentAltText( (int) $id );
			return Result::Ok( (int) $id );
		}

		// Final browser-safe anti-bot fallback.
		$id = $this->sideload_image_with_remote_get( $this->url, $postId, $desc );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			$this->updateAttachmentAltText( (int) $id );
			return Result::Ok( (int) $id );
		}

		return Result::Err( 'All image download attempts failed.' );
	}

	/**
	 * Base64 image download with hash deduplication.
	 */
	private function download_base64_image( int $postId ): Result {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		list($type, $data) = explode( ';', $this->url );
		list(, $data)      = explode( ',', $data );
		$binary = base64_decode( $data );
		$hash = hash( 'sha256', $binary );

		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'meta_query'  => array(
					array(
						'key'   => 'wprss_source_data_hash',
						'value' => $hash,
					),
				),
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		if ( count( $existing ) > 0 ) {
			$this->updateAttachmentAltText( (int) $existing[0] );
			return Result::Ok( (int) $existing[0] );
		}

		$tmp_file = wp_tempnam( 'wprss-datauri' );
		if ( ! $tmp_file || ! $wp_filesystem->put_contents( $tmp_file, $binary, FS_CHMOD_FILE ) ) {
			@unlink( $tmp_file );
			return Result::Err( 'Failed to create temporary file for Base64 image.' );
		}

		$mime_to_ext = array(
			'image/jpeg' => '.jpg',
			'image/png'  => '.png',
			'image/gif'  => '.gif',
			'image/bmp'  => '.bmp',
			'image/webp' => '.webp',
		);
		$mime_type = str_replace( 'data:', '', $type );
		$extension = $mime_to_ext[ $mime_type ] ?? '.jpg';
		$filename = 'image-' . uniqid() . $extension;

		$file_array = array(
			'name' => $filename,
			'tmp_name' => $tmp_file,
		);
		$desc = $postId > 0
			? sprintf( '[Aggregator] Downloaded image for imported item #%d', $postId )
			: 'Imported by WP RSS Aggregator';

		$id = media_handle_sideload( $file_array, $postId, $desc );
		@unlink( $tmp_file );

		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'wprss_source_data_hash', $hash );
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			$this->updateAttachmentAltText( (int) $id, $filename );
			return Result::Ok( $id );
		}

		return Result::Err( 'Failed to sideload Base64 image.' );
	}

	/**
	 * Robust fallback sideload: detects MIME type, fixes extensions, handles WebP, GIF, BMP.
	 */
	private function sideload_image( string $url, int $postId = 0, string $desc = '' ) {
		$tmp_file = download_url( $url, 15 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$mime_type = wp_get_image_mime( $tmp_file );
		$mime_type = is_string( $mime_type ) ? $mime_type : '';

		$filename = self::getRemoteImageFilename( $url, $mime_type );

		$file_array = array(
			'name' => $filename,
			'tmp_name' => $tmp_file,
		);
		$id = media_handle_sideload( $file_array, $postId, $desc );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp_file );
			return $id;
		}

		$this->updateAttachmentAltText( (int) $id, $filename );

		return $id;
	}

	private function sideload_image_with_remote_get( string $url, int $postId = 0, string $desc = '' ) {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 );
		$url = str_replace( '\\/', '/', $url );

		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '/\.(jpgx|pngx|jpegx)$/i', $path ) ) {
			$url = preg_replace( '/\.([a-z]+)x(\?|$)/i', '.$1$2', $url );
		}

		// Extract host for Referer.
		$parsed = parse_url( $url );
		$referer = is_array( $parsed ) && isset( $parsed['scheme'], $parsed['host'] )
			? $parsed['scheme'] . '://' . $parsed['host']
			: '';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'User-Agent'      => $this->getImageRequestUserAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
					'Accept'          => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
					'Referer'         => $referer,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code !== 200 || empty( $body ) ) {
			return new WP_Error( 'image_blocked', "Image blocked by remote host (HTTP $code)" );
		}

		$tmp = wp_tempnam( 'wprss-img' );
		file_put_contents( $tmp, $body );

		$mimeType = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$filename = self::getRemoteImageFilename( $url, $mimeType );
		$file_array = array(
			'name' => $filename,
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, $postId, $desc );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		$this->updateAttachmentAltText( (int) $id, $file_array['name'] );

		return $id;
	}

	/**
	 * Builds a safe filename candidate for a remote image URL.
	 *
	 * @since 5.5.0
	 *
	 * @param string $url The remote image URL.
	 * @param string $mimeType Optional detected image MIME type.
	 * @return string The filename candidate to pass to WordPress sideloading.
	 */
	private static function getRemoteImageFilename( string $url, string $mimeType = '' ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) );
		$url = str_replace( '\\/', '/', $url );

		$path = parse_url( $url, PHP_URL_PATH );
		$filename = is_string( $path ) ? rawurldecode( basename( $path ) ) : '';
		$filename = self::fixPseudoImageExtension( $filename );

		$extensions = self::getImageExtensions( $filename, $mimeType );
		$base = $extensions['matched'] !== ''
			? preg_replace( '/\.' . preg_quote( $extensions['matched'], '/' ) . '$/i', '', $filename )
			: $filename;

		$base = self::sanitizeFilenamePart( (string) $base );
		if ( ! self::isUsableImageFilenameBase( $base, $extensions['matched'] ) ) {
			$base = 'image-' . substr( hash( 'sha256', $url ), 0, 12 );
		}

		if ( $extensions['normalized'] === '' ) {
			$extensions['normalized'] = 'jpg';
		}

		return $base . '.' . $extensions['normalized'];
	}

	/**
	 * Gets image extensions from a filename or MIME type.
	 *
	 * @since 5.5.0
	 *
	 * @param string $filename The filename candidate.
	 * @param string $mimeType Optional detected image MIME type.
	 * @return array{matched:string,normalized:string} The matched and normalized extensions without leading dots.
	 */
	private static function getImageExtensions( string $filename, string $mimeType = '' ): array {
		if ( preg_match( '/\.(jpe?g|jpe|png|gif|bmp|webp|avif|tiff?|ico)$/i', $filename, $matches ) ) {
			$extension = strtolower( $matches[1] );
			return array(
				'matched' => $extension,
				'normalized' => $extension === 'jpeg' || $extension === 'jpe' ? 'jpg' : $extension,
			);
		}

		$mimeType = strtolower( trim( explode( ';', $mimeType )[0] ) );
		$mimeToExt = array(
			'image/jpeg'                => 'jpg',
			'image/png'                 => 'png',
			'image/gif'                 => 'gif',
			'image/bmp'                 => 'bmp',
			'image/webp'                => 'webp',
			'image/avif'                => 'avif',
			'image/tiff'                => 'tif',
			'image/x-icon'              => 'ico',
			'image/vnd.microsoft.icon' => 'ico',
		);

		$extension = $mimeToExt[ $mimeType ] ?? '';

		return array(
			'matched' => '',
			'normalized' => $extension,
		);
	}

	/**
	 * Corrects malformed image extensions observed in remote image URLs.
	 *
	 * @since 5.5.0
	 *
	 * @param string $filename The filename candidate.
	 * @return string The filename with a corrected pseudo-extension.
	 */
	private static function fixPseudoImageExtension( string $filename ): string {
		return (string) preg_replace_callback(
			'/\.(jpgx|jpegx|pngx)$/i',
			function ( array $matches ): string {
				$extension = strtolower( $matches[1] );
				return $extension === 'pngx' ? '.png' : '.jpg';
			},
			$filename
		);
	}

	/**
	 * Sanitizes one filename segment before WordPress applies final upload rules.
	 *
	 * @since 5.5.0
	 *
	 * @param string $part The filename segment.
	 * @return string The sanitized filename segment.
	 */
	private static function sanitizeFilenamePart( string $part ): string {
		$part = trim( $part );
		$part = sanitize_file_name( $part );

		return trim( (string) $part, '.-_' );
	}

	/**
	 * Checks whether a URL path segment carries usable filename information.
	 *
	 * @since 5.5.0
	 *
	 * @param string $base The sanitized filename base.
	 * @param string $matchedExtension The extension matched from the URL, if any.
	 * @return bool Whether the filename base should be used.
	 */
	private static function isUsableImageFilenameBase( string $base, string $matchedExtension ): bool {
		if ( $base === '' || $base === '.' || $base === '..' ) {
			return false;
		}

		return $matchedExtension !== '' || preg_match( '/[A-Za-z]/', $base ) === 1;
	}

	/**
	 * Updates an attachment's alt text when the importer has a useful value.
	 *
	 * @since 5.5.0
	 *
	 * @param int    $id The attachment ID.
	 * @param string $filename Optional filename to use for fallback alt text.
	 */
	private function updateAttachmentAltText( int $id, string $filename = '' ): void {
		$currentAlt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( is_string( $currentAlt ) && trim( $currentAlt ) !== '' ) {
			return;
		}

		$altText = $this->resolveAttachmentAltText( $filename );
		if ( $altText === '' ) {
			return;
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $altText );
	}

	/**
	 * Resolves alt text from source alt text first, then from a readable filename.
	 *
	 * @since 5.5.0
	 *
	 * @param string $filename Optional filename to use for fallback alt text.
	 * @return string The resolved alt text, or an empty string when none is useful.
	 */
	private function resolveAttachmentAltText( string $filename = '' ): string {
		if ( $this->altText !== '' ) {
			return $this->altText;
		}

		$fallback = $filename !== ''
			? $filename
			: $this->url;

		return self::altTextFromFilename( $fallback );
	}

	/**
	 * Builds readable fallback alt text from an image filename.
	 *
	 * @since 5.5.0
	 *
	 * @param string $filename The filename or URL to inspect.
	 * @return string The readable fallback alt text, or an empty string.
	 */
	public static function altTextFromFilename( string $filename ): string {
		$path = wp_parse_url( html_entity_decode( $filename, ENT_QUOTES | ENT_HTML5 ), PHP_URL_PATH );
		$name = is_string( $path ) && $path !== ''
			? basename( $path )
			: basename( $filename );

		$name = rawurldecode( $name );
		$name = preg_replace( '/\.(jpe?g|png|gif|bmp|webp|avif)$/i', '', $name );
		$name = preg_replace( '/-\d+x\d+$/', '', $name );
		$name = preg_replace( '/\b\d{3,5}x\d{3,5}\b/', ' ', $name );
		$name = preg_replace( '/[._-]+/', ' ', $name );
		$name = preg_replace( '/\b\d{8,}\b$/', '', $name );
		$name = self::sanitizeAltText( $name ?? '' );

		if ( $name === '' || self::isGenericFilenameAltText( $name ) ) {
			return '';
		}

		return ucfirst( strtolower( $name ) );
	}

	/**
	 * Sanitizes image alt text before storing it as attachment meta.
	 *
	 * @since 5.5.0
	 *
	 * @param string $altText The raw alt text.
	 * @return string The sanitized alt text.
	 */
	private static function sanitizeAltText( string $altText ): string {
		$altText = html_entity_decode( $altText, ENT_QUOTES | ENT_HTML5 );
		$altText = trim( preg_replace( '/\s+/', ' ', $altText ) ?? '' );

		return sanitize_text_field( $altText );
	}

	/**
	 * Checks whether filename-derived alt text is too generic to be useful.
	 *
	 * @since 5.5.0
	 *
	 * @param string $altText The normalized filename alt text.
	 * @return bool True when the text should not be used as fallback alt text.
	 */
	private static function isGenericFilenameAltText( string $altText ): bool {
		$normalized = strtolower( preg_replace( '/\s+/', '', $altText ) ?? '' );

		if ( ! preg_match( '/[a-z]/', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/^(img|dsc|image|photo|picture|thumbnail|thumb|cropped|intro|hero|main|featured|media|asset|upload|file|original|large|small)\d*$/', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/^(img|dsc|image|photo|picture|thumbnail|thumb|cropped|intro|hero|main|featured|media|asset|upload|file|original|large|small)[0-9a-f]{4,}$/', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/^[a-z](intro|hero|main|featured|media|asset|upload|file|original|large|small)$/', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/^[0-9a-f]{10,}$/', $normalized ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Runs a callback while applying image request headers to matching HTTP requests.
	 *
	 * @since 5.2.1
	 *
	 * @param string   $url The image URL.
	 * @param callable $callback The callback that triggers the image request.
	 * @return mixed The callback result.
	 */
	private function withImageRequestArgs( string $url, callable $callback ) {
		$userAgent = $this->getImageRequestUserAgent();
		if ( $userAgent === '' ) {
			return $callback();
		}

		$filter = function ( array $args, string $requestUrl ) use ( $url, $userAgent ): array {
			if ( $requestUrl !== $url ) {
				return $args;
			}

			$args['user-agent'] = $userAgent;
			$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] )
				? $args['headers']
				: array();

			$args['headers']['User-Agent'] = $userAgent;
			$args['headers']['Accept'] = $args['headers']['Accept'] ?? 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';

			return $args;
		};

		add_filter( 'http_request_args', $filter, 10, 2 );

		try {
			return $callback();
		} finally {
			remove_filter( 'http_request_args', $filter, 10 );
		}
	}

	/**
	 * Gets the User-Agent for remote image requests.
	 *
	 * @since 5.2.1
	 *
	 * @return string The User-Agent, or an empty string to use WordPress defaults.
	 */
	private function getImageRequestUserAgent(): string {
		return trim( $this->requestUserAgent );
	}

	/**
	 * Converts the IR image into an array.
	 *
	 * @since 5.5.0 Includes image alt text in the serialized value.
	 */
	public function toArray(): array {
		return array(
			'url' => $this->url,
			'source' => $this->source,
			'altText' => $this->altText,
			'size' => $this->size ? $this->size->toArray() : null,
			'sizes' => Arrays::map( $this->sizes, fn ( IrImage $image ) => $image->toArray() ),
		);
	}

	/**
	 * @since 5.5.0 Restores image alt text from the serialized value.
	 *
	 * @param array<string,mixed> $array
	 */
	public static function fromArray( array $array ): self {
		return new self(
			$array['url'] ?? '',
			$array['source'] ?? '',
			isset( $array['size'] ) ? Size::fromArray( $array['size'] ) : null,
			Arrays::map( $array['sizes'] ?? array(), fn ( array $size ) => self::fromArray( $size ) ),
			$array['altText'] ?? ''
		);
	}

	/**
	 * Creates an IR Image instance from a WP image ID.
	 *
	 * @param int    $id The ID of the WP image.
	 * @param string $source The source of the image.
	 * @return IrImage|null The IR image, or null if no image with the given ID exists.
	 */
	public static function fromWpImageId( int $id, string $source ): ?IrImage {
		$url = wp_get_attachment_url( $id );

		if ( $url === false ) {
			return null;
		} else {
			$image = new self( $url, $source );
			$image->id = $id;
			return $image;
		}
	}

	/**
	 * Creates an IR Image instance from a post's thumbnail.
	 *
	 * @param int $postId The ID of the post.
	 * @return IrImage|null The IR image, or null if the post does not exist or has no thumbnail.
	 */
	public static function fromPostThumbnail( int $postId ): ?IrImage {
		$thumbnailId = get_post_thumbnail_id( $postId );

		return $thumbnailId
			? self::fromWpImageId( $thumbnailId, static::FROM_WP )
			: null;
	}
}
