<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Settings;
use RebelCode\Aggregator\Core\Logger\PostLogger;

class RpcLoggerHandler {

	private PostLogger $logger;
	private Settings $settings;

	public function __construct( PostLogger $logger, Settings $settings ) {
		$this->logger = $logger;
		$this->settings = $settings;
	}

	public function enabled(): bool {
		return $this->settings->get( 'loggerEnabled' );
	}

	public function start(): string {
		$ok = $this->settings->patch( array( 'loggerEnabled' => true ) )->save();
		if ( $ok ) {
			return 'Started logging.';
		}
		return 'Already logging.';
	}

	public function stop(): string {
		$ok = $this->settings->patch( array( 'loggerEnabled' => false ) )->save();
		if ( $ok ) {
			return 'Stopped logging.';
		}
		return 'Already stopped.';
	}

	public function print( ?int $num = 20, int $page = 1 ): string {
		$logs = $this->logger->getLogs( $num, $page )->get();

		$messages = array();
		foreach ( $logs as $row ) {
			$context = json_decode( $row['context'], true );
			$messages[] = sprintf(
				'[%s] %s | %s: %s',
				$row['date'],
				$this->contextToStr( $context ),
				str_pad( substr( $row['level'], 0, 6 ), 6, ' ', STR_PAD_LEFT ),
				$row['message'],
			);
		}
		return join( "\r\n", $messages );
	}

	public function getList( int $num = 20, int $page = 1 ): array {
		$logs = $this->logger->getLogs( $num, $page )->get();
		$total = $this->logger->getCount()->get();

		return array(
			'logs' => $logs,
			'total' => $total,
		);
	}

	public function clear(): string {
		$num = $this->logger->deleteAll()->get();
		return sprintf( 'Deleted %d log messages', $num );
	}

	private function contextToStr( array $context ): string {
		$str = '';
		foreach ( $context as $key => $value ) {
			$str .= "$key: $value, ";
		}
		return rtrim( $str, ', ' );
	}
}
