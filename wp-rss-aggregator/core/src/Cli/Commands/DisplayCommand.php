<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use WP_CLI;
use RebelCode\Aggregator\Core\Utils\Strings;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\DisplaysStore;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Display;
use RebelCode\Aggregator\Core\Cli\Colors;
use RebelCode\Aggregator\Core\Cli\CliTable;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\BaseCommand;

class DisplayCommand extends BaseCommand {

	protected DisplaysStore $displays;
	protected SourcesStore $sources;

	public function __construct(
		CliIo $io,
		DisplaysStore $displays,
		SourcesStore $sources
	) {
		parent::__construct( $io );
		$this->displays = $displays;
		$this->sources = $sources;
	}

	/**
	 * Create a new display.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name of the display.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display new "Homepage"
	 *
	 * @param list<string> $args
	 */
	public function new( array $args ): void {
		$name = $args[0];

		$result = $this->displays->insert( new Display( null, $name ) );

		if ( $result->isOk() ) {
			WP_CLI::success( "Display created! ID: {$result->get()->id}" );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Duplicates a display.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the display to duplicate.
	 *
	 * [<name>]
	 * : Optional name for the new display. If omitted, '(Copy)' will be appended to the original name.
	 *
	 * ## EXAMPLE
	 *
	 * wp rss display duplicate 34
	 * wp rss display duplicate 14 --name="Sidebar"
	 *
	 * @param list<string> $args
	 */
	public function duplicate( array $args ): void {
		$id = $this->parseIntArg( $args[0], '%s is not a valid display ID.' );
		$name = $args['name'] ?? null;

		$result = $this->displays->getById( $id );
		if ( $result->isErr() ) {
			$this->printCliError( $result->error() );
		}

		$display = $result->get();

		$pattern = _x( '%s (copy)', 'Name of a duplicated display', 'wp-rss-aggregator' );
		$newDisplay = $display->withName( $name ?? sprintf( $pattern, $display->name ) );

		$result = $this->displays->insert( $newDisplay );

		if ( $result->isOk() ) {
			$display = $result->get();
			WP_CLI::success( "Created display \"{$display->name}\" with ID {$display->id}." );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Show information about a display.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The IDs of the display to show.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display show 9
	 * wp rss display show 16 19 35
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function show( array $args, array $opts ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid display ID.' );

		$result = $this->displays->getManyByIds( $ids );

		if ( $result->isOk() ) {
			foreach ( $result->get() as $display ) {
				$ids = array_diff( $ids, array( $display->id ) );

				$this->io->cprintf( "%($display->name)% [#$display->id]\n", array( Colors::BOLD ) );

				$result = $this->sources->getManyByIds( $display->sources );

				if ( $result->isOk() ) {
					$this->io->cprintf( "%(Sources)%:\n", array( Colors::BOLD ) );
					foreach ( $result->get() as $src ) {
						$this->io->println( " • $src->name" );
					}
				} else {
					$this->printCliError( $result->error() );
				}

				$this->io->cprintf( "%(Folders)%:\n", array( Colors::BOLD ) );
				foreach ( $display->folders as $folder ) {
					$this->io->println( " • $folder" );
				}

				$this->io->cprintf( "%(Settings)%:\n", array( Colors::BOLD ) );
				foreach ( $display->settings->toArray() as $key => $value ) {
					$json = json_encode( $value );
					$this->io->cprintf( " • %($key)%: $json\n", array( Colors::CYAN ) );
				}

				$this->io->println();
			}

			foreach ( $ids as $id ) {
				$this->io->warning( "Display with ID $id not found." );
			}
		} else {
			$this->printCliException( $result->error() );
		}
	}

	/**
	 * List the feed displays with a summary of their information.
	 *
	 * ## OPTIONS
	 *
	 * [<num>]
	 * : The number of displays to display per page. Default is 20.
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
	 * : Search for displays by name or URL.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display list
	 * wp rss display list --page=2
	 * wp rss display list --per-page=10
	 * wp rss display list --per-page=10 --page=5
	 * wp rss display list --columns=name,url,active
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function list( array $args, array $opts ): void {
		$num = (int) ( $args[0] ?? 20 );
		$page = (int) ( $opts['page'] ?? 1 );
		$filter = trim( $opts['search'] ?? '' );

		$result = $this->displays->getList( $filter, $num, $page, 'asc', 'name' );

		if ( $result->isOk() ) {
			$displays = Arrays::fromIterable( $result->get() );

			$cols = $opts['cols'] ?? null;
			$cols = is_string( $cols ) ? explode( ',', $cols ) : null;

			self::displayTable( $displays, $cols )->render();
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Delete one or multiple displays.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : The IDs of the displays to delete.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display delete 22
	 * wp rss display delete 39 50 88
	 *
	 * @param list<string> $args
	 */
	public function delete( array $args ): void {
		$ids = $this->parseIntArgArray( $args, '"%s" is not a valid display ID.' );

		$result = $this->displays->deleteManyByIds( $ids );

		if ( $result->isOk() ) {
			$num = $result->get();
			WP_CLI::success( sprintf( _n( 'Deleted %d display.', 'Deleted %d displays.', $num, 'wp-rss-aggregator' ), $num ) );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Configure the settings for one or more displays.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The IDs of the displays.
	 *
	 * <changes>...
	 * : The settings to change in the form: `setting=value`. JSON values or values that contain spaces must be quoted.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display set 14 layout=grid
	 * wp rss display set 14 32 51 layout=grid numPosts=10
	 *
	 * @param list<string> $args
	 */
	public function set( array $args ): void {
		$cIdx = Arrays::indexOf( $args, fn ( $arg ) => ! is_numeric( $arg ) );

		$ids = array_slice( $args, 0, $cIdx );
		$ids = $this->parseIntArgArray( $ids, '"%s" is not a valid display ID.' );

		$cList = array_slice( $args, $cIdx );
		$cMap = $this->parseKeyValueArgs( $cList );

		$res = $this->displays->configure( $ids, $cMap );

		$this->printNumUpdated( $res );
	}


	/**
	 * Rename a display.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the display to rename.
	 *
	 * <name>
	 * : The new name.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss display rename 11 "Footer"
	 *
	 * @param list<string> $args
	 */
	public function rename( array $args ): void {
		$id = $this->parseIntArg( $args[0], '"%s" is not a valid display ID.' );
		$name = $args[1];

		$result = $this->displays->getById( $id );

		if ( $result->isOk() ) {
			$result = $this->displays->save( $result->get()->withName( $name ) );
		}

		$this->printNumUpdated( $result );
	}

	/**
	 * Adds sources and folders to a display.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the display.
	 *
	 * [--sources=<sources>]
	 * : One or more IDs of the sources to add. Multiple IDs must be separated by a comma.
	 *
	 * [--folders=<folders>]
	 * : One or more IDs of the folders to add. Multiple IDs must be separated by a comma.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function add( array $args, array $opts ): void {
		$id = $this->parseIntArg( $args[0] );
		[$sIds, $fIds] = $this->checkSourcesAndFoldersArgs( $opts );

		$result1 = $this->displays->getById( $id );
		if ( $result1->isErr() ) {
			$this->printCliError( $result1->error() );
		}

		$newDisplay = $result1->get()
			->withAddedSources( $sIds )
			->withAddedFolders( $fIds );

		$saveResult = $this->displays->save( $newDisplay );
		if ( $saveResult->isErr() ) {
			$this->printCliError( $saveResult->error() );
		}
	}

	/**
	 * Removes sources and folders from a display.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the display.
	 *
	 * [--sources=<sources>]
	 * : One or more IDs of the sources to remove. Multiple IDs must be separated by a comma.
	 *
	 * [--folders=<folders>]
	 * : One or more IDs of the folders to remove. Multiple IDs must be separated by a comma.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function remove( array $args, array $opts ): void {
		$id = $this->parseIntArg( $args[0] );
		[$sIds, $fIds] = $this->checkSourcesAndFoldersArgs( $opts );

		$result1 = $this->displays->getById( $id );
		if ( $result1->isErr() ) {
			$this->printCliError( $result1->error() );
		}

		$newDisplay = $result1->get()
			->withoutSources( $sIds )
			->withoutFolders( $fIds );

		$saveResult = $this->displays->save( $newDisplay );
		if ( $saveResult->isErr() ) {
			$this->printCliError( $saveResult->error() );
		}
	}

	/**
	 * @param array<string,string> $opts
	 * @return list{0:list<int>,1:list<int>}
	 */
	protected function checkSourcesAndFoldersArgs( array $opts ): array {
		$fNames = Strings::cleanSplit( $opts['folders'] ?? '', ',' );
		$sOpt = Strings::cleanSplit( $opts['sources'] ?? '', ',' );
		$sIds = $this->parseIntArgArray( $sOpt );

		$sExist = array();

		$result = $this->sources->checkWhichExist( $sIds );

		if ( $result->isOk() ) {
			$sExist = Arrays::fromIterable( $result->get() );
			$sNotExist = array_diff( $sIds, $sExist );
			$numNotExist = count( $sNotExist );

			if ( $numNotExist ) {
				WP_CLI::warning(
					sprintf(
						_n( 'Source %s does not exist.', 'Sources %s do not exist.', $numNotExist, 'wp-rss-aggregator' ),
						join( ', ', $sNotExist )
					)
				);
			}
		} else {
			$this->printCliError( $result->error() );
		}

		return array( $sExist, $fNames );
	}

	/** @param Result<int> $result */
	protected function printNumUpdated( Result $result ): void {
		if ( $result->isOk() ) {
			$num = $result->get();

			WP_CLI::success(
				sprintf(
					_n( '%s display updated.', '%s displays updated.', $num, 'wp-rss-aggregator' ),
					number_format_i18n( $num )
				)
			);
		} else {
			$this->printCliError( $result->error() );
		}
	}
	/**
	 * Prints a list of displays as a table.
	 *
	 * @param iterable<Display> $displays The displays.
	 * @param array|null        $columns The columns to show.
	 * @return CliTable
	 */
	protected function displayTable( iterable $displays, ?array $columns = null ): CliTable {
		$columns = $columns ?? array( 'id', 'name', 'sources', 'folders' );
		$rows = array();

		foreach ( $displays as $display ) {
			$row = $display->toArray();

			if ( in_array( 'sources', $columns ) && count( $display->sources ) > 0 ) {
				$result = $this->sources->getManyByIds( $display->sources );

				if ( $result->isOk() ) {
					$row['sources'] = Arrays::gmap( $result->get(), fn ( Source $s ) => $s->name ?: '(No name)' );
				} else {
					$this->printCliError( $result->error() );
				}
			}

			if ( in_array( 'folders', $columns ) && count( $display->folders ) > 0 ) {
				$row['folders'] = $display->folders;
			}

			$rows[] = $row;
		}

		return CliTable::create( $rows )
			->showColumns( $columns )
			->columnNames(
				array(
					'id' => 'ID',
					'name' => 'Name',
					'sources' => 'Sources',
					'folders' => 'Folders',
				)
			);
	}
}
