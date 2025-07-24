<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

abstract class Http {

	/**
	 * Fetches the response headers for a given URL.
	 *
	 * @param string $url The URL to fetch the headers for.
	 * @return array<string,string|list<string>> A mapping from lowercase header names to header values.
	 */
	public static function getHeaders( string $url ): array {
		static $timeout = null;
		if ( $timeout === null ) {
			$timeout = apply_filters( 'wpra.http.get_headers.timeout', 0.4 );
		}

		$context = stream_context_create( array( 'http' => array( 'timeout' => $timeout ) ) );
		if ( version_compare( PHP_VERSION, '8.0.0', '>=' ) ) {
			$headers = @get_headers( $url, true, $context );
		} else {
			$headers = @get_headers( $url, 1, $context );
		}
		$headers = is_array( $headers ) ? $headers : array();

		return array_change_key_case( $headers );
	}

	/**
	 * Fetches the "Content-Type" header for a given URL
	 *
	 * @param string $url The URL.
	 * @return string The value of the "Content-Type" header, or an empty string if the header is not present.
	 */
	public static function getContentType( string $url ): string {
		$headers = static::getHeaders( $url );
		$contentTypeList = (array) ( $headers['content-type'] ?? array() );
		$contentType = reset( $contentTypeList ) ?: '';

		return trim( $contentType );
	}

	/**
	 * Checks if the "Content-Type" header for a given URL is a given type, where type is the left side of the slash.
	 *
	 * @param string $url The URL.
	 * @param string $type The type. E.g.: "image", "audio", "video", "text", "multipart", "application", etc.
	 * @return bool True if the content type matches "$type/*", false otherwise.
	 */
	public static function isContentTypeIn( string $url, string $type ): bool {
		return stripos( self::getContentType( $url ), $type . '/' ) === 0;
	}
}
