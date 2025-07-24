<?php

namespace RebelCode\Aggregator\Core;

use WP_CLI;
use RebelCode\Aggregator\Core\V4\V4Migrator;
use RebelCode\Aggregator\Core\Logger\CliLogger;
use RebelCode\Aggregator\Core\DataCleanup;
use RebelCode\Aggregator\Core\Cli\WpCliIo;
use RebelCode\Aggregator\Core\Cli\Commands\SourceCommand;
use RebelCode\Aggregator\Core\Cli\Commands\SettingsCommand;
use RebelCode\Aggregator\Core\Cli\Commands\RootCommand;
use RebelCode\Aggregator\Core\Cli\Commands\RejectListCommand;
use RebelCode\Aggregator\Core\Cli\Commands\Migration\V4MigrationCommand;
use RebelCode\Aggregator\Core\Cli\Commands\Migration\ResetV5Command;
use RebelCode\Aggregator\Core\Cli\Commands\ImportCommand;
use RebelCode\Aggregator\Core\Cli\Commands\FetchCommand;
use RebelCode\Aggregator\Core\Cli\Commands\FeedCommand;
use RebelCode\Aggregator\Core\Cli\Commands\DisplayCommand;
use Psr\Log\LogLevel;

wpra()->addModule(
	'cli',
	array( 'settings', 'importer', 'renderer', 'db', 'v4Migration', 'cleanup' ),
	function ( Settings $settings, Importer $importer, Renderer $renderer, Database $db, V4Migrator $v4Migrator, DataCleanup $dataCleanup ) {
		if ( ! defined( 'WP_CLI' ) || ! class_exists( WP_CLI::class ) ) {
			return null;
		}

		$io = new WpCliIo();
		$logger = new CliLogger( $io );

		Logger::push( $logger );
		Logger::setLevel( LogLevel::INFO );

		$commands = apply_filters(
			'wpra.cli.commands',
			array(
				'root' => new RootCommand( $io ),
				'source' => new SourceCommand( $io, $importer->sources ),
				'rejectlist' => new RejectListCommand( $io, $importer->rejectList ),
				'fetch' => new FetchCommand( $io, $importer ),
				'import' => new ImportCommand( $io, $importer ),
				'feed' => new FeedCommand( $io, $importer ),
				'settings' => new SettingsCommand( $io, $settings ),
				'display' => new DisplayCommand( $io, $renderer->displays, $importer->sources ),
				'v4_migration' => new V4MigrationCommand( $v4Migrator ),
				'reset-v5' => new ResetV5Command( $io, $dataCleanup ),
			)
		);

		foreach ( $commands as $name => $command ) {
			WP_CLI::add_command( "rss $name", $command );
		}

		return $commands;
	}
);
