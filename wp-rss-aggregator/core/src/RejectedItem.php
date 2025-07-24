<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use DateTime;
use Exception;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;

/** Represents a rejected item. */
class RejectedItem implements ArraySerializable {

	public string $guid;
	public DateTime $date;
	public string $notes;

	/**
	 * Constructor.
	 *
	 * @param string   $guid The rejected GUID.
	 * @param DateTime $date Optional date when the item was rejected.
	 * @param string   $notes Optional notes.
	 */
	public function __construct( string $guid, ?DateTime $date = null, string $notes = '' ) {
		$this->guid = $guid;
		$this->date = $date ?? new DateTime();
		$this->notes = $notes;
	}

	public function toArray(): array {
		return array(
			'guid' => $this->guid,
			'date' => $this->date->format( DateTime::ATOM ),
			'notes' => $this->notes,
		);
	}

	/** @param array<string,mixed> $array */
	public static function fromArray( array $array ): self {
		$guid = $array['guid'] ?? '';
		$notes = $array['notes'] ?? '';

		try {
			$dateStr = $array['date'] ?? '';
			$date = $dateStr ? new DateTime( $dateStr ) : null;
		} catch ( Exception $e ) {
			$date = new DateTime();
		}

		return new self( $guid, $date, $notes );
	}
}
