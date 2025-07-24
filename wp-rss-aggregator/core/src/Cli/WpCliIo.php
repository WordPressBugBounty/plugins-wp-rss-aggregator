<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli;

use WP_CLI;

class WpCliIo implements CliIo {

	/** The mapping of CLI color names to their codes. */
	protected const COLOR_CODES = array(
		Colors::NORMAL => '%n',
		Colors::YELLOW => '%y',
		Colors::GREEN => '%g',
		Colors::BLUE => '%b',
		Colors::RED => '%r',
		Colors::MAGENTA => '%m',
		Colors::CYAN => '%c',
		Colors::GREY => '%w',
		Colors::BLACK => '%k',
		Colors::BOLD_NORMAL => '%N',
		Colors::BOLD_YELLOW => '%Y',
		Colors::BOLD_GREEN => '%G',
		Colors::BOLD_BLUE => '%B',
		Colors::BOLD_RED => '%R',
		Colors::BOLD_MAGENTA => '%M',
		Colors::BOLD_CYAN => '%C',
		Colors::BOLD_GREY => '%W',
		Colors::BOLD_BLACK => '%K',
		Colors::BG_YELLOW => '%3',
		Colors::BG_GREEN => '%2',
		Colors::BG_BLUE => '%4',
		Colors::BG_RED => '%1',
		Colors::BG_MAGENTA => '%5',
		Colors::BG_CYAN => '%6',
		Colors::BG_GREY => '%7',
		Colors::BG_BLACK => '%0',
		Colors::BLINK => '%F',
		Colors::UNDERLINE => '%U',
		Colors::INVERSE => '%8',
		Colors::BOLD => '%9',
	);

	public function printf( string $message, ...$args ): void {
		vprintf( $message, $args );
	}

	public function print( string $message ): void {
		echo $message;
	}

	public function println( string $message = '' ): void {
		echo $message . PHP_EOL;
	}

	public function cprint( string $message, string $color ): void {
		$this->cprintf( "%($message)%", array( $color ) );
	}

	public function cprintln( string $message, string $color ): void {
		$this->cprintf( "%($message)%\n", array( $color ) );
	}

	public function cprintf( string $message, array $colors ): void {
		echo preg_replace_callback(
			'/%\((.*?)\)%/sm',
			function ( array $matches ) use ( &$colors ) {
				$msg = $matches[1];
				$color = array_shift( $colors ) ?? Colors::NORMAL;
				$code = self::COLOR_CODES[ strtolower( $color ) ] ?? Colors::NORMAL;

				return WP_CLI::colorize( "{$code}{$msg}%n" );
			},
			$message
		);
	}

	public function ask( string $prompt = '' ): string {
		if ( $prompt ) {
			$this->print( $prompt );
		}

		return trim( fgets( STDIN ) );
	}

	public function success( string $message ): void {
		WP_CLI::success( $message );
	}

	public function log( string $message ): void {
		WP_CLI::log( $message );
	}

	public function debug( string $message, ?string $group = null ): void {
		WP_CLI::debug( $message );
	}

	public function warning( $arg ): void {
		WP_CLI::warning( $arg );
	}

	public function error( $arg ): void {
		WP_CLI::error( $arg, false );
	}
}
