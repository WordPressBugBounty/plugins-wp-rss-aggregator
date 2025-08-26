<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use RebelCode\Aggregator\Core\Utils\WpUtils;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\DataCleanup;

class V4Migrator {

	public V4SettingsMigrator $settings;
	public V4SourceMigrator $sources;
	public V4BlacklistMigrator $blacklist;
	public V4TemplatesMigrator $templates;
	public V4ItemMigrator $items;
	protected DataCleanup $cleanupService;

	public function __construct(
		V4SettingsMigrator $settings,
		V4SourceMigrator $sources,
		V4BlacklistMigrator $blacklist,
		V4TemplatesMigrator $templates,
		V4ItemMigrator $items,
		DataCleanup $cleanupService
	) {
		$this->settings = $settings;
		$this->sources = $sources;
		$this->blacklist = $blacklist;
		$this->templates = $templates;
		$this->items = $items;
		$this->cleanupService = $cleanupService;
	}

	public function loadObjects(): void {
		try {
			if ( ! post_type_exists( 'wprss_feed' ) ) {
				register_post_type(
					'wprss_feed',
					array(
						'public' => true,
						'label' => 'Feed sources',
						'supports' => array( 'title', 'custom-fields' ),
					)
				);
			}
			if ( ! post_type_exists( 'wprss_blacklist' ) ) {
				register_post_type(
					'wprss_blacklist',
					array(
						'public' => true,
						'label' => 'Feed Blacklist',
						'supports' => array( 'title', 'custom-fields' ),
					)
				);
			}
			if ( ! post_type_exists( 'wprss_feed_template' ) ) {
				register_post_type(
					'wprss_feed_template',
					array(
						'public' => true,
						'label' => 'Feed Template',
						'supports' => array( 'title', 'custom-fields' ),
					)
				);
			}

			if ( ! taxonomy_exists( 'wprss_category' ) ) {
				register_taxonomy(
					'wprss_category',
					array( 'wprss_feed', 'wprss_feed_item' ),
				);
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error in V4Migrator::loadObjects during CPT/taxonomy registration: %s', $e->getMessage() ) );
			// Depending on severity, this might be a critical error.
		}
	}

	/** Deactivates the v4 addons. Returns which addons where deactivated. */
	public function deactivateAddons(): array {
		$deactivatedAddons = array();
		try {
			$constants = array(
				'WPRSS_TEMPLATES',
				'WPRSS_C_PATH',
				'WPRSS_ET_PATH',
				'WPRSS_KF_PATH',
				'WPRSS_FTP_PATH',
				'WPRSS_FTR_PATH',
				'WPRSS_WORDAI',
				'WPRSS_SPC_ADDON',
				'WPRA_SC',
			);

			$addons = array();
			foreach ( $constants as $path ) {
				if ( defined( $path ) ) {
					$addons[] = plugin_basename( constant( $path ) );
				}
			}
			deactivate_plugins( $addons, true );
			$deactivatedAddons = $addons;
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4Migrator::deactivateAddons: %s', $e->getMessage() ) );
			// $deactivatedAddons will remain empty or partially filled if error occurred mid-process
		}
		return $deactivatedAddons;
	}

	/**
	 * Checks if any of the specified addon plugins are active.
	 *
	 * @return Result True if at least one addon plugin is active, false otherwise.
	 */
	public function isPluginsActivated(): Result {
		try {
			$constants = wp_parse_list(
				array(
					'WPRSS_TEMPLATES',
					'WPRSS_C_PATH',
					'WPRSS_ET_PATH',
					'WPRSS_KF_PATH',
					'WPRSS_FTP_PATH',
					'WPRSS_FTR_PATH',
					'WPRSS_WORDAI',
					'WPRSS_SPC_ADDON',
					'WPRA_SC',
				)
			);

			foreach ( $constants as $path ) {
				if ( defined( $path ) ) {
					$plugin = plugin_basename( constant( $path ) );

					if ( is_plugin_active( $plugin ) ) {
						return Result::Ok( true );
					}
				}
			}

			return Result::Ok( false );
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4Migrator::isPluginsActivated: %s', $e->getMessage() ) );
			return Result::Err( $e ); // Return an error Result
		}
	}

	/**
	 * Checks if the WP RSS Aggregator Premium plugin is installed.
	 *
	 * @since 5.0.1
	 *
	 * @return Result Returns Result::Ok(true) if installed, Result::Ok(false) if not, or Result::Err on error.
	 */
	public function isPremiumPluginInstalled(): Result {
		try {
			$plugins = get_plugins();
			$isInstalled = isset( $plugins['wp-rss-aggregator-premium/wp-rss-aggregator-premium.php'] );

			return Result::Ok( $isInstalled );
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during %s::isPremiumPluginInstalled: %s', __CLASS__, $e->getMessage() ) );
			return Result::Err( $e );
		}
	}

	/**
	 * Activates the WP RSS Aggregator Premium plugin.
	 *
	 * Attempts to activate the premium plugin programmatically and returns
	 * a Result object indicating success or failure.
	 *
	 * @since 5.0.1
	 *
	 * @return Result Returns a Result::Ok(true) on success or Result::Err(error_message) on failure.
	 */
	public function activatePremiumPlugin(): Result {
		try {
			$result = activate_plugin( 'wp-rss-aggregator-premium/wp-rss-aggregator-premium.php' );
			if ( is_wp_error( $result ) ) {
				return Result::Err( $result->get_error_message() );
			}
			return Result::Ok( true );
		} catch ( \Exception $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	public function uninstall(): void {
		try {
			delete_option( 'wprss_settings_general' );
			delete_option( 'wprss_settings_ftp' );
			delete_option( 'wprss_settings_kf' );
			delete_option( 'wprss_settings_wordai' );
			delete_option( 'wprss_spc_settings' );
			delete_option( 'wprss_prev_update_page_version' );
			delete_option( 'wprss_admin_notices' );
			delete_option( 'wprss_did_guide_sign_up' );
			delete_option( 'wprss_v5_coming_notice_dismissed' );
			delete_option( 'wprss_c_check_existing_feeds' );
			delete_option( 'wprss_db_version' );
			delete_option( 'wprss_c_db_version' );
			delete_option( 'wprss_kf_db_version' );
			delete_option( 'wprss_ftp_db_version' );

			$templates = WpUtils::batchQueryPosts(
				100,
				array(
					'post_type' => 'wprss_feed_template',
					'fields' => 'ids',
				)
			);
			foreach ( $templates as $templateId ) {
				wp_delete_post( $templateId, true );
			}

			$sources = WpUtils::batchQueryPosts(
				100,
				array(
					'post_type' => 'wprss_feed_blacklist',
					'fields' => 'ids',
				)
			);
			foreach ( $sources as $itemId ) {
				wp_delete_post( $itemId, true );
			}

			$sources = WpUtils::batchQueryPosts(
				100,
				array(
					'post_type' => 'wprss_feed',
					'fields' => 'ids',
				)
			);
			foreach ( $sources as $sourceId ) {
				wp_delete_post( $sourceId, true );
			}

			$categories = WpUtils::batchQueryTerms(
				100,
				array(
					'taxonomy' => 'wprss_category',
					'hide_empty' => false,
					'fields' => 'ids',
				)
			);
			foreach ( $categories as $categoryId ) {
				wp_delete_term( $categoryId, 'wprss_category' );
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4Migrator::uninstall: %s', $e->getMessage() ) );
			// This is a void method, so no return value to change. Error is logged.
		}
	}

	public function reset(): void {
		try {
			$this->deleteOptions();
			$this->cleanPostMeta();
			$this->deleteTables();
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4Migrator::uninstall: %s', $e->getMessage() ) );
			// This is a void method, so no return value to change. Error is logged.
		}
	}

	public function rollback(): void {
		try {
			$this->reset();
			update_option( 'wprss_enable_v5', '0' );
			if ( ! get_option( 'wprss_prev_update_page_version', false ) ) {
				update_option( 'wprss_prev_update_page_version', '4.23.13' );
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4Migrator::rollback: %s', $e->getMessage() ) );
		}
	}

	private function deleteOptions(): void {
		$optionNames = $this->cleanupService->getPluginOptionNames();
		foreach ( $optionNames as $optionName ) {
			delete_option( $optionName );
		}
	}

	private function cleanPostMeta(): void {
		/** @var wpdb $wpdb */
		global $wpdb;

		$metaKeys = $this->cleanupService->getPluginPostMetaKeys();
		foreach ( $metaKeys as $metaKey ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $metaKey ), array( '%s' ) );
		}
	}

	private function deleteTables(): void {
		/** @var wpdb $wpdb */
		global $wpdb;
		$pluginDbPrefix = apply_filters( 'wpra.db.prefix', 'agg_' );
		$fullTablePrefix = $wpdb->prefix . $pluginDbPrefix;

		$tableSuffixes = $this->cleanupService->getPluginTableSuffixes();

		if ( empty( $tableSuffixes ) ) {
			return;
		}

		try {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tableSuffixes as $suffix ) {
				$tableName = $fullTablePrefix . $suffix;
				$wpdb->query( "DROP TABLE IF EXISTS `{$tableName}`" );
			}
		} finally {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}
}
