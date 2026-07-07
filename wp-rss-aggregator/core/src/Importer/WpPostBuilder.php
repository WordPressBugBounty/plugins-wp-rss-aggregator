<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Importer;

use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\IrPost;
use RebelCode\Aggregator\Core\ImportedPost;
use Exception;

class WpPostBuilder {

	/**
	 * Creates a WordPress post from an IR post.
	 *
	 * @result Result<IrPost> The IR post with an updated {@link IrPost::$postId}.
	 */
	public static function buildWpPost( IrPost $irPost ): Result {
		$irPost = self::checkRevisions( $irPost );

		$postData = self::buildPostData( $irPost );
		$metaData = self::buildMetaData( $irPost );

		$guid = $irPost->guid;
		if ( ! empty( $guid ) && empty( $postData['ID'] ) ) {
			$posts = get_posts(
				array(
					'meta_query' => array(
						array(
							'key' => ImportedPost::GUID,
							'value' => $guid,
						),
					),
				)
			);

			if ( isset( $posts[0] ) && $posts[0] instanceof \WP_Post ) {
				$postData['ID'] = $posts[0]->ID;
			}
		}

		$postId = self::savePostWithImportedHtml( $postData, true );

		if ( is_wp_error( $postId ) ) {
			return Result::Err( new Exception( $postId->get_error_message() ) );
		}

		self::updatePostMeta( $postId, $metaData, array( ImportedPost::SOURCE ) );
		self::setPostTerms( $irPost, $postId );
		self::setPostThumbnail( $irPost, $postId );
		self::downloadImages( $irPost, $postId );

		$newPost = clone $irPost;
		$newPost->postId = $postId;

		return Result::Ok( $newPost );
	}

	/**
	 * Permits extra HTML tags in imported post content.
	 *
	 * WordPress runs imported content through KSES on save (cron imports have no
	 * user with the `unfiltered_html` capability). This filter is only attached
	 * while an imported post is being saved, so it does not widen the allowed HTML
	 * for normal site editing.
	 *
	 * @since 5.2.1
	 *
	 * @param array<string,array<string,bool>> $tags    Allowed tags for the context.
	 * @param string                           $context The KSES context.
	 *
	 * @return array<string,array<string,bool>> The allowed tags.
	 */
	public static function allowImportedHtml( $tags, $context, ?array $allowedImportHtmlTags = null ) {
		if ( 'post' !== $context || ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( $allowedImportHtmlTags ?? self::allowedImportHtmlTags() as $tag => $attrs ) {
			if ( ! is_string( $tag ) || ! is_array( $attrs ) ) {
				continue;
			}

			$tags[ $tag ] = array_merge( $tags[ $tag ] ?? array(), $attrs );
		}

		return $tags;
	}

	/**
	 * Gets extra HTML tags allowed only while importer content is saved.
	 *
	 * Use the `wpra.importer.allowedHtmlTags` filter to opt in to extra tags:
	 *
	 * add_filter( 'wpra.importer.allowedHtmlTags', function ( $tags ) {
	 *     $tags['iframe'] = array(
	 *         'src' => true,
	 *         'width' => true,
	 *         'height' => true,
	 *         'allowfullscreen' => true,
	 *     );
	 *
	 *     return $tags;
	 * } );
	 *
	 * Allowed tags are also preserved while SimplePie reads feed content.
	 *
	 * @since 5.2.1
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowedImportHtmlTags(): array {
		$tags = array();

		$tags = apply_filters( 'wpra.importer.allowedHtmlTags', $tags );

		return is_array( $tags ) ? $tags : array();
	}

	/**
	 * Saves imported post content while applying import-only KSES rules.
	 *
	 * @since 5.2.1
	 *
	 * @param array<string,mixed> $postData
	 * @return int|\WP_Error
	 */
	protected static function savePostWithImportedHtml( array $postData, bool $wpError = false ) {
		$allowedImportHtmlTags = self::allowedImportHtmlTags();

		if ( empty( $allowedImportHtmlTags ) ) {
			return self::savePost( $postData, $wpError );
		}

		$filter = static function ( $tags, $context ) use ( $allowedImportHtmlTags ) {
			return self::allowImportedHtml( $tags, $context, $allowedImportHtmlTags );
		};

		add_filter( 'wp_kses_allowed_html', $filter, 10, 2 );

		try {
			return self::savePost( $postData, $wpError );
		} finally {
			remove_filter( 'wp_kses_allowed_html', $filter, 10 );
		}
	}

	/**
	 * Saves post data without changing KSES rules.
	 *
	 * @since 5.2.1
	 *
	 * @param array<string,mixed> $postData
	 * @return int|\WP_Error
	 */
	protected static function savePost( array $postData, bool $wpError = false ) {
		if ( isset( $postData['ID'] ) ) {
			return wp_update_post( $postData, $wpError );
		}

		return wp_insert_post( $postData, $wpError );
	}

	/** @return array<string,mixed> */
	protected static function buildPostData( IrPost $irPost ): array {
		$published = Time::normalizeDatetime( $irPost->datePublished );
		$modified  = Time::normalizeDatetime( $irPost->dateModified );

		$postData = array(
			'post_type' => $irPost->type,
			'post_status' => $irPost->status,
			'post_format' => $irPost->format,
			'post_name' => $irPost->slug,
			'post_title' => $irPost->title,
			'post_excerpt' => $irPost->excerpt,
			'post_content' => $irPost->content,
			'post_date'        => $published['local'] ?? null,
			'post_date_gmt'    => $published['gmt'] ?? null,
			'post_modified'    => $modified['local'] ?? null,
			'post_modified_gmt' => $modified['gmt'] ?? null,
			'comments_open' => $irPost->commentsOpen,
			'post_password' => $irPost->password,
		);

		// If we have a local ID, we're updating an existing post.
		if ( $irPost->postId !== null ) {
			$postData['ID'] = $irPost->postId;
		}

		if ( $irPost->parentId > 0 ) {
			$postData['post_parent'] = $irPost->parentId;
		}

		// Add the post author, creating it if necessary.
		if ( $irPost->author !== null ) {
			$result = $irPost->author->getOrCreate();

			if ( $result->isOk() ) {
				$authorId = $result->get();

				if ( $authorId > 0 ) {
					$postData['post_author'] = $authorId;
				}
			} else {
				Logger::warning( $result->error() );
			}
		}

		return $postData;
	}

	/** @return array<string,mixed> */
	protected static function buildMetaData( IrPost $irPost ): array {
		$meta = $irPost->meta;

		$meta[ ImportedPost::GUID ] = $irPost->guid;
		$meta[ ImportedPost::URL ] = $irPost->url;
		$meta[ ImportedPost::IMPORT_DATE ] = date( DATE_ATOM );

		if ( $irPost->ftImage && $irPost->ftImage->url ) {
			$meta[ ImportedPost::FT_IMAGE_URL ] = $irPost->ftImage->url;
		}

		$meta[ ImportedPost::SOURCE ] = array();
		foreach ( $irPost->sources as $source ) {
			$meta[ ImportedPost::SOURCE ][] = $source;
		}

		return $meta;
	}

	protected static function setPostTerms( IrPost $irPost, int $postId ): void {
		foreach ( $irPost->terms as $taxonomy => $terms ) {
			$termIds = array();

			foreach ( $terms as $term ) {
				$result = $term->getOrCreate();

				if ( $result->isOk() ) {
					$term = $result->get();
					$termIds[] = $term->term_id;
				} else {
					Logger::warning( $result->error() );
				}
			}

			wp_set_post_terms( $postId, $termIds, $taxonomy );
		}
	}

	protected static function setPostThumbnail( IrPost $irPost, int $postId ): void {
		if ( $irPost->ftImage !== null ) {
			$result = $irPost->ftImage->download( $postId );

			if ( $result->isOk() ) {
				$imgId = $result->get();
				set_post_thumbnail( $postId, $imgId );
			} else {
				Logger::warning( $result->error() );
			}
		}
	}

	protected static function downloadImages( IrPost $irPost, int $postId ): void {
		$search = array();
		$replace = array();

		foreach ( $irPost->images as $image ) {
			$result = $image->download( $postId );

			if ( $result->isErr() ) {
				Logger::warning(
					sprintf(
						__( 'Failed to download image for post: %s', 'wp-rss-aggregator' ),
						$image->url,
					)
				);
				continue;
			}

			$imgId = $result->get();
			$localUrl = wp_get_attachment_url( $imgId );

			if ( $localUrl === false ) {
				Logger::warning(
					sprintf(
						__( 'Could not get URL of downloaded image with ID %d', 'wp-rss-aggregator' ),
						$imgId,
					)
				);
				continue;
			}

			$search[] = $image->url;
			$replace[] = $localUrl;
		}

		if ( count( $search ) > 0 ) {
			$newContent = str_replace( $search, $replace, $irPost->content );

			self::savePostWithImportedHtml(
				array(
					'ID' => $postId,
					'post_content' => $newContent,
				)
			);
		}
	}

	/**
	 * Updates a post's meta data, ensuring that arrays are saved as separate
	 * values, and not as serialized array string. If we wanted to serialize
	 * values, we could do it ourselves! Thanks WordPress -_-
	 *
	 * @param array<string,mixed> $meta The meta data associative array.
	 * @param array<string>       $uniques The meta keys that should be unique.
	 */
	protected static function updatePostMeta( int $postId, array $meta, array $uniques = array() ): void {
		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) ) {
				$unique = in_array( $key, $uniques, true );

				foreach ( $value as $subVal ) {
					add_post_meta( $postId, $key, $subVal, $unique );
				}
			} else {
				update_post_meta( $postId, $key, $value );
			}
		}

		get_post_meta( $postId, 'some_key', true );
	}

	/**
	 * Creates a revision for the post if the original title and content are
	 * detected in the post meta.
	 */
	protected static function checkRevisions( IrPost $post ): IrPost {
		$origTitle = $post->getSingleMeta( ImportedPost::ORIG_TITLE, null );
		$origContent = $post->getSingleMeta( ImportedPost::ORIG_CONTENT, null );

		unset( $post->meta[ ImportedPost::ORIG_TITLE ] );
		unset( $post->meta[ ImportedPost::ORIG_CONTENT ] );

		if ( $origTitle === null && $origContent === null ) {
			return $post;
		}

		$origPost = clone $post;
		$origPost->title = $origTitle;
		$origPost->content = $origContent;

		$result = self::buildWpPost( $origPost );
		if ( $result->isErr() ) {
			Logger::warning( 'Could not prepare IR post for WordAi revision. Cause: ' . $result->error()->getMessage() );
			return $post;
		}

		$origPost = $result->get();
		wp_save_post_revision( $origPost->postId );

		$newPost = clone $post;
		$newPost->postId = $origPost->postId;

		return $newPost;
	}
}
