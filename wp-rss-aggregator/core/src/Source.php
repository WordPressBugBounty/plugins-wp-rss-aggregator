<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core;

use Throwable;
use RebelCode\Aggregator\Core\Source\SourceSettings;
use RebelCode\Aggregator\Core\Source\ScheduleFactory;
use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Source\ReconcileStrategy;
use DomainException;
use DateTime;

/** Represents a user-created source from which items can be fetched. */
class Source {

	public ?int $id = null;
	public string $name = '';
	public string $url = '';
	public bool $isActive = false;
	public ?Schedule $schedule = null;
	public ?DateTime $lastUpdate = null;
	public ?string $lastError = null;
	public SourceSettings $settings;
	public ?int $v4Id = null;
	public string $v4Slug = '';

	/**
	 * Constructor.
	 *
	 * @param int|null $id The ID of the source.
	 * @param string   $type The type of the source.
	 * @param string   $name The name of the source.
	 */
	public function __construct( ?int $id = null, string $name = '' ) {
		$this->id = $id;
		$this->name = $name;
		$this->settings = new SourceSettings();
	}

	/**
	 * Creates a copy of the source with a different ID.
	 *
	 * @param int|null $id The ID.
	 * @return self The new instance.
	 */
	public function withId( ?int $id ): self {
		$clone = clone $this;
		$clone->id = $id;
		return $clone;
	}

	/**
	 * Creates a copy of the source with a different URL.
	 *
	 * @param string $url The new URL.
	 * @return self The new instance.
	 */
	public function withUrl( string $url ): self {
		$clone = clone $this;
		$clone->url = $url;
		return $clone;
	}

	/**
	 * Creates a copy of the source with a different name.
	 *
	 * @param string $name The name.
	 * @return self The new instance.
	 */
	public function withName( string $name ): self {
		$clone = clone $this;
		$clone->name = $name;
		return $clone;
	}

	/**
	 * Creates a copy of the source with a different active status.
	 *
	 * @param bool $isActive True for active, false for inactive.
	 * @return self The new instance.
	 */
	public function withActive( bool $isActive ): self {
		$clone = clone $this;
		$clone->isActive = $isActive;
		return $clone;
	}

	/**
	 * Creates a copy of the source with a different schedule.
	 *
	 * @param Schedule|null $schedule The new schedule, or null to set no schedule.
	 * @return self The new instance.
	 */
	public function withSchedule( ?Schedule $schedule ): self {
		$clone = clone $this;
		$clone->schedule = $schedule;
		return $clone;
	}

	/**
	 * Gets the date and time of the source's next update.
	 *
	 * @return DateTime|null An option containing the date and time, or null if the source has no schedule.
	 */
	public function getNextUpdate(): ?DateTime {
		if ( $this->schedule === null ) {
			return null;
		} else {
			$lastTs = $this->lastUpdate ? $this->lastUpdate->getTimestamp() : null;
			$nextTs = $this->schedule->getNext( $lastTs );

			return new DateTime( "@$nextTs" );
		}
	}

	/** Checks if the source needs an update. */
	public function isPendingUpdate(): bool {
		$nextUpdate = $this->getNextUpdate();
		return $this->isActive && $this->schedule !== null && $nextUpdate !== null && $nextUpdate < new DateTime();
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'id' => $this->id,
			'name' => $this->name,
			'url' => $this->url,
			'isActive' => $this->isActive,
			'lastUpdate' => $this->lastUpdate,
			'nextUpdate' => $this->getNextUpdate(),
			'schedule' => $this->schedule,
			'lastError' => $this->lastError,
			'settings' => $this->settings->toArray(),
			'v4Slug' => $this->v4Slug,
		);
	}

	/**
	 * Creates a source from an array.
	 *
	 * @param array<string,mixed> $array The array.
	 * @throws DomainException If the array is invalid.
	 */
	public static function fromArray( array $array ): Source {
		$src = new Source();

		$src->id = ( (int) ( $array['id'] ?? 0 ) ) ?: null;
		$src->name = $array['name'] ?? '';
		$src->url = $array['url'] ?? '';
		$src->isActive = (bool) ( $array['isActive'] ?? false );
		$src->v4Slug = $array['v4Slug'] ?? '';

		$schedule = $array['schedule'] ?? null;
		$src->schedule = ScheduleFactory::from( $schedule )->getOrThrow();

		$lastUpdate = $array['lastUpdate'] ?? null;
		if ( $lastUpdate instanceof DateTime ) {
			$src->lastUpdate = $lastUpdate;
		} elseif ( is_string( $lastUpdate ) ) {
			try {
				$src->lastUpdate = new DateTime( $lastUpdate );
			} catch ( Throwable $e ) {
				throw new DomainException( 'Invalid source lastUpdate datetime', 0, $e );
			}
		} else {
			$src->lastUpdate = null;
		}

		$src->settings = SourceSettings::fromArray( $array['settings'] ?? array() );

		return $src;
	}
}
