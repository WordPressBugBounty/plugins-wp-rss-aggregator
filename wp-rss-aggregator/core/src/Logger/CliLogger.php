<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\Colors;

class CliLogger implements LoggerInterface {

	use LoggerTrait;

	// Number versions of the log levels
	public const DEBUG = 0;
	public const INFO = 1;
	public const NOTICE = 2;
	public const WARNING = 3;
	public const ERROR = 4;
	public const CRITICAL = 5;
	public const ALERT = 6;
	public const EMERGENCY = 7;

	// Mapping of log levels to numbers
	protected static array $levelNums = array(
		LogLevel::EMERGENCY => self::EMERGENCY,
		LogLevel::ALERT => self::ALERT,
		LogLevel::CRITICAL => self::CRITICAL,
		LogLevel::ERROR => self::ERROR,
		LogLevel::WARNING => self::WARNING,
		LogLevel::NOTICE => self::NOTICE,
		LogLevel::INFO => self::INFO,
		LogLevel::DEBUG => self::DEBUG,
	);

	protected static array $colors = array(
		LogLevel::EMERGENCY => Colors::RED,
		LogLevel::ALERT => Colors::RED,
		LogLevel::CRITICAL => Colors::RED,
		LogLevel::ERROR => Colors::RED,
		LogLevel::WARNING => Colors::YELLOW,
		LogLevel::NOTICE => Colors::CYAN,
		LogLevel::INFO => Colors::GREEN,
		LogLevel::DEBUG => Colors::MAGENTA,
	);

	protected CliIo $io;
	protected int $minLevel;

	public function __construct( CliIo $io, int $minLevel = self::ERROR ) {
		$this->io = $io;
		$this->minLevel = $minLevel;
	}

	/** @inheritDoc */
	public function log( $level, $message, array $context = array() ): void {
		$num = self::$levelNums[ $level ] ?? self::ERROR;

		if ( $num >= $this->minLevel ) {
			$prefix = '[' . strtoupper( $level ) . ']';
			$color = static::$colors[ $level ] ?? Colors::NORMAL;

			$this->io->cprintf( "%($prefix)% $message\n", array( $color ) );
		}
	}
}
