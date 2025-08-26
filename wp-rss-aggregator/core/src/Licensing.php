<?php

namespace RebelCode\Aggregator\Core;

use stdClass;
use RuntimeException;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Licensing\License;
use RebelCode\Aggregator\Core\Licensing\Customer;
use EDD_SL_Plugin_Updater;

class Licensing {

	private const OPTION = 'wpra_license';

	public string $storeUrl = '';
	public array $plans = array();
	private ?License $license = null;

	public function __construct( string $storeUrl, array $plans ) {
		$this->storeUrl = $storeUrl;
		$this->plans = $plans;
	}

	public function createUpdater( string $itemId, string $file, string $version ): EDD_SL_Plugin_Updater {
		$license = $this->getLicense();
		$licenseKey = $license ? $license->key : '';

		if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) ) {
			require_once wpra()->path . '/core/edd-sl-updater.php';
		}

		return new EDD_SL_Plugin_Updater(
			$this->storeUrl,
			$file,
			array(
				'item_id' => $itemId,
				'version' => $version,
				'license' => $licenseKey,
				'author' => 'RebelCode',
			)
		);
	}

	public function getLicense(): ?License {
		if ( $this->license === null ) {
			$array = get_option( self::OPTION, null );

			if ( is_array( $array ) ) {
				$this->license = License::fromArray( $array );
			}
		}

		return $this->license;
	}

	public function getTier(): int {
		$license = $this->getLicense();
		if ( $license === null || $license->status !== License::Valid ) {
			return Tier::Free;
		}
		return $license->tier;
	}

	/**
	 * Retrieves the EDD item ID for the current license.
	 *
	 * @deprecated 5.0.2 Use getItemIds() instead.
	 *
	 * @return int|null An int of item ID, or an null if no license is found.
	 */
	public function getItemId(): ?int {
		_deprecated_function( __METHOD__, '5.0.2', __CLASS__ . '::getItemIds()' );

		$license = $this->getLicense();
		if ( $license === null ) {
			return null;
		}

		switch ( $license->tier ) {
			case Tier::Basic:
				return 839950;
			case Tier::Plus:
				return 839952;
			case Tier::Pro:
				return 839953;
			case Tier::Elite:
				return 839954;
		}

		return null;
	}

	/**
	 * Retrieves the EDD item IDs for the current license tier.
	 *
	 * @since 5.0.2
	 *
	 * @return int[] An array of item IDs, or an empty array if no license is found.
	 */
	public function getItemIds(): array {
		$license = $this->getLicense();
		if ( $license === null ) {
			return array();
		}

		foreach ( $this->plans as $plan ) {
			if ( $plan['tier'] === $license->tier ) {
				return $plan['eddIds'];
			}
		}

		return array();
	}

	/** @return Result<License> */
	public function check( string $key ): Result {
		$license = $this->getLicense();
		return Result::pipe(
			array(
				fn () => $this->sendRequest( 'check_license', $key, $license->eddId ),
				fn ( stdClass $data ) => $this->eddSlResponseToLicense( $key, $data ),
				function ( $rLicense ) {
					if ( ! ( $rLicense instanceof License ) || ! in_array( $rLicense->status, array( 'valid', 'expired' ) ) ) {
						return Result::Err( __( 'License check failed.', 'wprss' ) );
					}

					update_option( self::OPTION, $rLicense->toArray() );

					return Result::Ok( $rLicense );
				},
			)
		);
	}

	/** @return Result<License> */
	public function activate( string $key ): Result {
		$rResponse = $this->sendRequest( 'activate_license', $key );
		if ( $rResponse->isErr() ) {
			return $rResponse;
		}

		$rLicense = $this->eddSlResponseToLicense( $key, $rResponse->get() );
		if ( $rLicense->isErr() ) {
			return $rLicense;
		}

		update_option( self::OPTION, $rLicense->get()->toArray() );

		return $rLicense;
	}

	public function deactivate() {
		$license = $this->getLicense();

		if ( ! $license ) {
			return Result::Ok( new License() );
		}

		$rResponse = $this->sendRequest( 'deactivate_license', $license->key, $license->eddId );

		if ( $rResponse->isErr() ) {
			return $rResponse;
		}

		$responseData = $rResponse->get();

		if ( ! empty( $responseData->success ) ) {
			$message = esc_html__( 'Your license has been deactivated.', 'wprss' );
		} else {
			$message = esc_html__( 'Your license has expired.', 'wprss' );
		}

		delete_option( self::OPTION );

		return Result::Ok( $message );
	}

	/** Updates the saved license to match the licensing server. */
	public function update(): void {
		$license = $this->getLicense();
		if ( $license === null || empty( $license->key ) ) {
			return;
		}

		$result = $this->activate( $license->key );

		if ( $result->isErr() ) {
			$error = new RuntimeException( __( 'Failed to update the license status: ' ), 0, $result->error() );
			Logger::warning( $error );
		}
	}

	public function setLicense( License $license ): self {
		$this->license = $license;
		return $this;
	}

	/** @return Result<stdClass> */
	private function sendRequest( string $action, string $license, $itemId = null ): Result {
		$args = array(
			'edd_action' => $action,
			'license' => $license,
			'site' => site_url(),
		);

		if ( $itemId ) {
			$args['item_id'] = $itemId;
		}

		$query = build_query(
			$args
		);

		$res = wp_remote_get( $this->storeUrl . '?' . $query );
		if ( is_wp_error( $res ) ) {
			return Result::wrapWpError( $res );
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body );

		return Result::Ok( $data );
	}

	/** @return Result<License> */
	private function eddSlResponseToLicense( string $key, object $data ): Result {
		$success = $data->success ?? false;

		if ( ! $success && empty( $data->item_id ) ) {
			return Result::Err( __( 'Invalid license key', 'wprss' ) );
		}

		$itemId = (int) $data->item_id;

		$tier = Tier::Free;
		foreach ( $this->plans as $plan ) {
			if ( in_array( $itemId, $plan['eddIds'] ) ) {
				$tier = $plan['tier'];
				break;
			}
		}

		if ( $tier === Tier::Free ) {
			return Result::Err( __( 'Invalid license key', 'wprss' ) );
		}

		$license = new License();
		$license->key = $key;
		$license->tier = $tier;
		$license->status = $data->license;
		$license->quota = (int) ( $data->license_limit ?? 0 );
		$license->activations = (int) ( $data->activations_left ?? 0 );
		$license->customer = new Customer( $data->customer_name, $data->customer_email );
		$license->eddId = $itemId;

		if ( is_string( $data->expires ?? null ) ) {
			$license->expires = Time::createAndCatch( $data->expires ) ?? null;
		}

		return Result::Ok( $license );
	}
}
