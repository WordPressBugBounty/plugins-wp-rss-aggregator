<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use Psr\Log\LogLevel;
use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Importer;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\Result;

class FetchCommand extends BaseCommand {

	protected Importer $importer;

	public function __construct( CliIo $io, Importer $importer ) {
		parent::__construct( $io );
		$this->importer = $importer;
	}

	/**
	 * Fetches new items from a feed source, without importing them.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the feed source.
	 *
	 * [--num=<num>]
	 * : The maximum number of items to fetch.
	 *
	 * [--log=<level>]
	 * : The minimum log level.
	 * ---
	 * default: info
	 * options:
	 *   - debug
	 *   - info
	 *   - notice
	 *   - warning
	 *   - error
	 *   - critical
	 *   - alert
	 *   - emergency
	 * ---
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $opts
	 */
	public function __invoke( array $args, array $opts ): void {
		Logger::setLevel( $opts['log'] ?? LogLevel::INFO );

		$srcId = $this->parseIntArg( $args[0], '%s is not a valid source ID.' );

		if ( isset( $opts['num'] ) ) {
			$num = $this->parseIntArg( $opts['num'], '%s is not a valid number.' );
		} else {
			$num = null;
		}

		$result = Result::pipe(
			array(
				fn () => $this->importer->sources->getById( $srcId ),
				fn ( Source $src ) => $this->importer->fetch( $src, $num ),
			)
		);

		if ( $result->isOk() ) {
			$posts = Arrays::fromIterable( $result->get() );
			$count = count( $posts );

			if ( $count > 0 ) {
				$this->io->println( "Feed has $count new items" );
				foreach ( $posts as $post ) {
					$this->io->println( ' • ' . $post->title );
				}
			} else {
				$this->io->println( 'Feed has no new items' );
			}
		} else {
			$this->printCliException( $result->error() );
		}
	}
}
