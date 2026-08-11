<?php

namespace RebelCode\Aggregator\Core;

use wpdb;

use RebelCode\Aggregator\Core\DataCleanup;

if ( ! class_exists( 'RebelCode\Aggregator\Core\Uninstaller' ) ) {
	class Uninstaller {

		private const POST_META_DELETE_BATCH_SIZE = 1000;

		protected DataCleanup $cleanupService;

		public function __construct() {
			$this->cleanupService = new DataCleanup();
		}

		public function shouldUninstall(): bool {
			$settings = get_option( 'wpra_settings', array() );
			$doUninstall = (bool) ( $settings['doUninstall'] ?? false );
			return $doUninstall;
		}

		/**
		 * Removes WPRA-owned cron events from WordPress cron.
		 *
		 * @since 5.4.0
		 */
		public function clearCronEvents(): void {
			$cronHooks = $this->cleanupService->getPluginCronHooks();
			foreach ( $cronHooks as $cronHook ) {
				wp_clear_scheduled_hook( $cronHook );
			}
		}

		/**
		 * Deletes Aggregator-owned persisted data for opted-in uninstall cleanup.
		 *
		 * @since 5.4.0
		 */
		public function uninstall(): void {
			$this->deleteOptions();
			$this->cleanPostMeta();
			$this->deleteTables();

			do_action( 'wpra.uninstall' );
		}

		public function deleteOptions(): void {
			$optionNames = $this->cleanupService->getPluginOptionNames();
			foreach ( $optionNames as $optionName ) {
				delete_option( $optionName );
			}
		}

		public function cleanPostMeta(): void {
			/** @var wpdb $wpdb */
			global $wpdb;

			$metaKeys = $this->cleanupService->getPluginPostMetaKeys();
			foreach ( $metaKeys as $metaKey ) {
				do {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name comes from wpdb.
					$metaIds = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d",
							$metaKey,
							self::POST_META_DELETE_BATCH_SIZE
						)
					);

					if ( empty( $metaIds ) ) {
						break;
					}

					$metaIds = array_map( 'absint', $metaIds );
					$placeholders = implode( ', ', array_fill( 0, count( $metaIds ), '%d' ) );

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated from the selected ID count.
					$deleted = $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)",
							$metaIds
						)
					);
				} while ( $deleted > 0 );
			}
		}

		public function deleteTables(): void {
			/** @var wpdb $wpdb */
			global $wpdb;
			$pluginDbPrefix = apply_filters( 'wpra.db.prefix', 'agg_' );
			$fullTablePrefix = $wpdb->prefix . sanitize_text_field( $pluginDbPrefix );

			$tableSuffixes = $this->cleanupService->getPluginTableSuffixes();

			if ( empty( $tableSuffixes ) ) {
				return;
			}

			try {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
				foreach ( $tableSuffixes as $suffix ) {
					$tableName = $fullTablePrefix . $suffix;
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tableName ) );
				}
			} finally {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			}
		}
	}
}

return new Uninstaller();
