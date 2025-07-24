<?php

namespace RebelCode\Aggregator\Core\Licensing;

use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;
use DateTime;

class License implements ArraySerializable {

	public const Valid = 'valid';
	public const Invalid = 'invalid';
	public const Deactivated = 'deactivated';
	public const Inactive = 'site_inactive';
	public const Expired = 'expired';
	public const QuotaReached = 'no_activations_left';
	public const Revoked = 'disabled';

	public string $key = '';
	public int $tier = 0;
	public string $status = 'inactive';
	public ?Customer $customer = null;
	public int $quota = 0;
	public int $activations = 0;
	public ?DateTime $expires = null;
	public int $eddId = 0;

	public function toArray(): array {
		return array(
			'key' => $this->key,
			'item_id' => $this->eddId,
			'tier' => $this->tier,
			'status' => $this->status,
			'customer' => $this->customer->toArray(),
			'quota' => $this->quota,
			'activations' => $this->activations,
			'expires' => $this->expires ? $this->expires->format( DATE_ATOM ) : null,
		);
	}

	public static function fromArray( array $array ): self {
		$license = new self();

		$license->key = $array['key'] ?? '';
		$license->eddId = (int) ( $array['item_id'] ?? 0 );
		$license->tier = (int) ( $array['tier'] ?? 0 );
		$license->status = $array['status'] ?? '';
		$license->customer = Customer::fromArray( $array['customer'] ?? array() );
		$license->quota = (int) ( $array['quota'] ?? 0 );
		$license->activations = (int) ( $array['activations'] ?? 0 );
		$expires = $array['expires'] ?? null;
		if ( $expires ) {
			$license->expires = Time::createAndCatch( $expires );
		}

		return $license;
	}
}
