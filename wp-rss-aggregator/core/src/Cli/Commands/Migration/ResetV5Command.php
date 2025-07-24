<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands\Migration;

use WP_CLI;
use RebelCode\Aggregator\Core\DataCleanup;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\BaseCommand;

/**
 * Resets WP RSS Aggregator v5 data.
 *
 * Removes v5 tables, options, and associated post meta.
 * The plugin should remain active, and tables/options will be recreated
 * on the next load.
 */
class ResetV5Command extends BaseCommand {

	/**
	 * The WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * The database prefix used by WP RSS Aggregator.
	 *
	 * @var string
	 */
	protected $dbPrefix;

	/**
	 * The data cleanup service instance.
	 *
	 * @var DataCleanupService
	 */
	protected DataCleanup $cleanupService;

	public function __construct( CliIo $io, DataCleanup $cleanupService ) {
		parent::__construct( $io );
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->cleanupService = $cleanupService;
	}

	/**
	 * Resets all WP RSS Aggregator v5 data, including tables, options, and post meta.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rss reset-v5
	 *     wp rss reset-v5 --yes
	 *
	 * @param list<string>         $args Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'Are you sure you want to reset all WP RSS Aggregator v5 data? This is a destructive operation.' );
		}

		// Fetch DB prefix here to ensure plugin services are loaded
		$this->dbPrefix = wpra()->get( 'db' )->prefix;

		WP_CLI::log( 'Starting WP RSS Aggregator v5 data reset...' );

		$this->deleteTables();
		$this->deleteOptions();
		$this->cleanPostMeta();

		WP_CLI::success( 'WP RSS Aggregator v5 data reset complete. Tables and options will be regenerated on the next plugin load.' );
	}

	protected function deleteTables(): void {
		WP_CLI::log( 'Deleting v5 database tables...' );
		$tableSuffixes = $this->cleanupService->getPluginTableSuffixes();

		if ( empty( $tableSuffixes ) ) {
			WP_CLI::log( 'No table suffixes defined by DataCleanupService. Skipping table deletion.' );
			return;
		}

		$fullDbPrefix = $this->wpdb->prefix . $this->dbPrefix;

		$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		WP_CLI::log( 'Temporarily disabled foreign key checks.' );

		foreach ( $tableSuffixes as $tableSuffix ) {
			$tableName = $fullDbPrefix . $tableSuffix;
			$this->wpdb->query( "DROP TABLE IF EXISTS `{$tableName}`" );
			WP_CLI::log( sprintf( 'Dropped table: %s (if it existed)', $tableName ) );
		}

		$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		WP_CLI::log( 'Re-enabled foreign key checks.' );
		WP_CLI::log( 'Database tables deletion process complete.' );
	}

	protected function deleteOptions(): void {
		WP_CLI::log( 'Deleting v5 options...' );
		$optionNames = $this->cleanupService->getPluginOptionNames();

		if ( empty( $optionNames ) ) {
			WP_CLI::log( 'No option names defined by DataCleanupService. Skipping option deletion.' );
			return;
		}

		foreach ( $optionNames as $optionName ) {
			delete_option( $optionName );
			WP_CLI::log( sprintf( 'Deleted option: %s (if it existed)', $optionName ) );
		}
		WP_CLI::log( 'Options deletion process complete.' );
	}

	protected function cleanPostMeta(): void {
		WP_CLI::log( 'Cleaning v5 post meta...' );
		$metaKeys = $this->cleanupService->getPluginPostMetaKeys();

		if ( empty( $metaKeys ) ) {
			WP_CLI::log( 'No post meta keys defined by DataCleanupService. Skipping post meta cleanup.' );
			return;
		}

		foreach ( $metaKeys as $metaKey ) {
			$deletedCount = $this->wpdb->delete( $this->wpdb->postmeta, array( 'meta_key' => $metaKey ), array( '%s' ) );
			if ( $deletedCount > 0 ) {
				WP_CLI::log( sprintf( "Cleaned %d rows of post meta with key '%s'.", (int) $deletedCount, $metaKey ) );
			} else {
				WP_CLI::log( sprintf( "No post meta found with key '%s'.", $metaKey ) );
			}
		}
		WP_CLI::log( 'Post meta cleanup process complete.' );
	}
}
