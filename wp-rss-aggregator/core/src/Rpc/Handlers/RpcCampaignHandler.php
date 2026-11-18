<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

/**
 * RPC handler for time-boxed marketing campaigns shown in the admin app.
 *
 * @since 5.8.0
 */
class RpcCampaignHandler {

	/**
	 * The dismissed-pointer ID for the Black Friday 2026 Hub banner.
	 *
	 * @since 5.8.0
	 */
	public const BF26_BANNER_POINTER = 'wpra_bf26_banner';

	/**
	 * Hides the Black Friday Hub banner for the current user.
	 *
	 * I store it with WordPress's dismissed admin pointers, so it is saved per
	 * user and follows them across browsers.
	 *
	 * @since 5.8.0
	 *
	 * @return bool True when the banner is now dismissed for the user.
	 */
	public function dismissBlackFridayBanner(): bool {
		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			return false;
		}

		$dismissed = $this->getDismissedPointers( $userId );
		if ( in_array( self::BF26_BANNER_POINTER, $dismissed, true ) ) {
			return true;
		}

		$dismissed[] = self::BF26_BANNER_POINTER;
		update_user_meta( $userId, 'dismissed_wp_pointers', implode( ',', $dismissed ) );

		return true;
	}

	/**
	 * Checks whether the current user has hidden the Black Friday Hub banner.
	 *
	 * @since 5.8.0
	 */
	public function isBlackFridayBannerDismissed(): bool {
		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			return false;
		}

		return in_array( self::BF26_BANNER_POINTER, $this->getDismissedPointers( $userId ), true );
	}

	/**
	 * Gets the dismissed admin pointer IDs for a user.
	 *
	 * @since 5.8.0
	 *
	 * @param int $userId The user ID.
	 * @return list<string> The pointer IDs.
	 */
	private function getDismissedPointers( int $userId ): array {
		$meta = (string) get_user_meta( $userId, 'dismissed_wp_pointers', true );

		return array_values( array_filter( explode( ',', $meta ) ) );
	}
}
