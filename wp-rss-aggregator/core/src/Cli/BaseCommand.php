<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli;

use Exception;
use Throwable;

class BaseCommand {

	protected CliIo $io;

	public function __construct( CliIo $io ) {
		$this->io = $io;
	}

	/** @param int|string $arg */
	protected function parseIntArg( $arg, string $errPattern = '%s is not a valid number' ): int {
		if ( ! is_int( $arg ) && ! is_numeric( $arg ) ) {
			$this->io->error( sprintf( $errPattern, $arg ) );
			exit( 1 );
		}

		return (int) $arg;
	}

	/**
	 * @param list<int|string> $args
	 * @return list<int>
	 */
	protected function parseIntArgArray( array $args, string $argName = '' ): array {
		$result = array();

		foreach ( $args as $arg ) {
			$result[] = $this->parseIntArg( $arg, $argName );
		}

		return $result;
	}

	/**
	 * @param list<string> $args
	 * @return arary<string,string>
	 */
	protected function parseKeyValueArgs( array $args ): array {
		$map = array();

		foreach ( $args as $arg ) {
			if ( str_contains( $arg, '=' ) ) {
				$parts = explode( '=', $arg );

				if ( count( $parts ) === 2 ) {
					[$key, $value] = $parts;
					$map[ trim( $key ) ] = trim( $value );
					continue;
				}
			}

			$this->io->error( "Invalid argument: \"$arg\"." );
		}

		return $map;
	}

	/**
	 * @param Throwable|null $err
	 * @return never
	 */
	protected function printCliException( ?Throwable $err ): void {
		if ( $err === null ) {
			$err = new Exception( 'Something went wrong' );
		}

		if ( $err instanceof Throwable ) {
			$this->io->error( $err->getMessage() . "\n" . $err->getTraceAsString() );
		}
	}

	/**
	 * @param Throwable|string|null $err
	 * @return never
	 */
	protected function printCliError( $err ): void {
		if ( $err === null ) {
			$err = new Exception( 'Something went wrong' );
		}

		if ( $err instanceof Throwable ) {
			$this->io->error( $err->getMessage() );
		} else {
			$this->io->error( $err );
		}
	}

	/**
	 * @param Throwable|string|null $err
	 */
	protected function printCliWarning( $err ): void {
		if ( $err === null ) {
			$err = new Exception( 'Something went wrong' );
		}

		if ( $err instanceof Throwable ) {
			$this->io->warning( $err->getMessage() . "\n" . $err->getTraceAsString() );
		} else {
			$this->io->warning( $err );
		}
	}

	/**
	 * Asks the user for an integer.
	 *
	 * @param string        $prompt The prompt to display.
	 * @param int|null      $default The default value if the user enters nothing. If null, the prompt repeats until the user
	 *                               enters a valid value.
	 * @param callable|null $validate Optional validation callback. If the callback returns a string, the string is
	 *                                displayed as a warning and the prompt repeats. If the callback returns an integer,
	 *                                the integer is returned as the result. Other return values will repeat the prompt.
	 * @return int The entered integer.
	 */
	protected function askForInt( string $prompt, ?int $default = null, ?callable $validate = null ): int {
		do {
			$input = $this->io->ask( $prompt );

			if ( empty( $input ) && $default !== null ) {
				return $default;
			}

			if ( ! is_numeric( $input ) ) {
				$this->io->warning( 'Value must be a number.' );
				continue;
			}

			$num = (int) $input;

			if ( $validate !== null ) {
				$result = $validate( $num );

				if ( is_string( $result ) ) {
					$this->io->warning( $result );
					continue;
				}

				if ( is_int( $result ) ) {
					return $result;
				}
			} else {
				return $num;
			}
		} while ( true );
	}

	/**
	 * Asks the user for a time string.
	 *
	 * @param string   $prompt The prompt to display.
	 * @param int|null $default The default value if the user enters nothing. If null, the prompt repeats until the user
	 *                          enters a valid value.
	 * @return int The entered time in seconds.
	 */
	protected function askForTimeString( string $prompt, ?int $default = null ): int {
		do {
			$input = $this->io->ask( $prompt . ' [HH:MM]' );
			$parts = explode( ':', $input );

			$hours = is_numeric( $parts[0] ?? '' )
				? (int) $parts[0]
				: null;
			$minutes = is_numeric( $parts[1] ?? '' )
				? (int) $parts[1]
				: null;

			if ( empty( $input ) && $default !== null ) {
				return $default;
			}

			if ( $hours === null || $minutes === null ) {
				$this->io->warning( 'Invalid time format. Please use HH:MM.' );
				continue;
			}

			return ( $hours * 3600 ) + ( $minutes * 60 );
		} while ( true );
	}

	/**
	 * Ask the user to confirm either yes or no.
	 *
	 * @param string $question The question to ask.
	 * @return bool True if the user confirmed "yes", false if they said "no".
	 */
	protected function confirm( string $question ): bool {
		do {
			$input = $this->io->ask( $question . ' [y/n]' );
			$lower = strtolower( $input );
		} while ( $lower !== 'y' && $lower !== 'n' );

		return $lower === 'y';
	}

	/**
	 * Ask the user to choose from a list of options.
	 *
	 * @param string $prompt The prompt to display.
	 * @param array  $choices The list of choices.
	 * @param bool   $allowEmpty Whether to allow the user to choose no option.
	 * @return int The option that the user chose, starting from 1. If $allowEmpty is true, -1 is returned if the user
	 *             chooses no option.
	 */
	protected function choice( string $prompt, array $choices, bool $allowEmpty = false ): int {
		$count = count( $choices );
		foreach ( $choices as $i => $choice ) {
			$this->io->printf( "%d. %s\n", $i + 1, $choice );
		}

		return $this->askForInt(
			$prompt,
			$allowEmpty ? -1 : null,
			fn ( int $c ) => ( $c > 0 && $c <= $count ) ? $c : "Please pick a number between 1 and $count."
		);
	}
}
