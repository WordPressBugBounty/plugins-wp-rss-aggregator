<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use DateTime;

class Progress implements ArraySerializable {

	public string $id;
	public DateTime $started;
	public int $current;
	public int $total;
	public string $message = '';

	/**
	 * Constructor.
	 *
	 * @param string   $id The ID of the progress in the database.
	 * @param int      $current The current amount of work done.
	 * @param int      $total The total amount of work that needs to be done.
	 * @param DateTime $started The time when the work was started.
	 */
	public function __construct( string $id, DateTime $started, int $current, int $total, string $message = '' ) {
		$this->id = $id;
		$this->started = $started;
		$this->current = $current;
		$this->total = $total;
		$this->message = $message;
	}

	/**
	 * Gets the estimated time remaining.
	 *
	 * @return int The estimated time remaining, in seconds.
	 */
	public function getEta(): int {
		$timeElapsed = time() - $this->started->getTimestamp();
		$timePerItem = $this->current === 0 ? 0 : $timeElapsed / $this->current;
		$timeRemaining = $timePerItem * ( $this->total - $this->current );

		return (int) round( $timeRemaining );
	}

	/**
	 * Gets the percentage completion.
	 *
	 * @return float A number between 0 and 1, inclusive, representing the percentage completion between 0% and 100%.
	 */
	public function getPercentage(): float {
		return $this->current / $this->total;
	}

	/**
	 * Checks whether the progress is done.
	 *
	 * @return bool True if the progress is done, false if not.
	 */
	public function isDone(): bool {
		return $this->current >= $this->total;
	}

	/**
	 * Checks if two progress instances are equal in terms of progress. The ID
	 * and start time are not checked.
	 */
	public function equals( Progress $progress ): bool {
		return $this->current === $progress->current
			&& $this->total === $progress->total
			&& $this->message === $progress->message;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'id' => $this->id,
			'started' => Time::toHumanFormat( $this->started ),
			'current' => $this->current,
			'total' => $this->total,
			'message' => $this->message,
			'done' => $this->isDone(),
			'eta' => $this->getEta(),
		);
	}
}
