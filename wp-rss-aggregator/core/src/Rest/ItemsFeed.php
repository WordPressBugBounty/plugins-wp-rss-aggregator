<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Rest;

use InvalidArgumentException;
use RebelCode\Aggregator\Core\Capabilities;
use RebelCode\Aggregator\Core\Database;
use RebelCode\Aggregator\Core\ImportedPost;
use WP_Error;

/** Builds the payload for the public, read-only `wpra/v1/items` endpoint. */
class ItemsFeed {

	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 100;
	public const EXCERPT_WORDS = 55;
	public const THUMBNAIL = '_thumbnail_id';

	public Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	public const HOLDBACK_SECONDS = 2;
	public const FUTURE_TOLERANCE_SECONDS = 5;

	/** @return true|WP_Error */
	public static function checkReadAccess() {
		if ( apply_filters( 'wpra_rest_items_public', true ) || current_user_can( Capabilities::SEE_AGGREGATOR ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			'Sorry, you are not allowed to read items.',
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/** Clamps the raw page size. */
	public static function clampPerPage( $perPage ): int {
		$perPage = is_numeric( $perPage ) ? (int) $perPage : self::DEFAULT_LIMIT;

		return max( 1, min( self::MAX_LIMIT, $perPage ) );
	}

	public static function encodeCursor( string $modifiedGmt, int $id ): string {
		return rtrim( strtr( base64_encode( $modifiedGmt . '|' . $id ), '+/', '-_' ), '=' );
	}

	/**
	 * @param mixed $cursor
	 * @return array{0:string,1:int}|null The modified time and ID, or null when there is no cursor.
	 * @throws InvalidArgumentException When the cursor is malformed or out of range.
	 */
	public static function decodeCursor( $cursor ): ?array {
		if ( $cursor === null || $cursor === '' ) {
			return null;
		}

		$decoded = is_string( $cursor ) ? base64_decode( strtr( $cursor, '-_', '+/' ), true ) : false;
		if ( $decoded === false || ! preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,19})\z/', $decoded, $m ) ) {
			throw new InvalidArgumentException( 'Invalid cursor' );
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $m[1], new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			$date === false
			|| ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
			|| $date->format( 'Y-m-d H:i:s' ) !== $m[1]
			|| $date->getTimestamp() > time() + self::FUTURE_TOLERANCE_SECONDS
		) {
			throw new InvalidArgumentException( 'Invalid cursor' );
		}

		$id = $m[2];
		if ( strlen( $id ) === 19 && strcmp( $id, (string) PHP_INT_MAX ) > 0 ) {
			throw new InvalidArgumentException( 'Invalid cursor' );
		}

		return array( $m[1], (int) $id );
	}

	/**
	 * @param mixed $cursor
	 * @param mixed $perPage
	 * @return array{items:list<array<string,mixed>>,next_cursor:?string}
	 * @throws InvalidArgumentException When the cursor is invalid.
	 */
	public function fetch( $cursor, $perPage ): array {
		$position = self::decodeCursor( $cursor );
		$limit = self::clampPerPage( $perPage );
		$holdback = max( 0, (int) apply_filters( 'wpra_rest_items_holdback_seconds', self::HOLDBACK_SECONDS ) );
		$newest = gmdate( 'Y-m-d H:i:s', time() - $holdback );

		$posts = $this->db->wpdb->posts;
		$postmeta = $this->db->wpdb->postmeta;

		$types = array_values( array_unique( array_merge( array_values( get_post_types( array( 'public' => true ) ) ), array( 'wprss_feed_item' ) ) ) );
		$args = array( $newest );
		$after = '';
		if ( $position !== null ) {
			$after = 'AND (p.post_modified_gmt > %s OR (p.post_modified_gmt = %s AND p.ID > %d))';
			array_push( $args, $position[0], $position[0], $position[1] );
		}
		$typeList = $this->db->prepareList( $types, '%s', $args );
		$args[] = ImportedPost::SOURCE;
		$args[] = $limit;

		$rows = $this->db->getResults(
			"SELECT p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_date_gmt, p.post_modified_gmt
			FROM {$posts} p
			WHERE p.post_status = 'publish'
			AND p.post_password = ''
			AND p.post_modified_gmt <= %s
			{$after}
			AND p.post_type IN ({$typeList})
			AND EXISTS (SELECT 1 FROM {$postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s)
			ORDER BY p.post_modified_gmt ASC, p.ID ASC
			LIMIT %d",
			$args
		);

		if ( count( $rows ) === 0 ) {
			return array(
				'items' => array(),
				'next_cursor' => $position === null ? null : $cursor,
			);
		}

		$ids = array_map( fn ( $row ) => (int) $row['ID'], $rows );
		$meta = $this->fetchMeta( $ids );

		$sourceIds = array();
		foreach ( $meta as $postMeta ) {
			if ( isset( $postMeta[ ImportedPost::SOURCE ] ) ) {
				$sourceIds[] = (int) $postMeta[ ImportedPost::SOURCE ];
			}
		}
		$sourceNames = $this->fetchSourceNames( $sourceIds );

		$thumbIds = array();
		foreach ( $meta as $postMeta ) {
			if ( empty( $postMeta[ ImportedPost::FT_IMAGE_URL ] ) && ! empty( $postMeta[ self::THUMBNAIL ] ) ) {
				$thumbIds[] = (int) $postMeta[ self::THUMBNAIL ];
			}
		}
		$attachmentUrls = $this->fetchAttachmentUrls( $thumbIds );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::mapItem( $row, $meta[ (int) $row['ID'] ] ?? array(), $sourceNames, $attachmentUrls );
		}

		$last = end( $rows );

		return array(
			'items' => $items,
			'next_cursor' => self::encodeCursor( (string) $last['post_modified_gmt'], (int) $last['ID'] ),
		);
	}

	/**
	 * Maps a post row and its meta to the response shape.
	 *
	 * @param array<string,mixed>  $row The post row.
	 * @param array<string,string> $meta The post's WPRA meta, by key. Multi-value keys hold the first value.
	 * @param array<int,string>    $sourceNames Source names by source ID.
	 * @param array<int,string>    $attachmentUrls Attachment URLs by attachment ID, used when there is no remote image URL.
	 * @return array<string,mixed>
	 */
	public static function mapItem( array $row, array $meta, array $sourceNames, array $attachmentUrls = array() ): array {
		$sourceId = isset( $meta[ ImportedPost::SOURCE ] ) ? (int) $meta[ ImportedPost::SOURCE ] : null;

		$name = $sourceId !== null ? ( $sourceNames[ $sourceId ] ?? '' ) : '';
		if ( $name === '' ) {
			$name = (string) ( $meta[ ImportedPost::SOURCE_NAME ] ?? '' );
		}

		$excerpt = (string) $row['post_excerpt'] !== '' ? $row['post_excerpt'] : $row['post_content'];
		$image = (string) ( $meta[ ImportedPost::FT_IMAGE_URL ] ?? '' );
		if ( $image === '' && isset( $meta[ self::THUMBNAIL ] ) ) {
			$image = $attachmentUrls[ (int) $meta[ self::THUMBNAIL ] ] ?? '';
		}
		$url = (string) ( $meta[ ImportedPost::URL ] ?? '' );
		$guid = (string) ( $meta[ ImportedPost::GUID ] ?? '' );
		$author = self::plainText( (string) ( $meta[ ImportedPost::AUTHOR_NAME ] ?? '' ) );

		return array(
			'id' => (int) $row['ID'],
			'title' => self::plainText( (string) $row['post_title'] ),
			'excerpt' => self::plainText( (string) $excerpt, self::EXCERPT_WORDS ),
			'date_gmt' => self::isoDate( (string) $row['post_date_gmt'] ),
			'modified_gmt' => self::isoDate( (string) ( $row['post_modified_gmt'] ?? '' ) ),
			'url' => $url !== '' ? $url : null,
			'source' => array(
				'id' => $sourceId,
				'name' => $name,
			),
			'image' => $image !== '' ? $image : null,
			'guid' => $guid !== '' ? $guid : null,
			'author' => $author !== '' ? $author : null,
		);
	}

	public static function isoDate( string $gmt ): ?string {
		$time = strtotime( $gmt . ' UTC' );
		if ( $gmt === '' || str_starts_with( $gmt, '0000' ) || $time === false ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	public static function plainText( string $html, int $words = 0 ): string {
		$text = trim( wp_strip_all_tags( $html ) );
		if ( $words > 0 ) {
			$text = wp_trim_words( $text, $words );
		}

		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Fetches the WPRA meta for a batch of posts in one query.
	 *
	 * @param list<int> $ids
	 * @return array<int,array<string,string>> Meta by post ID, then key. The first value wins for repeated keys.
	 */
	protected function fetchMeta( array $ids ): array {
		$args = array();
		$idList = $this->db->prepareList( $ids, '%d', $args );
		$keys = array(
			ImportedPost::SOURCE,
			ImportedPost::SOURCE_NAME,
			ImportedPost::URL,
			ImportedPost::FT_IMAGE_URL,
			ImportedPost::GUID,
			ImportedPost::AUTHOR_NAME,
			self::THUMBNAIL,
		);
		$keyList = $this->db->prepareList( $keys, '%s', $args );

		$rows = $this->db->getResults(
			"SELECT post_id, meta_key, meta_value FROM {$this->db->wpdb->postmeta}
			WHERE post_id IN ({$idList}) AND meta_key IN ({$keyList})
			ORDER BY meta_id ASC",
			$args
		);

		$meta = array();
		foreach ( $rows as $row ) {
			$postId = (int) $row['post_id'];
			if ( ! isset( $meta[ $postId ][ $row['meta_key'] ] ) ) {
				$meta[ $postId ][ $row['meta_key'] ] = (string) $row['meta_value'];
			}
		}

		return $meta;
	}

	/**
	 * @param list<int> $sourceIds
	 * @return array<int,string> Source names by ID.
	 */
	protected function fetchSourceNames( array $sourceIds ): array {
		$sourceIds = array_values( array_unique( $sourceIds ) );
		if ( count( $sourceIds ) === 0 ) {
			return array();
		}

		$args = array();
		$idList = $this->db->prepareList( $sourceIds, '%d', $args );
		$table = $this->db->tableName( 'sources' );

		$rows = $this->db->getResults( "SELECT id, name FROM {$table} WHERE id IN ({$idList})", $args );

		$names = array();
		foreach ( $rows as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $names;
	}

	/**
	 * Resolves attachment URLs for a batch of attachment IDs in one query.
	 *
	 * @param list<int> $attachmentIds
	 * @return array<int,string> Full-size URLs by attachment ID.
	 */
	protected function fetchAttachmentUrls( array $attachmentIds ): array {
		$attachmentIds = array_values( array_unique( array_filter( $attachmentIds ) ) );
		if ( count( $attachmentIds ) === 0 ) {
			return array();
		}

		$args = array( '_wp_attached_file' );
		$idList = $this->db->prepareList( $attachmentIds, '%d', $args );

		$rows = $this->db->getResults(
			"SELECT post_id, meta_value FROM {$this->db->wpdb->postmeta}
			WHERE meta_key = %s AND post_id IN ({$idList})",
			$args
		);

		$baseUrl = untrailingslashit( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ) );

		$urls = array();
		foreach ( $rows as $row ) {
			if ( $row['meta_value'] !== '' ) {
				$urls[ (int) $row['post_id'] ] = $baseUrl . '/' . ltrim( (string) $row['meta_value'], '/' );
			}
		}

		return $urls;
	}
}
