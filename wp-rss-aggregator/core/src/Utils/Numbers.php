<?php /** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

use NumberFormatter;

class Numbers {

	public const ORDINAL_SUFFIX = array( 'th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th' );

	/**
	 * Gets the ordinal suffix (st, nd, rd, th) for a number.
	 *
	 * @param int $number The number.
	 * @return string
	 */
	public static function formatOrdinal( int $number ): string {
		if ( extension_loaded( 'intl' ) ) {
			$formatter = new NumberFormatter( get_locale(), NumberFormatter::ORDINAL );
			return $formatter->format( $number );
		} else {
			// 11, 12, 13 are exceptions; they use "th" instead of "st", "nd", "rd"
			if ( ( $number % 100 ) >= 11 && ( $number % 100 ) <= 13 ) {
				return $number . 'th';
			} else {
				$unit = absint( $number % 10 );

				return $number . self::ORDINAL_SUFFIX[ $unit ];
			}
		}
	}
}
