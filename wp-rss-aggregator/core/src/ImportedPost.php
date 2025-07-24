<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

/** The keys for the metadata of imported posts used by WPRA  */
abstract class ImportedPost {

	public const GUID = '_wpra_guid';
	public const SOURCE = '_wpra_source';
	public const SOURCE_NAME = '_wpra_source_name';
	public const SOURCE_URL = '_wpra_source_url';
	public const URL = '_wpra_url';
	public const IMPORT_DATE = '_wpra_import_date';
	public const FT_IMAGE_URL = '_wpra_ft_image_url';
	public const IS_YT = '_wpra_is_yt';
	public const YT_VIEWS = '_wpra_yt_views';
	public const YT_VIDEO_ID = '_wpra_yt_video_id';
	public const YT_EMBED_URL = '_wpra_yt_embed_url';
	public const AUDIO_URL = '_wpra_audio_url';
	public const ENCLOSURE_URL = '_wpra_enclosure_url';
	public const AUTHOR_NAME = '_wpra_author_name';
	public const AUTHOR_EMAIL = '_wpra_author_email';
	public const AUTHOR_URL = '_wpra_author_url';

	// Original post data, used to create revisions
	public const ORIG_TITLE = '_wpra_orig_title';
	public const ORIG_CONTENT = '_wpra_orig_content';

	// For multisite. Stores the blog ID of the source that imported the post
	public const SOURCE_BLOG_ID = '_wpra_source_blog_id';

	/**
	 * Checks if a post ID refers to a post imported by WPRA.
	 *
	 * @param int $postId The post ID.
	 * @return bool True if the post is imported, false if not.
	 */
	public static function isImported( int $postId ): bool {
		$sources = get_post_meta( $postId, self::SOURCE, false );
		$hasSources = count( $sources ) > 0;
		return $hasSources;
	}
}
