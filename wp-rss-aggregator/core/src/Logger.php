<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Throwable;

/** @todo log file and line for exceptions */
class Logger {

	protected static array $levels = array(
		LogLevel::EMERGENCY => 7,
		LogLevel::ALERT => 6,
		LogLevel::CRITICAL => 5,
		LogLevel::ERROR => 4,
		LogLevel::WARNING => 3,
		LogLevel::NOTICE => 2,
		LogLevel::INFO => 1,
		LogLevel::DEBUG => 0,
	);
	/** @var LoggerInterface[] */
	protected static array $stack = array();
	/** @var array<string,mixed> */
	protected static array $ctx = array();
	/** The minimum level to log. */
	protected static int $level = 4;

	/** Pushes a new logger onto the stack. */
	public static function push( LoggerInterface $logger ): void {
		if ( count( static::$stack ) === 0 ) {
			static::$stack[] = new NullLogger();
		}

		array_unshift( static::$stack, $logger );
	}

	/** Pops the current logger off the stack. */
	public static function pop(): LoggerInterface {
		$logger = array_shift( static::$stack );
		if ( $logger === null ) {
			static::push( $logger = new NullLogger() );
		}

		return $logger;
	}

	/**
	 * Set the context to apply to all future log messages.
	 *
	 * @param array<string,mixed> $ctx The context.
	 */
	public static function setContext( array $ctx ): void {
		static::$ctx = $ctx;
	}

	/** Clears the current context. */
	public static function clearContext(): void {
		static::$ctx = array();
	}

	/** Gets the current logger instance. */
	protected static function get(): LoggerInterface {
		if ( count( static::$stack ) === 0 ) {
			static::$stack[] = new NullLogger();
		}

		return static::$stack[0];
	}

	/**
	 * Merges the static context with the context for an individual log message.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	protected static function prepareContext( array $context ): array {
		return array_merge( static::$ctx, $context );
	}

	/**
	 * Prepares the log message.
	 *
	 * @param string|Throwable $arg The message or throwable to log.
	 */
	protected static function prepareMessage( $arg ): string {
		if ( $arg instanceof Throwable ) {
			$message = $arg->getMessage();
			$file = $arg->getFile();
			$line = $arg->getLine();

			return "$message in $file:$line";
		} else {
			return $arg;
		}
	}

	/**
	 * Generic log method.
	 *
	 * @param string              $level The log level.
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function log( $level, $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( $level ) ) {
			static::get()->log( $level, $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * The entire system is unusable.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function emergency( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::EMERGENCY ) ) {
			static::get()->emergency( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Action must be taken immediately.
	 * Example: Website is down, database unavailable, etc. This should trigger the SMS alerts that wake you up. 🤣
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function alert( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::ALERT ) ) {
			static::get()->alert( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Critical conditions.
	 * Example: Application component unavailable, unexpected exception.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function critical( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::CRITICAL ) ) {
			static::get()->critical( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Runtime errors that do not require immediate action but should typically be logged and monitored.
	 * Example: Application component unavailable, unexpected exception.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function error( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::ERROR ) ) {
			static::get()->error( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Exceptional occurrences that are not errors.
	 * Example: Use of deprecated APIs, poor use of an API, undesirable things that are not necessarily wrong.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function warning( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::WARNING ) ) {
			static::get()->warning( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Normal but significant events.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function notice( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::NOTICE ) ) {
			static::get()->notice( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Interesting events.
	 * Example: User logs in, SQL logs.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function info( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::INFO ) ) {
			static::get()->info( $message, static::prepareContext( $context ) );
		}
	}

	/**
	 * Detailed debug information.
	 *
	 * @param string|Throwable    $arg The message or throwable to log.
	 * @param array<string,mixed> $context
	 */
	public static function debug( $arg, array $context = array() ): void {
		$message = static::prepareMessage( $arg );

		if ( static::isLevelAtLeast( LogLevel::DEBUG ) ) {
			static::get()->debug( $message, static::prepareContext( $context ) );
		}
	}

	/** Gets the current minimum log level. */
	public static function getLevel(): string {
		$levelsFlipped = array_flip( static::$levels );
		return $levelsFlipped[ static::$level ] ?? 'debug';
	}

	/** Sets the minimum level to log. */
	public static function setLevel( string $level ): void {
		static::$level = static::getLevelFor( trim( $level ) );
	}

	/** Checks if the current minimum level to log is at least at the given level. */
	public static function isLevelAtLeast( string $level ): bool {
		return static::$level <= static::getLevelFor( $level );
	}

	/** Gets the integer level for a PSR log level */
	protected static function getLevelFor( string $level ): int {
		return static::$levels[ strtolower( $level ) ] ?? -1;
	}
}
