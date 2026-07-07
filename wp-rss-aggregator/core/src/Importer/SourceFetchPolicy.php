<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Importer;

/**
 * Source-specific fetch policy resolver.
 *
 * Rules are intentionally data-driven so modules and integrations can add host
 * specific behaviour without changing the importer or RSS reader.
 *
 * @since 5.2.1
 */
class SourceFetchPolicy {

	/** @var array<int,array<string,mixed>> */
	protected array $rules;

	/**
	 * Constructor.
	 *
	 * @since 5.2.1
	 *
	 * @param array<int,array<string,mixed>> $rules Source fetch rules.
	 */
	public function __construct( array $rules = array() ) {
		$this->rules = $rules;
	}

	/**
	 * Gets the cURL options for a URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $url The source URL.
	 * @return array<int,mixed>
	 */
	public function getCurlOptions( string $url ): array {
		$options = array();

		foreach ( $this->getMatchingRules( $url ) as $rule ) {
			$ruleOptions = $rule['curlOptions'] ?? array();
			if ( is_array( $ruleOptions ) ) {
				$options = $this->mergeCurlOptions( $options, $ruleOptions );
			}
		}

		return $options;
	}

	/**
	 * Gets request headers for a URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $url The source URL.
	 * @return array<string,string>
	 */
	public function getRequestHeaders( string $url ): array {
		$headers = array();

		foreach ( $this->getMatchingRules( $url ) as $rule ) {
			$ruleHeaders = $rule['requestHeaders'] ?? array();
			if ( ! is_array( $ruleHeaders ) ) {
				continue;
			}

			foreach ( $ruleHeaders as $name => $value ) {
				if ( is_string( $name ) && is_scalar( $value ) ) {
					$headers[ $name ] = (string) $value;
				}
			}
		}

		return $headers;
	}

	/**
	 * Gets the preview cache TTL for a URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $url The source URL.
	 * @return int|null The TTL in seconds, or null when preview caching is disabled.
	 */
	public function getPreviewCacheTtl( string $url ): ?int {
		foreach ( $this->getMatchingRules( $url ) as $rule ) {
			$ttl = $rule['previewCacheTtl'] ?? null;
			if ( is_numeric( $ttl ) && (int) $ttl > 0 ) {
				return (int) $ttl;
			}
		}

		return null;
	}

	/**
	 * Checks if feed discovery should be skipped for a URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $url The source URL.
	 * @return bool True if discovery should be skipped.
	 */
	public function shouldSkipFeedDiscovery( string $url ): bool {
		foreach ( $this->getMatchingRules( $url ) as $rule ) {
			if ( ! empty( $rule['skipFeedDiscovery'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets the frontend-safe policy config.
	 *
	 * @since 5.2.1
	 *
	 * @return array<string,mixed>
	 */
	public function getClientConfig(): array {
		$previewCacheRules = array();

		foreach ( $this->rules as $rule ) {
			$ttl = $rule['previewCacheTtl'] ?? null;
			if ( ! is_numeric( $ttl ) || (int) $ttl <= 0 ) {
				continue;
			}

			$hosts = $rule['hosts'] ?? array();
			if ( is_string( $hosts ) ) {
				$hosts = array( $hosts );
			}

			if ( ! is_array( $hosts ) ) {
				continue;
			}

			$hosts = array_values( array_filter( $hosts, 'is_string' ) );
			if ( empty( $hosts ) ) {
				continue;
			}

			$previewCacheRules[] = array(
				'hosts' => $hosts,
				'ttl' => (int) $ttl,
			);
		}

		return array(
			'previewCacheRules' => $previewCacheRules,
		);
	}

	/**
	 * Gets all rules that match a URL.
	 *
	 * @since 5.2.1
	 *
	 * @param string $url The source URL.
	 * @return array<int,array<string,mixed>>
	 */
	protected function getMatchingRules( string $url ): array {
		$host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
		if ( $host === '' ) {
			return array();
		}

		$matches = array();
		foreach ( $this->rules as $rule ) {
			$hosts = $rule['hosts'] ?? array();
			if ( is_string( $hosts ) ) {
				$hosts = array( $hosts );
			}

			if ( ! is_array( $hosts ) ) {
				continue;
			}

			foreach ( $hosts as $pattern ) {
				if ( is_string( $pattern ) && $this->hostMatches( $host, strtolower( $pattern ) ) ) {
					$matches[] = $rule;
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Checks if a host matches a host rule pattern.
	 *
	 * @since 5.2.1
	 *
	 * @param string $host The normalized host.
	 * @param string $pattern The normalized host pattern.
	 * @return bool True if the host matches.
	 */
	protected function hostMatches( string $host, string $pattern ): bool {
		if ( $host === $pattern ) {
			return true;
		}

		if ( substr( $pattern, 0, 2 ) === '*.' ) {
			$suffix = substr( $pattern, 1 );
			return substr( $host, -strlen( $suffix ) ) === $suffix;
		}

		return false;
	}

	/**
	 * Merges cURL options from a matching rule.
	 *
	 * @since 5.2.1
	 *
	 * @param array<int,mixed> $options Existing cURL options.
	 * @param array<int,mixed> $incoming Incoming cURL options.
	 * @return array<int,mixed>
	 */
	protected function mergeCurlOptions( array $options, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( isset( $options[ $key ] ) && is_array( $options[ $key ] ) && is_array( $value ) ) {
				$options[ $key ] = array_values( array_merge( $options[ $key ], $value ) );
			} else {
				$options[ $key ] = $value;
			}
		}

		return $options;
	}
}
