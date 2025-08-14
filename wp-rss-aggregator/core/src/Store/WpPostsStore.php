<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Store;

use WP_Query;
use WP_Post;
use Throwable;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\RejectedItem;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\IrPost;
use RebelCode\Aggregator\Core\ImportedPost;
use RebelCode\Aggregator\Core\Exception\NotFoundException;
use RebelCode\Aggregator\Core\Database;
use DateTime;

class WpPostsStore {

	public const ID = 'ID';
	public const AUTHOR = 'post_author';
	public const DATE = 'post_date';
	public const DATE_GMT = 'post_date_gmt';
	public const CONTENT = 'post_content';
	public const TITLE = 'post_title';
	public const EXCERPT = 'post_excerpt';
	public const STATUS = 'post_status';
	public const COMMENT_STATUS = 'comment_status';
	public const PING_STATUS = 'ping_status';
	public const PASSWORD = 'post_password';
	public const NAME = 'post_name';
	public const TO_PING = 'to_ping';
	public const PINGED = 'pinged';
	public const MODIFIED = 'post_modified';
	public const MODIFIED_GMT = 'post_modified_gmt';
	public const CONTENT_FILTERED = 'post_content_filtered';
	public const PARENT = 'post_parent';
	public const GUID = 'guid';
	public const MENU_ORDER = 'menu_order';
	public const TYPE = 'post_type';
	public const POST_MIME_TYPE = 'post_mime_type';
	public const COMMENT_COUNT = 'comment_count';

	public const IMPORT_DATE_ORDER = 'import_date';

	private Database $db;
	public string $posts;
	public string $meta;
	protected RejectListStore $rejectList;
	private ?array $postTypes = null;

	public function __construct( Database $db, string $posts, string $meta, RejectListStore $rejectList ) {
		$this->db = $db;
		$this->posts = $posts;
		$this->meta = $meta;
		$this->rejectList = $rejectList;
	}

	/** @return WP_Query */
	private function wpQuery( array $args = array() ): WP_Query {
		if ( $this->postTypes === null ) {
			$this->postTypes = get_post_types( array( 'public' => true ), 'names' );
		}

		$metaQuery = $args['meta_query'] ?? array();
		unset( $args['meta_query'] );

		$args = wp_parse_args(
			$args,
			array(
				'post_type' => $this->postTypes,
				'post_status' => 'any',
				'ignore_sticky_posts' => true,
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'meta_query' => wp_parse_args(
					$metaQuery,
					array(
						'relation' => 'AND',
						'source' => array(
							'key' => ImportedPost::SOURCE,
							'compare' => 'EXISTS',
						),
					)
				),
			)
		);

		$args = apply_filters( 'wpra.importer.wpPosts.query.args', $args );

		$query = new WP_Query( $args );
		wp_reset_postdata();

		return $query;
	}

	/** @return Result<iterable<IrPost>> */
	public function queryFilters( array $srcIds, array $excludeIds, ?int $num = null, int $page = 1 ) {
		$sql = "SELECT * FROM {$this->posts}
        LEFT JOIN {$this->meta} AS `m` ON `m`.`post_id` = `ID` ";

		$where = array( '`m`.`meta_key` = %s' );
		$args = array( ImportedPost::SOURCE );

		if ( ! empty( $srcIds ) ) {
			$srcIdList = $this->db->prepareList( $srcIds, '%d', $args );
			$where[] = "(`m`.`meta_value` IN ({$srcIdList}))";
		}

		if ( ! empty( $excludeIds ) ) {
			$excIdList = $this->db->prepareList( $excludeIds, '%d', $args );
			$where[] = "(`m`.`meta_value` IN ({$excIdList}))";
		}

		[$where, $args] = apply_filters( 'wpra.importer.wpPosts.query.where', array( $where, $args ) );

		$whereStr = implode( ' AND ', $where );
		$pagination = $this->db->pagination( $num, $page );

		$sql .= "WHERE {$whereStr}
        ORDER BY `post_date` DESC
        {$pagination}";

		try {
			$rows = $this->db->getResults( $sql, $args );

			$irPosts = array();
			foreach ( $rows as $row ) {
				$irPosts[] = $this->rowToIrPost( $row );
			}

			return Result::Ok( $irPosts );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/**
	 * Similar to {@see query()}, but accepts filters rather than expressions.
	 *
	 * @param string    $filter Optional search filter. Searches post titles and content.
	 * @param list<int> $sources The IDs of the sources to get IR posts from.
	 * @param int|null  $num Optional limit.
	 * @param int       $page Optional offset.
	 * @param string    $orderBy Optoinal column to sort by.
	 * @param string    $order Either "asc" or "desc"
	 * @return Result<iterable<IrPost>> A result containing a list of IR posts.
	 */
	public function getList(
		string $filter = '',
		array $sources = array(),
		?int $num = null,
		int $page = 1,
		string $orderBy = '',
		string $order = 'asc'
	): Result {
		$args = array(
			's' => $filter,
			'posts_per_page' => $num ?? -1,
			'paged' => max( 1, $page ),
			'order' => $order,
			'orderby' => $orderBy,
			'meta_query' => array(),
		);

		if ( ! empty( $sources ) ) {
			$args['meta_query']['source'] = array(
				'key' => ImportedPost::SOURCE,
				'compare' => 'IN',
				'value' => $sources,
			);
		}

		if ( $orderBy === 'import_date' ) {
			// the ID sorting helps results be in a consistent order, for cases
			// where posts have the exact same import date
			$args['orderby'] = ['meta_value_num' => $order, 'ID' => $order];
			$args['meta_type'] = 'DATE';
			$args['meta_key'] = ImportedPost::IMPORT_DATE;
		}

		$results = $this->wpQuery( $args );

		$irPosts = array();
		foreach ( $results->posts as $wpPost ) {
			$irPosts[] = IrPost::fromWpPost( $wpPost );
		}

		return Result::Ok( $irPosts );
	}

	/**
	 * Gets an imported post by its ID.
	 *
	 * @param int $id The ID of the post to retrieve.
	 * @return Result<IrPost> A result containing the IR post.
	 */
	public function getById( int $id ): Result {
		$posts = $this->wpQuery( array( 'p' => $id ) );

		if ( empty( $posts ) ) {
			return Result::Err(
				new NotFoundException(
					sprintf( __( 'Post #%s does not exist or is not imported by WP RSS Aggregator', 'wp-rss-aggregator' ), $id )
				)
			);
		}

		$wpPost = reset( $posts );
		$irPost = IrPost::fromWpPost( $wpPost );

		return Result::Ok( $irPost );
	}

	/**
	 * Retrieves multiple imported posts by their IDs.
	 *
	 * @param list<int> $ids The IDs of the posts to retrieve.
	 * @return Result<iterable<IrPost>> A result containing a list of IR posts.
	 */
	public function getManyByIds( array $ids ): Result {
		if ( empty( $ids ) ) {
			return Result::Ok( array() );
		}

		$result = $this->wpQuery( array( 'post__in' => $ids ) );

		$irPosts = array();
		foreach ( $result->posts as $post ) {
			$irPosts[] = IrPost::fromWpPost( $post );
		}

		return Result::Ok( $irPosts );
	}

	/**
	 * Retrieves multiple imported posts by their GUIDS.
	 *
	 * @param list<string> $guids The GUIDs of the posts to retrieve.
	 * @return Result<iterable<IrPost>> A result containing a list of IR posts.
	 */
	public function getManyByGuids( array $guids ): Result {
		if ( empty( $guids ) ) {
			return Result::Ok( array() );
		}

		$cacheKey = 'wpra_posts_by_guid_' . md5( implode( ',', $guids ) );
		$cachedPosts = wp_cache_get( $cacheKey, 'wpra' );

		if ( $cachedPosts !== false ) {
			return Result::Ok( $cachedPosts );
		}

		$sql = "SELECT p.* FROM {$this->posts} p
                INNER JOIN {$this->meta} m ON p.ID = m.post_id
                WHERE m.meta_key = %s AND m.meta_value IN (" . implode( ',', array_fill( 0, count( $guids ), '%s' ) ) . ')
                GROUP BY p.ID';

		$args = array_merge( array( ImportedPost::GUID ), $guids );

		try {
			$rows = $this->db->getResults( $sql, $args );

			$irPosts = array();
			foreach ( $rows as $row ) {
				$irPosts[] = $this->rowToIrPost( (array) $row );
			}

			wp_cache_set( $cacheKey, $irPosts, 'wpra', 3600 );

			return Result::Ok( $irPosts );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	public function titleExists( string $title ): Result {
		$args = array(
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			's'                => sanitize_text_field( $title ?? '' ),
			'search_columns'   => array( 'post_title' ),
		);

		$query = $this->wpQuery( $args );

		return Result::Ok( absint( $query->post_count ) > 0 );
	}

	/**
	 * Gets posts imported from multiple feed sources.
	 *
	 * @param list<int> $srcIds The IDs of the sources.
	 * @param string    $order "ASC" or "DESC". Posts are sorted by their date.
	 * @return Result<iterable<IrPost>> The list of IR posts.
	 */
	public function getFromSources( array $srcIds, ?int $num = null, int $page = 1, string $order = 'DESC' ): Result {
		if ( count( $srcIds ) === 0 ) {
			return Result::Ok( array() );
		}

		$result = $this->wpQuery(
			array(
				'orderby' => 'date',
				'order' => $order,
				'posts_per_page' => $num ?? -1,
				'paged' => max( 1, $page ),
				'meta_query' => array(
					array(
						'key' => ImportedPost::SOURCE,
						'compare' => 'IN',
						'value' => $srcIds,
					),
				),
			)
		);

		$irPosts = array();
		foreach ( $result->posts as $post ) {
			$irPosts[] = IrPost::fromWpPost( $post );
		}

		return Result::Ok( $irPosts );
	}

	/**
	 * Deletes an imported post by its ID.
	 *
	 * @param int $id The ID of the post to delete.
	 * @return Result<int> The number of deleted posts.
	 */
	public function deleteById( int $id, bool $reject = false ): Result {
		$result = $this->getById( $id );

		if ( $result->isOk() ) {
			$post = $result->get();

			if ( $reject ) {
				$note = $this->deleteRejectionNote( $post->title );
				$result = $this->rejectList->add( new RejectedItem( $post->guid, null, $note ) );

				if ( $result->isErr() ) {
					return Result::Err( $result->error() );
				}
			}

			$success = wp_delete_post( $post->postId ?? 0, true );

			if ( $success ) {
				return Result::Ok( 1 );
			} else {
				return Result::Err( "Post #{$id} could not be deleted" );
			}
		} else {
			return Result::Err( $result->error() );
		}
	}

	/**
	 * Deletes multiple posts by their IDs.
	 *
	 * @param list<int> $ids The IDs of the posts to delete.
	 * @return Result<int> The number of deleted posts.
	 */
	public function deleteManyByIds( array $ids, bool $reject = false ): Result {
		$result = $this->getManyByIds( $ids );

		if ( $result->isOk() ) {
			$posts = $result->get();
			$num = $this->deleteWpPosts( $posts, $reject );
			return Result::Ok( $num );
		} else {
			return Result::Err( $result->error() );
		}
	}

	/**
	 * Deletes posts from a particular source.
	 *
	 * @param list<int> $srcIds The ID of the source.
	 * @param string    $order "ASC" or "DESC". Posts are sorted by their date.
	 * @return Result<int> The number of deleted posts.
	 */
	public function deleteFromSources( array $srcIds, bool $reject = false, ?int $num = null, int $page = 1, string $order = 'DESC' ): Result {
		$result = $this->getFromSources( $srcIds, $num, $page, $order );

		if ( $result->isOk() ) {
			$posts = $result->get();
			$num = $this->deleteWpPosts( $posts, $reject );
			return Result::Ok( $num );
		} else {
			return Result::Err( $result->error() );
		}
	}

	/**
	 * Deletes posts older than a given date, optionally from a specific source.
	 *
	 * @return Result<int> The number of deleted posts.
	 */
	public function deleteOlderThan( DateTime $minDate, ?int $srcId = null ): Result {
		$metaQuery = array();
		if ( $srcId !== null ) {
			$metaQuery = array(
				'source' => array(
					'key' => ImportedPost::SOURCE,
					'value' => $srcId,
				),
			);
		}

		$result = $this->wpQuery(
			array(
				'meta_query' => $metaQuery,
				'date_query' => array(
					'column' => 'post_date_gmt',
					'before' => $minDate->format( 'Y-m-d H:i:s' ),
				),
			)
		);

		$num = $this->deleteWpPosts( $result->posts );
		return Result::Ok( $num );
	}

	/**
	 * Deletes all imported posts.
	 *
	 * @return Result<int> The number of deleted posts.
	 */
	public function deleteAll( bool $reject = false ): Result {
		$num = 0;
		do {
			$result = $this->wpQuery(
				array(
					'posts_per_page' => 50,
				)
			);

			if ( ! empty( $result ) ) {
				$irPosts = ( function ( \WP_Query $result ) {
					foreach ( $result->posts as $post ) {
						yield IrPost::fromWpPost( $post );
					}
				} )( $result );

				$num += $this->deleteWpPosts( $irPosts, $reject );
			}
		} while ( ! empty( $result->posts ) );

		return Result::Ok( $num );
	}

	/**
	 * Gets the number of imported WordPress posts.
	 *
	 * @return Result<int> The number of imported posts.
	 */
	public function getCount( array $srcIds = array() ): Result {
		$queryArgs = array(
			'posts_per_page'   => -1,
			'fields'           => 'ids',
		);

		if ( ! empty( $srcIds ) ) {
			$queryArgs['meta_query']['source'] = array(
				'key' => ImportedPost::SOURCE,
				'compare' => 'IN',
				'value' => $srcIds,
			);
		}

		$result = $this->wpQuery( $queryArgs );

		return Result::Ok( $result->post_count );
	}

	/**
	 * Gets the number of imported WordPress posts for each source.
	 *
	 * @param list<int> $srcIds The IDs of the sources.
	 * @return Result<array<int, int>> A map of source IDs to their post counts.
	 */
	public function getCountsBySource( array $srcIds ): Result {
		if ( empty( $srcIds ) ) {
			return Result::Ok( array() );
		}

		try {
			$args = array( ImportedPost::SOURCE );
			$idsList = $this->db->prepareList( $srcIds, '%d', $args );

			$sql = "SELECT meta_value as source_id, COUNT(*) as count
                    FROM {$this->meta}
                    WHERE meta_key = %s AND meta_value IN ({$idsList})
                    GROUP BY meta_value";

			$results = $this->db->getResults( $sql, $args );

			$counts = array();
			foreach ( $results as $row ) {
				$counts[ (int) $row['source_id'] ] = (int) $row['count'];
			}

			foreach ( $srcIds as $id ) {
				if ( ! isset( $counts[ $id ] ) ) {
					$counts[ $id ] = 0;
				}
			}

			return Result::Ok( $counts );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/** @param iterable<IrPost> $posts */
	public function deleteWpPosts( iterable $posts, bool $reject = false ): int {
		$num = 0;

		foreach ( $posts as $post ) {
			if ( $reject ) {
				$note = $this->deleteRejectionNote( $post->title );
				$this->rejectList->add( new RejectedItem( $post->guid, null, $note ) );
			}

			$success = wp_delete_post( $post->postId, true );

			if ( $success ) {
				$num++;
			} else {
				Logger::warning( "Post #{$post->postId} could not be deleted." );
			}
		}

		return $num;
	}

	/** @param array<string, mixed> $row */
	protected function rowToIrPost( array $row ): IrPost {
		$postObj = (object) sanitize_post( $row, 'raw' );
		$post = new WP_Post( $postObj );

		return IrPost::fromWpPost( $post );
	}

	/** The note to use when a post is deleted and rejected. */
	protected function deleteRejectionNote( string $title ): string {
		return sprintf(
			_x( 'Rejected after deletion: %s', 'The recorded note when an imported post is deleted and rejected. %s = post title', 'wp-rss-aggregator' ),
			$title
		);
	}
}
