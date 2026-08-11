<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

/**
 * Service class responsible for providing lists of plugin-specific data
 * that may need to be cleaned up during operations like reset or uninstall.
 *
 * This service is intended to be registered with the plugin's DI container.
 * It has no constructor dependencies itself to allow for easier instantiation
 * as a fallback in contexts where DI might not be available (e.g., uninstaller).
 */
class DataCleanup {

	/**
	 * Retrieves a list of database table suffixes used by the plugin.
	 *
	 * These are suffixes that will be appended to the WordPress table prefix
	 * and the plugin's specific database prefix (e.g., 'wp_agg_').
	 *
	 * @return string[] A list of table suffixes.
	 */
	public function getPluginTableSuffixes(): array {
		return array(
			'sources',
			'reject_list',
			'displays',
			'progress',
			'ir_posts',
			'folders',
			'folder_sources',
		);
	}

	/**
	 * Retrieves a list of WordPress option names used by the plugin.
	 *
	 * @return string[] A list of option names.
	 */
	public function getPluginOptionNames(): array {
		return array(
			'wpra_version',
			'wpra_settings',
			'wpra_state',
			'wpra_license',
			'wpra_did_v4_migration',
		);
	}

	/**
	 * Retrieves a list of post meta keys used by the plugin.
	 *
	 * @since 5.4.0 Includes explicit imported-post metadata keys for reset and uninstall cleanup.
	 *
	 * @return string[] A list of post meta keys.
	 */
	public function getPluginPostMetaKeys(): array {
		return array(
			'wpra_v5_id',
			'_wpra_guid',
			'_wpra_source',
			'_wpra_source_name',
			'_wpra_source_url',
			'_wpra_url',
			'_wpra_import_date',
			'_wpra_ft_image_url',
			'_wpra_is_yt',
			'_wpra_yt_views',
			'_wpra_yt_video_id',
			'_wpra_yt_embed_url',
			'_wpra_audio_url',
			'_wpra_enclosure_url',
			'_wpra_author_name',
			'_wpra_author_email',
			'_wpra_author_url',
			'_wpra_orig_title',
			'_wpra_orig_content',
			'_wpra_source_blog_id',
			'_wpra_protected',
		);
	}

	/**
	 * Retrieves a list of cron hooks owned by the plugin.
	 *
	 * @since 5.4.0
	 *
	 * @return string[] A list of cron hooks.
	 */
	public function getPluginCronHooks(): array {
		return array(
			'wpra.update',
			'wpra.licensing.update',
		);
	}
}
