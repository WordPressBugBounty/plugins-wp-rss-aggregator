<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use WP_Post;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\IrPost\IrTerm;
use RebelCode\Aggregator\Core\IrPost\IrImage;
use RebelCode\Aggregator\Core\IrPost\IrAuthor;
use DateTime;

/**
 * An IR Post is an "Intermediate Representation" of a WordPress post. They are used to:
 *
 * - store the data for a WordPress post, prior to the post being created.
 * - provide a common interface for imported posts and curated posts.
 * - facilitate the creation of WordPress posts.
 */
class IrPost {

	/** The ID of the IR post in the database. */
	public ?int $id = null;
	/** The globally unique ID of the post. */
	public string $guid;
	/** @var int[] The IDs of the sources that imported the post. */
	public array $sources;
	/** The ID of corresponding WordPress post, if any. */
	public ?int $postId = null;
	/** The original URL of the post. */
	public string $url = '';
	/** The WP post type. */
	public string $type = 'wprss_feed_item';
	/** The WP post status. */
	public string $status = 'draft';
	/** The WP post format. */
	public string $format = 'standard';
	/** The slug of the post, aka `post_name`. */
	public string $slug = '';
	/** The title of the post. */
	public string $title = '';
	/** The excerpt of the post. */
	public string $excerpt = '';
	/** The content of the post. */
	public string $content = '';
	/** Information about the author of the post, if any. */
	public ?IrAuthor $author = null;
	/** The date the post was published, if any. */
	public ?DateTime $datePublished = null;
	/** The date the post was last modified, if any. */
	public ?DateTime $dateModified = null;
	/** The featured image of the post, if any. */
	public ?IrImage $ftImage = null;
	/** Whether comments on the post are open. */
	public bool $commentsOpen = false;
	/** The password, for password-protected posts, or empty for public posts. */
	public string $password = '';
	/** @var IrImage[] The found images in the item. */
	public array $images = array();
	/** @var array<string, IrTerm[]> The taxonomy terms, grouped into sub-arrays and keyed by their taxonomy. */
	public array $terms = array();
	/** @var array An associative array of post meta data. */
	public array $meta = array();
	/** The ID of the parent post, or zero for no parent. */
	public int $parentId = 0;

	// These properties are not saved in the database
	public string $attribution = '';
	public string $audio = '';

	/**
	 * Constructor.
	 *
	 * @param string   $guid The GUID of the item.
	 * @param int|null $postId The ID of the WordPress post, if any.
	 * @param int[]    $sources The IDs of the item's sources.
	 */
	public function __construct( string $guid, ?int $postId, array $sources, string $url = '' ) {
		$this->guid = $guid;
		$this->postId = $postId;
		$this->sources = $sources;
		$this->url = $url;
	}

	/**
	 * Retrieves the date of the post.
	 *
	 * This method attempts to return the published date of the post. If the post has no published date, then the
	 * modified date is returned instead, if is has one.
	 *
	 * @return DateTime|null The date of the post, or null if the post has no date.
	 */
	public function getDate(): ?DateTime {
		return $this->datePublished ?? $this->dateModified;
	}

	/**
	 * Gets a single value for a meta key.
	 *
	 * @param string $key The meta key.
	 * @param mixed  $default The default value to return if the meta key is not found.
	 * @param int    $index The index of the value to return.
	 * @return mixed
	 */
	public function getSingleMeta( string $key, $default = null, int $index = 0 ) {
		return $this->meta[ $key ][ $index ] ?? $default;
	}

	/**
	 * Gets all values for a meta key.
	 *
	 * @param string      $key The meta key.
	 * @param list<mixed> $default The default values to return.
	 * @return list<mixed>
	 */
	public function getMeta( string $key, array $default = array() ): array {
		return $this->meta[ $key ] ?? $default;
	}

	/**
	 * Gets the embed URL for the post.
	 *
	 * @return string The embed URL.
	 */
	public function getEmbedUrl(): string {
		$isYt = $this->isYouTube();
		$ytUrl = $this->getSingleMeta( ImportedPost::YT_EMBED_URL, '' );

		if ( $isYt && ! empty( $ytUrl ) ) {
			return $ytUrl;
		}

		$audioUrl = $this->getSingleMeta( ImportedPost::AUDIO_URL, '' );

		if ( ! empty( $audioUrl ) ) {
			return $audioUrl;
		}

		$enclosureUrl = $this->getSingleMeta( ImportedPost::ENCLOSURE_URL, '' );

		if ( ! empty( $enclosureUrl ) ) {
			return $enclosureUrl;
		}

		return '';
	}

	public function isYouTube(): bool {
		return (bool) $this->getSingleMeta( ImportedPost::IS_YT, false );
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$content = apply_filters( 'the_content', $this->content );

		return array(
			'id' => $this->id,
			'postId' => $this->postId,
			'guid' => $this->guid,
			'sources' => $this->sources,
			'type' => $this->type,
			'status' => $this->status,
			'format' => $this->format,
			'url' => $this->url,
			'title' => $this->title,
			'content' => $content,
			'excerpt' => $this->excerpt,
			'author' => $this->author ? $this->author->toArray() : null,
			'datePublished' => $this->datePublished ? Time::toHumanFormat( $this->datePublished ) : null,
			'dateModified' => $this->dateModified ? Time::toHumanFormat( $this->dateModified ) : null,
			'commentsOpen' => $this->commentsOpen,
			'ftImage' => $this->ftImage ? $this->ftImage->toArray() : null,
			'images' => Arrays::toArrayAll( $this->images ),
			'terms' => array_map( fn ( array $terms ) => array_map( fn ( IrTerm $t ) => $t->toArray(), $terms ), $this->terms ),
			'meta' => $this->meta,
			'parentId' => $this->parentId,
			'extra' => array(
				'attribution' => $this->attribution,
				'audio' => $this->audio,
			),
		);
	}

	/**
	 * Creates an IR post from a WordPress post.
	 *
	 * @param WP_Post $post The WordPress post.
	 * @return IrPost The IR post.
	 */
	public static function fromWpPost( WP_Post $post ): IrPost {
		$guid = get_post_meta( $post->ID, ImportedPost::GUID, true );
		$url = get_post_meta( $post->ID, ImportedPost::URL, true );

		$srcMeta = get_post_meta( $post->ID, ImportedPost::SOURCE );
		$sources = array();
		foreach ( $srcMeta as $srcId ) {
			if ( is_string( $srcId ) ) {
				$sources[] = intval( $srcId );
			}
		}

		$dateModified = wp_resolve_post_date( $post->post_modified, $post->post_modified_gmt ) ?? current_time( 'mysql' );
		$datePublished = wp_resolve_post_date( $post->post_date, $post->post_date_gmt ) ?? $dateModified;

		$irPost = new IrPost( $guid, $post->ID, $sources, $url );
		$irPost->type = $post->post_type;
		$irPost->status = $post->post_status;
		$irPost->format = get_post_format( $post->ID ) ?: 'standard';
		$irPost->slug = $post->post_name;
		$irPost->title = $post->post_title;
		$irPost->excerpt = $post->post_excerpt;
		$irPost->content = $post->post_content;
		$irPost->author = IrAuthor::fromWpUserId( (int) $post->post_author );
		$irPost->datePublished = Time::createAndCatch( $datePublished );
		$irPost->dateModified = Time::createAndCatch( $dateModified );
		$irPost->commentsOpen = ( strtolower( $post->comment_status ) === 'open' );
		$irPost->password = $post->post_password;
		$irPost->images = array();
		$irPost->ftImage = IrImage::fromPostThumbnail( $post->ID );
		$irPost->terms = IrTerm::getForWpPost( $post );
		$irPost->meta = get_post_meta( $post->ID );
		$irPost->parentId = $post->post_parent;

		return $irPost;
	}
}
