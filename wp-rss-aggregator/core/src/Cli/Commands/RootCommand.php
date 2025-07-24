<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use function RebelCode\Aggregator\Core\wpra;

/** The top-level command. */
class RootCommand extends BaseCommand {

	/** Show information about the current version of WP RSS Aggregator. */
	public function version(): void {
		$this->io->println( 'WP RSS Aggregator, version ' . wpra()->version );
		$this->io->println( 'Copyright (c) RebelCode 2012-' . date( 'Y' ) );
		$this->io->println();
		$this->io->println( 'Website:         https://wprssaggregator.com' );
		$this->io->println( 'Documentation:   https://kb.wprssaggregator.com' );
		$this->io->println( 'Free Support:    https://wordpress.org/support/plugin/wp-rss-aggregator' );
		$this->io->println( 'Premium Support: https://wprssaggregator.com/support' );
	}
}
