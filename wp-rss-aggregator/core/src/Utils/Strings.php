<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

abstract class Strings {

	/**
	 * Converts a string into a boolean, using PHP's built-in filter_var() function.
	 *
	 * @param string $string The string to convert.
	 * @return bool The boolean value.
	 */
	public static function toBool( string $string ): bool {
		return filter_var( $string, FILTER_VALIDATE_BOOLEAN );
	}

	public static function lower( string $string ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $string );
		}
		return strtolower( $string );
	}

	public static function upper( string $string ): string {
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $string );
		}
		return mb_strtoupper( $string );
	}

	/**
	 * Gets a substring, using mb_substr() if the mbstring extension is loaded.
	 *
	 * @param string   $str The string to get the substring from.
	 * @param int      $offset The offset to start from.
	 * @param int|null $length The length of the substring.
	 */
	public static function substr( string $str, int $offset, ?int $length = null ): string {
		if ( extension_loaded( 'mbstring' ) ) {
			return mb_substr( $str, $offset, $length );
		} else {
			return substr( $str, $offset, $length );
		}
	}

	/**
	 * Joins a list into a string using a delimiter, and maps each list item through a function.
	 *
	 * @param iterable $list The list to join.
	 * @param string   $delimiter The delimiter to join with.
	 * @param callable $fn The mapping function to apply to each item.
	 * @return string The joined string.
	 */
	public static function joinMap( iterable $list, string $delimiter, callable $fn ): string {
		return implode( $delimiter, Arrays::map( $list, $fn ) );
	}

	/**
	 * Splits a string by a delimiter, removes empty elements and trims each element.
	 *
	 * @template T
	 * @param string                  $string The string to split.
	 * @param string                  $delimiter The delimiter to split by.
	 * @param callable(string):T|null $mapFn An optional mapping function to apply to each element.
	 * @return list<T> The split string.
	 */
	public static function cleanSplit( string $string, string $delimiter = ',', ?callable $mapFn = null ): array {
		$array = explode( $delimiter, trim( $string, $delimiter ) );

		$result = array();
		foreach ( $array as $item ) {
			$item = trim( $item );

			if ( $item && $mapFn ) {
				$item = $mapFn( $item );
			}

			if ( $item ) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Transforms a string from snake casing to camel casing.
	 *
	 * @param string $str The snake cased string.
	 * @return string The camel case version of the string.
	 */
	public static function snakeCaseToCamelCase( string $str ): string {
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $str ) ) ) );
	}

	/**
	 * Normalizes a string value by checking for null, and trimming leading and trailing whitespace in the string.
	 *
	 * This method is useful for sanitizing string values that may be null, or may contain leading or trailing
	 * whitespace, that need to be checked for empty values.
	 *
	 * @param string|null $input The input string.
	 * @return string The normalized string.
	 */
	public static function normalize( ?string $input ): string {
		return trim( $input ?? '' );
	}

	/**
	 * Checks if a string contains another.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The string to search for.
	 * @param bool   $matchCase Whether to match the case of the strings.
	 * @param bool   $wholeWords Whether the needle must be surround by word
	 *          boundaries, or whether it can start or end mid-word.
	 * @return bool True if the haystack contains the needle, false if not.
	 */
	public static function contains(
		string $haystack,
		string $needle,
		bool $matchCase = false,
		bool $wholeWords = false
	): bool {
		if ( ! $matchCase ) {
			$haystack = strtolower( $haystack );
			$needle = strtolower( $needle );
		}

		if ( $wholeWords ) {
			return preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $haystack ) === 1;
		} else {
			return strpos( $haystack, $needle ) !== false;
		}
	}

	/**
	 * Derivative of {@link wp_trim_words}, without using the PREG_SPLIT_NO_EMPTY flag for preg_split
	 *
	 * Trims text to a certain number of words. This function is localized. For languages that count 'words' by the
	 * individual character (such as East Asian languages), the $num_words argument will apply to the number of
	 * individual characters.
	 *
	 * @param string      $text Text to trim.
	 * @param int         $numWords Number of words. Default 55.
	 * @param string|null $suffix Optional suffix to append if $text needs to be trimmed. If null, '&hellip;' is used.
	 * @return string The result. Either the $text argument if no trimming was needed, or the trimmed text.
	 */
	public static function trimWords( string $text, int $numWords = 55, ?string $suffix = null ): string {
		$suffix = $suffix ?? __( '&hellip;' );
		$original = $text;

		$text = ltrim( $text );
		$prefix = substr( $original, 0, strlen( $original ) - strlen( $text ) );

		// Translators: If your word count is based on single characters (East Asian characters),
		// use 'characters'. Otherwise, use 'words'. Do not translate into your own language.
		$mode = strtolower( _x( 'words', 'DO NOT TRANSLATE. Change to "characters" for Eastern languages', 'wpra' ) );
		$isUtf8 = preg_match( '/^utf-?8$/i', get_option( 'blog_charset' ) );

		if ( $mode === 'characters' && $isUtf8 ) {
			$text = trim( preg_replace( "/[\n\r\t ]+/", ' ', $text ), ' ' );
			preg_match_all( '/./u', $text, $words );
			$words = array_slice( $words[0], 0, $numWords + 1 );
			$sep = '';
		} else {
			$words = preg_split( "/[\n\r\t ]/", $text, $numWords + 1 );
			$sep = ' ';
		}

		if ( ! is_array( $words ) ) {
			return $original;
		}

		if ( count( $words ) > $numWords ) {
			array_pop( $words );
			$text = implode( $sep, $words );
			$text = $text . $suffix;
		} else {
			$text = implode( $sep, $words );
		}

		$text = $prefix . $text;

		/**
		 * Filter the text content after words have been trimmed.
		 *
		 * @param string $text The trimmed text.
		 * @param int $numWords The number of words to trim the text to. Default 5.
		 * @param string $suffix An optional string to append to the end of the trimmed text, e.g. &hellip;.
		 * @param string $original The text before it was trimmed.
		 */
		return apply_filters( 'wp_trim_words', $text, $numWords, $suffix, $original );
	}
}
