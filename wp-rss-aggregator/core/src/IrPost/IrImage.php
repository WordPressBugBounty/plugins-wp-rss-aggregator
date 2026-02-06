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

	/**
	 * Constructor.
	 *
	 * @param string    $url The URL of the image.
	 * @param string    $source The source of the image.
	 * @param Size|null $size The size of the image.
	 * @param IrImage[] $sizes Alternative image sizes for this image.
	 */
	public function __construct( string $url, string $source, Size $size = null, array $sizes = array() ) {
		$this->url = $url;
		$this->size = $size;
		$this->source = $source;
		$this->sizes = $sizes;
	}

	/**
	 * Downloads the image and returns a result.
	 *
	 * @param int $postId Optional ID of the post to associate the image with. Use zero to only download the image.
	 * @return Result<int> The result, containing the ID of the downloaded image if successful.
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
			return Result::Ok( $existing[0]->ID );
		}

		$desc = $postId > 0
			? sprintf( '[Aggregator] Downloaded image for imported item #%d', $postId )
			: 'Imported by WP RSS Aggregator';

		// Fast path: normal media sideload.
		$id = media_sideload_image( $this->url, $postId, $desc, 'id' );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			return Result::Ok( (int) $id );
		}

		// Robust fallback sideload.
		$this->url = trim( html_entity_decode( $this->url ) );
		$id = $this->sideload_image( $this->url, $postId, $desc );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
			return Result::Ok( (int) $id );
		}

		// Final browser-safe anti-bot fallback.
		$id = $this->sideload_image_with_remote_get( $this->url, $postId, $desc );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
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
			return Result::Ok( $existing[0]->ID );
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

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime_type = finfo_file( $finfo, $tmp_file );
		finfo_close( $finfo );

		$mime_to_ext = array(
			'image/jpeg' => '.jpg',
			'image/png'  => '.png',
			'image/gif'  => '.gif',
			'image/bmp'  => '.bmp',
			'image/webp' => '.webp',
		);

		$extension = $mime_to_ext[ $mime_type ] ?? '.jpg';
		$filename = basename( parse_url( $url, PHP_URL_PATH ) );
		if ( ! preg_match( '/\.(jpe?g|png|gif|bmp|webp)$/i', $filename ) ) {
			$filename .= $extension;
		}

		$file_array = array(
			'name' => $filename,
			'tmp_name' => $tmp_file,
		);
		$id = media_handle_sideload( $file_array, $postId, $desc );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp_file );
			return $id;
		}

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
		$parsed = parse_url($url);
		$referer = $parsed['scheme'] . '://' . $parsed['host'] ?? '';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
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

		$filename = basename( parse_url( $url, PHP_URL_PATH ) );
		$filename = preg_replace( '/\.(jpgx|pngx|jpegx)$/i', '.jpg', $filename );
		$file_array = array(
			'name' => $filename ?: 'image.jpg',
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, $postId, $desc );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		return $id;
	}

	/** Converts the IR image into an array. */
	public function toArray(): array {
		return array(
			'url' => $this->url,
			'source' => $this->source,
			'size' => $this->size ? $this->size->toArray() : null,
			'sizes' => Arrays::map( $this->sizes, fn ( IrImage $image ) => $image->toArray() ),
		);
	}

	/** @param array<string,mixed> $array */
	public static function fromArray( array $array ): self {
		return new self(
			$array['url'] ?? '',
			$array['source'] ?? '',
			isset( $array['size'] ) ? Size::fromArray( $array['size'] ) : null,
			Arrays::map( $array['sizes'] ?? array(), fn ( array $size ) => self::fromArray( $size ) )
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
