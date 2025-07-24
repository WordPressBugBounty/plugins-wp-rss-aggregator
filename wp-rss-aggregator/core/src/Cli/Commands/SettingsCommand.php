<?php

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\Colors;
use RebelCode\Aggregator\Core\Settings;
use ReflectionClass;
use ReflectionException;
use Throwable;

class SettingsCommand extends BaseCommand {

	protected Settings $settings;

	public function __construct( CliIo $io, Settings $settings ) {
		parent::__construct( $io );
		$this->settings = $settings;
	}

	public function list(): void {
		$data = $this->settings->toArray();
		$this->printArrayValue( $data );
	}

	/**
	 * Set the value of a single setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The key of the setting to change.
	 *
	 * <value>
	 * : The new value of the setting.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss settings set "mergedFeedUrl" "my-custom-feed"
	 *
	 * @param list<string> $args
	 */
	public function set( array $args ): void {
		[$key, $value] = $args;

		try {
			$ref = new ReflectionClass( $this->settings );
			$prop = $ref->getProperty( $key );
		} catch ( ReflectionException $t ) {
			$this->io->error( "The setting \"$key\" does not exist." );
			exit( 1 );
		}

		switch ( $prop->getType()->getName() ) {
			case 'string':
				$value = (string) $value;
				break;
			case 'int':
				if ( is_numeric( $value ) ) {
					$value = (int) $value;
				} else {
					$this->io->error( 'Value is not a valid whole number.' );
					exit( 1 );
				}
				break;
			case 'float':
				if ( is_numeric( $value ) ) {
					$value = (float) $value;
				} else {
					$this->io->error( 'Value is not a valid number.' );
					exit( 1 );
				}
				break;
			case 'bool':
				$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( ! is_bool( $value ) ) {
					$this->io->error( "Value is not a valid boolean value. Use 'true' or 'false'." );
					exit( 1 );
				}
				break;
			case 'array':
				$value = json_decode( $value, true );
				if ( $value === null ) {
					throw new Throwable( 'Invalid value. Use JSON format for arrays and objects.' );
				}
				break;
			default:
				$this->io->error( "The setting \"$key\" cannot be set through the command line." );
				exit( 1 );
		}

		$this->settings->patch( array( $key => $value ) );
		$this->settings->save();
		$this->io->cprintln( "Setting \"$key\" updated!", Colors::GREEN );
	}

	/** @param array<string,mixed> $array */
	protected function printArrayValue( array $array, string $prefix = '' ): void {
		foreach ( $array as $key => $value ) {
			$this->io->print( $prefix );

			if ( is_array( $value ) ) {
				$this->io->cprintf( "%($key)%:\n", array( Colors::BOLD ) );
				$this->printArrayValue( $value, $prefix . '  ' );
			} else {
				$this->io->cprintf( "%($key)%: $value\n", array( Colors::BOLD ) );
			}
		}
	}
}
