<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\CliTable;
use RebelCode\Aggregator\Core\RejectedItem;
use RebelCode\Aggregator\Core\Store\RejectListStore;
use RebelCode\Aggregator\Core\Utils\Arrays;
use WP_CLI;

class RejectListCommand extends BaseCommand {

	protected RejectListStore $rejectList;

	public function __construct( CliIo $io, RejectListStore $rejectList ) {
		parent::__construct( $io );
		$this->rejectList = $rejectList;
	}

	/**
	 * Add an item to the reject list.
	 *
	 * ## OPTIONS
	 *
	 * <guid>
	 * : The GUID to add. Items with no GUID use their permalink as a GUID.
	 *
	 * [<note>]
	 * : Optional notes to include.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss rejectlist add "https://example.com/blog/1"
	 * wp rss rejectlist add "4B00C21991FE0" "Off-topic post"
	 *
	 * @param list<string> $args
	 */
	public function add( array $args ): void {
		$guid = $args[0];
		$notes = $args[1] ?? '';

		$result = $this->rejectList->add( $guid, null, $notes );

		if ( $result->isOk() ) {
			WP_CLI::success( sprintf( __( 'Added "%s" to the reject list', 'wp-rss-aggregator' ), $guid ) );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Remove an item from the reject list.
	 *
	 * ## OPTIONS
	 *
	 * <guid>
	 * : The GUID to remove.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss rejectlist remove "https://example.com/blog/1"
	 *
	 * @param list<string> $args
	 */
	public function remove( array $args ): void {
		$guid = $args[0];

		$result = $this->rejectList->delete( $guid );

		if ( $result->isOk() ) {
			$num = $result->get();
			WP_CLI::success( sprintf( _n( 'Removed %d item', 'Removed %d items', $num, 'wp-rss-aggregator' ), $num ) );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * List all the items in the reject list.
	 *
	 * ## OPTIONS
	 *
	 * [<num>]
	 * : The number of items to display per page. Default is 20.
	 *
	 * [--page=<num>]
	 * : The page number to display.
	 *
	 * [--search=<search>]
	 * : Search for items by GUID or notes.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss rejectlist list
	 * wp rss rejectlist list --page=2
	 * wp rss rejectlist list --per-page=10
	 * wp rss rejectlist list --per-page=10 --page=5
	 * wp rss rejectlist list --search="inappropriate"
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function list( array $args, array $opts ): void {
		$num = (int) ( $args[0] ?? 20 );
		$page = (int) ( $opts['page'] ?? 1 );
		$filter = trim( $opts['search'] ?? '' );

		$result = $this->rejectList->getList( $filter, $num, $page );

		if ( $result->isOk() ) {
			$rows = Arrays::gmap( $result->get(), fn ( RejectedItem $i ) => $i->toArray() );

			CliTable::create( $rows )
				->showColumns( array( 'guid', 'notes' ) )
				->columnNames(
					array(
						'guid' => 'GUID',
						'notes' => 'Notes',
					)
				)
				->render();
		} else {
			$this->printCliError( $result->error() );
		}
	}

	public function clear(): void {
		$result = $this->rejectList->deleteAll();

		if ( $result->isOk() ) {
			$num = $result->get();
			WP_CLI::success(
				sprintf(
					_n( 'Deleted %d item.', 'Deleted %d items', $num, 'wp-rss-aggregator' ),
					$num
				)
			);
		} else {
			$this->printCliError( $result->error() );
		}
	}
}
