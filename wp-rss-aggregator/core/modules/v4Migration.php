<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\V4\V4TemplatesMigrator;
use RebelCode\Aggregator\Core\V4\V4SourceMigrator;
use RebelCode\Aggregator\Core\V4\V4SettingsMigrator;
use RebelCode\Aggregator\Core\V4\V4Migrator;
use RebelCode\Aggregator\Core\V4\V4ItemMigrator;
use RebelCode\Aggregator\Core\V4\V4BlacklistMigrator;
use RebelCode\Aggregator\Core\DataCleanup;

wpra()->addModule(
	'v4Migration',
	array( 'settings', 'importer', 'renderer',  'cleanup' ),
	function ( Settings $settings, Importer $importer, Renderer $renderer, DataCleanup $dataCleanup ) {
		$v4CoreSettings = get_option( 'wprss_settings_general', array() );
		$v4FtpSettings = get_option( 'wprss_settings_ftp', array() );
		$v4KfSettings = get_option( 'wprss_settings_kf', array() );
		$v4WaiSettings = get_option( 'wprss_settings_wordai', array() );
		$v4ScSettings = get_option( 'wprss_spc_settings', array() );

		$settingsMigrator = new V4SettingsMigrator(
			$settings,
			$v4CoreSettings,
			$v4FtpSettings,
			$v4KfSettings,
			$v4WaiSettings,
			$v4ScSettings
		);
		$srcMigrator = new V4SourceMigrator( $importer->sources, $v4CoreSettings, );
		$bListMigrator = new V4BlacklistMigrator( $importer->rejectList );
		$templMigrator = new V4TemplatesMigrator( $renderer->displays );
		$itemMigrator = new V4ItemMigrator();

		$migrator = new V4Migrator(
			$settingsMigrator,
			$srcMigrator,
			$bListMigrator,
			$templMigrator,
			$itemMigrator,
			$dataCleanup
		);

		add_filter(
			'wpra.rpc.v4.*',
			function ( $callback ) use ( $migrator ) {
				$migrator->loadObjects();
				return $callback;
			}
		);

		add_filter( 'wpra.rpc.v4.rollback', fn () => fn () => $migrator->rollback() );
		add_filter( 'wpra.rpc.v4.reset', fn () => fn () => $migrator->reset() );

		add_filter( 'wpra.rpc.v4.numSources', fn () => fn () => $srcMigrator->getCount() );
		add_filter( 'wpra.rpc.v4.numBlacklist', fn () => fn () => $bListMigrator->getCount() );
		add_filter( 'wpra.rpc.v4.numTemplates', fn () => fn () => $templMigrator->getCount() );
		add_filter( 'wpra.rpc.v4.numItems', fn () => fn () => $itemMigrator->getCount() );

		add_filter( 'wpra.rpc.v4.migrateSettings', fn () => fn ( $dryRun ) => $settingsMigrator->migrate( $dryRun ) );
		add_filter( 'wpra.rpc.v4.migrateSources', fn () => fn ( $dryRun ) => $srcMigrator->migrateAll( $dryRun ) );
		add_filter( 'wpra.rpc.v4.migrateBlacklist', fn () => fn ( $dryRun ) => $bListMigrator->migrateAll( $dryRun ) );
		add_filter( 'wpra.rpc.v4.migrateTemplates', fn () => fn ( $dryRun ) => $templMigrator->migrateAll( $dryRun ) );
		add_filter( 'wpra.rpc.v4.migrateItems', fn () => fn ( $dryRun ) => $itemMigrator->migrateAll( $dryRun ) );

		add_filter( 'wpra.rpc.v4.isPluginsActivated', fn () => fn () => $migrator->isPluginsActivated() );
		add_filter( 'wpra.rpc.v4.deactivateAddons', fn () => fn () => $migrator->deactivateAddons() );
		add_filter( 'wpra.rpc.v4.uninstall', fn () => fn () => $migrator->uninstall() );

		return $migrator;
	}
);
