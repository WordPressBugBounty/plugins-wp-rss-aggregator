<?php

namespace RebelCode\Aggregator\Core;

abstract class Tier {

	public const Free = 0;
	public const Basic = 10;
	public const Plus = 20;
	public const Pro = 30;
	public const Elite = 40;

	public static function getName( int $tier ): string {
		switch ( $tier ) {
			case self::Free:
				return __( 'Free', 'wp-rss-aggregator' );
			case self::Basic:
				return __( 'Basic', 'wp-rss-aggregator' );
			case self::Plus:
				return __( 'Plus', 'wp-rss-aggregator' );
			case self::Pro:
				return __( 'Pro', 'wp-rss-aggregator' );
			case self::Elite:
				return __( 'Elite', 'wp-rss-aggregator' );
			default:
				return __( 'Unknown', 'wp-rss-aggregator' );
		}
	}
}
