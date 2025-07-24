<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

class Uri {

	public static function isAbsolute( string $uri, string $protocol = '\w+' ): bool {
		$pattern = sprintf( '/^%s?:\/\//', $protocol );
		return preg_match( $pattern, $uri ) === 1;
	}

	/**
	 * @param array<string,mixed> $parsed A URL parsed with `parse_url`.
	 */
	public static function build( array $parsed ): string {
		$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
		$host     = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$user     = isset( $parsed['user'] ) ? $parsed['user'] : '';
		$pass     = isset( $parsed['pass'] ) ? ':' . $parsed['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
	}

	/**
	 * @param callable(array $query):array $fn A function that takes and returns
	 *        an associative array of query args.
	 */
	public static function modifyQuery( string $uri, callable $fn ): string {
		$parsedUrl = parse_url( $uri );
		$queryStr = $parsedUrl['query'] ?? '';

		if ( ! is_string( $queryStr ) ) {
			return $uri;
		}

		parse_str( $queryStr, $query );

		if ( ! is_array( $query ) ) {
			return $uri;
		}

		$query = call_user_func( $fn, $query );

		if ( count( $query ) === 0 ) {
			unset( $parsedUrl['query'] );
		} else {
			$parsedUrl['query'] = http_build_query( $query );
		}

		return self::build( $parsedUrl );
	}
}
