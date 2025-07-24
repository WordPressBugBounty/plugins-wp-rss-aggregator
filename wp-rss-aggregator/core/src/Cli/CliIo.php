<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli;

use Throwable;

interface CliIo {

	public function printf( string $message ): void;
	public function print( string $message ): void;
	public function println( string $message = '' ): void;

	/** @param list<CliColors::*> $colors */
	public function cprintf( string $message, array $colors ): void;
	public function cprint( string $message, string $color ): void;
	public function cprintln( string $message, string $color ): void;

	public function ask( string $prompt = '' ): string;

	public function success( string $message ): void;
	public function log( string $message ): void;
	public function debug( string $message, ?string $group = null ): void;
	/** @param string|Throwable $arg */
	public function warning( $arg ): void;
	/** @param string|Throwable $arg */
	public function error( $arg ): void;
}
