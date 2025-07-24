<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\Colors;
use RebelCode\Aggregator\Core\Cli\CliTable;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Source\ScheduleFactory;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Time;
use WP_CLI;

/**
 * The CLI command with sub-commands for managing feed sources.
 */
class SourceCommand extends BaseCommand {

	protected SourcesStore $sources;

	public function __construct( CliIo $io, SourcesStore $sources ) {
		parent::__construct( $io );
		$this->sources = $sources;
	}

	/**
	 * Create a new feed source.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name of the feed source.
	 *
	 * <url>
	 * : The URL of the feed source.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source create "BBC News" "https://www.bbc.co.uk/news/uk/rss.xml"
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function new( array $args ): void {
		$name = $args[0];
		$url = $args[1];

		$result = $this->sources->save( new Source( null, $name, $url ) );

		if ( $result->isOk() ) {
			$src = $result->get();
			$this->io->success( "Source created! ID: {$src->id}" );
		} else {
			$this->io->error( $result->error() );
		}
	}

	/**
	 * Duplicates a feed source.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the source to duplicate.
	 *
	 * [--name=<name>]
	 * : Optional name for the new source. If omitted, '(Copy)' will be appended to the original name.
	 *
	 * [--rss-url=<url>]
	 * : Optional URL for the new source. If omitted, the new source will have the same URL as the original source.
	 *
	 * ## EXAMPLE
	 *
	 * wp rss source duplicate 34 --name="WordPress News"
	 * wp rss source duplicate 71 --url="https://new-url.com"
	 * wp rss source duplicate 14 --name="WordPress News" --url="https://new-url.com"
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function duplicate( array $args, array $opts ): void {
		$id = $this->parseIntArg( $args[0], '%s is not a valid source ID.' );
		$name = $opts['name'] ?? '';
		$url = $opts['rss-url'] ?? '';

		$result = $this->sources->duplicate( $id, $name, $url );

		if ( $result->isOk() ) {
			$src = $result->get();
			WP_CLI::success( "Created source \"{$src->name}\" with ID {$src->id}." );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Show information about a feed source.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The IDs of the source to show.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source show 9
	 * wp rss source show 16 19 35
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function show( array $args, array $opts ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid source ID.' );

		$result = $this->sources->getManyByIds( $ids );

		if ( $result->isOk() ) {
			foreach ( $result->get() as $src ) {
				$ids = array_diff( $ids, array( $src->id ) );
				$dotColor = $src->isActive ? Colors::GREEN : Colors::RED;

				$this->io->cprintf( "%($src->name)% [#$src->id] %(⏺)%\n", array( Colors::BOLD, $dotColor ) );
				$this->io->cprintf( "%(URL)%: %($src->url)%\n", array( Colors::BOLD, Colors::BLUE ) );

				if ( $src->isActive ) {
					$schedStr = $src->schedule ? $src->schedule->explain() : '';
					$dateStr = Time::toHumanFormat( $src->getNextUpdate(), 'Never' );

					$this->io->cprintf( "%(Schedule)%: $schedStr\n", array( Colors::BOLD ) );
					$this->io->cprintf( "%(Next update)%: $dateStr | ", array( Colors::BOLD ) );
				}

				$dateStr = Time::toHumanFormat( $src->lastUpdate, 'Never' );
				$this->io->cprintf( "%(Last updated)%: $dateStr\n", array( Colors::BOLD ) );

				$this->io->cprintf( "%(Settings)%:\n", array( Colors::BOLD ) );
				foreach ( $src->settings->toArray() as $key => $value ) {
					$json = json_encode( $value );
					$this->io->cprintf( " • %($key)%: $json\n", array( Colors::CYAN ) );
				}

				$this->io->println();
			}

			foreach ( $ids as $id ) {
				$this->io->warning( "Source with ID $id not found." );
			}
		} else {
			$this->printCliException( $result->error() );
		}
	}

	/**
	 * List the feed sources with a summary of their information.
	 *
	 * ## OPTIONS
	 *
	 * [<num>]
	 * : The number of sources to display per page. Default is 20.
	 *
	 * [--page=<num>]
	 * : The page number to display.
	 *
	 * [--columns=<columns>]
	 * : A comma-separated list of columns to display.
	 * - id
	 * - name
	 * - type
	 * - url
	 * - active
	 * - schedule
	 * - last_update
	 * - folders
	 *
	 * [--search=<search>]
	 * : Search for sources by name or URL.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source list
	 * wp rss source list --page=2
	 * wp rss source list --per-page=10
	 * wp rss source list --per-page=10 --page=5
	 * wp rss source list --columns=name,url,active
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function list( array $args, array $opts ): void {
		$num = (int) ( $args[0] ?? 20 );
		$page = (int) ( $opts['page'] ?? 1 );
		$filter = trim( $opts['search'] ?? '' );

		$result = $this->sources->getList( $filter, $num, $page );

		if ( $result->isOk() ) {
			$srcs = $result->get();

			$cols = $opts['cols'] ?? null;
			$cols = is_string( $cols ) ? explode( ',', $cols ) : null;

			self::sourceTable( $srcs, $cols )->render();
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Delete one or multiple feed sources.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The IDs of the sources to delete.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source delete 22
	 * wp rss source delete 39 50 88
	 *
	 * @param list<string> $args
	 */
	public function delete( array $args ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid source ID.' );

		$result = $this->sources->deleteManyByIds( $ids );

		if ( $result->isOk() ) {
			$num = $result->get();
			WP_CLI::success( sprintf( _n( 'Deleted %d source.', 'Deleted %d sources.', $num, 'wp-rss-aggregator' ), $num ) );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Configure the settings for one or more sources.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The IDs of the sources.
	 *
	 * <changes>...
	 * : The settings to change in the form: `setting=value`. JSON values or values that contain spaces must be quoted.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source set 14 trimContent=true
	 * wp rss source set 21 44 96 curatePosts=true minImageSize='{"width":100,"height":100"}'
	 *
	 * @param list<string> $args
	 */
	public function set( array $args ): void {
		$cIdx = Arrays::indexOf( $args, fn ( $arg ) => ! is_numeric( $arg ) );

		$ids = array_slice( $args, 0, $cIdx );
		$ids = $this->parseIntArgArray( $ids, '"%s" is not a valid source ID.' );

		$cList = array_slice( $args, $cIdx );
		$cMap = $this->parseKeyValueArgs( $cList );

		$res = $this->sources->configure( $ids, $cMap );

		$this->printNumUpdated( $res );
	}

	/**
	 * Rename a feed source.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID or query of the source(s) to rename.
	 *
	 * <name>
	 * : The new name.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source rename 11 "Medicine"
	 *
	 * @param list<string> $args
	 */
	public function rename( array $args ): void {
		$id = $this->parseIntArg( $args[0], '"%s" is not a valid source ID.' );
		$name = $args[1];

		$res = $this->sources->rename( $id, $name );

		$this->printNumUpdated( $res );
	}

	/**
	 * Activate a feed source to enable automatic updates.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The IDs of the source to activate.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source activate 14
	 * wp rss source activate 14 21 151
	 *
	 * @param list<string> $args
	 */
	public function activate( array $args ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid source ID.' );
		$res = $this->sources->activate( $ids );

		$this->printNumUpdated( $res );
	}

	/**
	 * Pause a feed source to disable automatic updates.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The IDs of the sources to pause.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source pause 14
	 * wp rss source pause 14 62 151
	 *
	 * @param list<string> $args
	 */
	public function pause( array $args ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid source ID.' );
		$res = $this->sources->pause( $ids );

		$this->printNumUpdated( $res );
	}

	/**
	 * Sets the schedule for a feed source.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The ID or search term for a source, or multiple IDs or search terms in square brackets separated by commas.
	 *
	 * [<schedule>]
	 * : The schedule string. Examples:
	 *
	 *     Minute-based schedules:
	 *     - 15m              Every 15 minutes
	 *     - 40m from 00:05   Every 40 minutes, starting from the 5th minute of the hour.
	 *
	 *     Hour-based schedules:
	 *     - 2h                      Every 2 hours
	 *     - 2h at 00:10             Every 2 hours, at the 10-minute mark.
	 *     - 2h from 05:00           Every 2 hours, starting at the 5 AM.
	 *     - 2h at 00:10 from 05:00  Every 2 hours, starting at the 5 AM, at the 10-minute mark.
	 *
	 *     Day-based schedules:
	 *     - 3d            Every 3 days
	 *     - 3d at 00:25   Every 3 days, at the 25-minute mark.
	 *
	 *     Week-based schedules:
	 *     - 2w                  Every 2 weeks
	 *     - 2w on Sun           Every 2 weeks, on Sunday.
	 *     - 2w on Sat           Every 2 weeks, on Saturday.
	 *     - 2w at 18:20         Every 2 weeks, at 6:20 PM.
	 *     - 2w on Wed at 18:20  Every 2 weeks, on Wednesday, at 6:20 PM.
	 *
	 *     Month-based schedules:
	 *     - 1M                 Every month
	 *     - 1M on 13           Every month, on the 13th day.
	 *     - 3M at 11:45        Every 3 months, at 11:45 AM.
	 *     - 3M on 13 at 11:45  Every 3 months, on the 13th day, at 11:45 AM.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss schedule 18 "5d at 12:30"
	 * wp rss schedule 18 21 156 "5d at 12:30"
	 *
	 * @param list<string> $args
	 */
	public function schedule( array $args ): void {
		if ( count( $args ) < 2 ) {
			$this->printCliError( 'You must specify at least one source ID and the schedule.' );
		}

		$ids = array_slice( $args, 0, -1 );
		$ids = $this->parseIntArgArray( $ids, '"%s" is not a valid source ID.' );

		$schedStr = $args[ count( $args ) - 1 ];

		$res = Result::pipe(
			array(
				fn () => ScheduleFactory::fromString( $schedStr ),
				fn ( Schedule $sched ) => $this->sources->schedule( $ids, $sched ),
			)
		);

		$this->printNumUpdated( $res );
	}

	/**
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The ID or search term for a source, or multiple IDs or search terms in square brackets separated by commas.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source unschedule 18
	 * wp rss source unschedule 9 34 106
	 *
	 * @param list<string> $args
	 */
	public function unschedule( array $args ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid source ID.' );
		$res = $this->sources->unschedule( $ids );

		$this->printNumUpdated( $res );
	}

	/** @param Result<int> $result */
	protected function printNumUpdated( Result $result ): void {
		if ( $result->isOk() ) {
			$num = $result->get();

			WP_CLI::success(
				sprintf(
					_n( '%s source updated.', '%s sources updated.', $num, 'wp-rss-aggregator' ),
					number_format_i18n( $num )
				)
			);
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Prints a list of sources as a table.
	 *
	 * @param iterable<Source> $sources The sources.
	 * @param array|null       $columns
	 * @return CliTable
	 */
	public function sourceTable( iterable $sources, ?array $columns = null ): CliTable {
		$columns = $columns ?? array( 'id', 'name', 'isActive', 'schedule', 'lastUpdate', 'nextUpdate' );

		$rows = array();
		foreach ( $sources as $src ) {
			$row = $src->toArray();

			unset( $row['settings'] );
			$row['isActive'] = $row['isActive'] ? 'Yes' : 'No';

			$rows[] = $row;
		}

		return CliTable::create( $rows )
			->showColumns( $columns )
			->columnNames(
				array(
					'id' => 'ID',
					'name' => 'Name',
					'type' => 'Type',
					'url' => 'URL',
					'isActive' => 'Is Active',
					'schedule' => 'Schedule',
					'lastUpdate' => 'Last Update',
					'nextUpdate' => 'Next Update',
				)
			);
	}
}
