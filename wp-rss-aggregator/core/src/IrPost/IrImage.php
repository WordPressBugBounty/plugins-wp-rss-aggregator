<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\IrPost;

use WP_Post;
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
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Image has an ID already, so it should exist in the media library
		if ( $this->id !== null ) {
			$existing = get_post( $this->id );
			if ( $existing instanceof WP_Post ) {
				return Result::Ok( $existing->ID );
			} else {
				return Result::Err( "Image #{$this->id} does not exist in the media library." );
			}
		}

		if ( strpos( $this->url, 'data:image' ) === 0 ) {
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
					'post_type' => 'attachment',
					'post_status' => 'any',
					'meta_query' => array(
						array(
							'key' => 'wprss_source_data_hash',
							'value' => $hash,
						),
					),
				)
			);

			if ( count( $existing ) > 0 ) {
				return Result::Ok( $existing[0]->ID );
			}

			$tmp_file_path = wp_tempnam( 'wprss-datauri' );
			if ( ! $tmp_file_path ) {
				return Result::Err( 'Could not create temporary file.' );
			}

			if ( ! $wp_filesystem->put_contents( $tmp_file_path, $binary, FS_CHMOD_FILE ) ) {
				@unlink( $tmp_file_path );
				return Result::Err( 'Could not write to temporary file.' );
			}

			$mime_type = str_replace( 'data:', '', $type );
			$extension = '.jpg';
			$mime_to_ext = array(
				'image/jpeg' => '.jpg',
				'image/png'  => '.png',
				'image/gif'  => '.gif',
				'image/bmp'  => '.bmp',
				'image/webp' => '.webp',
			);
			if ( isset( $mime_to_ext[ $mime_type ] ) ) {
				$extension = $mime_to_ext[ $mime_type ];
			}

			$filename = 'image-' . uniqid() . $extension;

			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file_path,
			);

			$desc = ( $postId > 0 )
				? sprintf( __( '[Aggregator] Downloaded image for imported item #%d', 'wpra' ), $postId )
				: __( 'Imported by WP RSS Aggregator', 'wpra' );

			$id = media_handle_sideload( $file_array, $postId, $desc );

			@unlink( $tmp_file_path );

			if ( is_wp_error( $id ) ) {
				return Result::Err( $id->get_error_message() );
			} else {
				update_post_meta( $id, 'wprss_source_data_hash', $hash );
				update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
				return Result::Ok( $id );
			}
		}

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
		} else {
			$desc = ( $postId > 0 )
			? sprintf( __( '[Aggregator] Downloaded image for imported item #%d', 'wpra' ), $postId )
				: __( 'Imported by WP RSS Aggregator', 'wpra' );

			$result = media_sideload_image( $this->url, $postId, $desc, 'id' );

			if ( is_wp_error( $result ) ) {
				return Result::Err( $result->get_error_message() );
			} else {
				$id = (int) $result;
				update_post_meta( $id, ImportedMedia::SOURCE_URL, $this->url );
				return Result::Ok( $id );
			}
		}
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
