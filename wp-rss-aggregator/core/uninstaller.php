<?php

namespace RebelCode\Aggregator\Core;

use wpdb;

use RebelCode\Aggregator\Core\DataCleanup;

if ( ! class_exists( 'RebelCode\Aggregator\Core\Uninstaller' ) ) {
	class Uninstaller {

		protected DataCleanup $cleanupService;

		public function __construct() {
			$this->cleanupService = new DataCleanup();
		}

		public function shouldUninstall(): bool {
			$settings = get_option( 'wpra_settings', array() );
			$doUninstall = (bool) ( $settings['doUninstall'] ?? false );
			return $doUninstall;
		}

		public function uninstall() {
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
				$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $metaKey ), array( '%s' ) );
			}
		}

		public function deleteTables(): void {
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
}

return new Uninstaller();
