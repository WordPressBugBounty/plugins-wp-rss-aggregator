<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\V4;

use RebelCode\Aggregator\Core\Settings;
use RebelCode\Aggregator\Core\Logger;

class V4SettingsMigrator {

	private Settings $settings;
	private array $coreSettings;

	/** @param array<string,mixed> $v4Settings */
	public function __construct( Settings $settings, array $v4Settings ) {
		$this->settings = $settings;
		$this->coreSettings = $v4Settings;
	}

	public function migrate( bool $dryRun = false ): Settings {
		try {
			$patch = $this->getSettingsPatch();
			$this->settings->patch( $patch );

			if ( ! $dryRun ) {
				$this->settings->save();
			}
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error during V4 settings migration: %s', $e->getMessage() ) );
		}
		return $this->settings;
	}

	public function getSettingsPatch(): array {
		try {
			$patch = $this->convertCoreSettings();
			$patch = apply_filters( 'wpra.v4Migration.settings.patch', $patch );
		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Error generating V4 settings patch: %s', $e->getMessage() ) );
			return array(); // Return empty patch on error
		}
		return $patch;
	}

	/** Creates a patch to apply to the v5 settings to migrate the v4 core settings. */
	public function convertCoreSettings(): array {
		$core = $this->coreSettings;

		$core['open_dd'] ??= '';
		if ( $core['open_dd'] === 'New window' || $core['open_dd'] === __( 'New window', 'wprss' ) ) {
			$core['open_dd'] = 'blank';
		} elseif ( $core['open_dd'] === 'Lightbox' || $core['open_dd'] === __( 'Lightbox', 'wprss' ) ) {
			$core['open_dd'] = 'lightbox';
		} elseif ( $core['open_dd'] === 'Self' || $core['open_dd'] === __( 'Self', 'wprss' ) ) {
			$core['open_dd'] = 'self';
		}

		$patch = array();
		if ( array_key_exists( 'styles_disable', $core ) ) {
			$patch['disableStyles'] = (bool) $core['styles_disable'];
		}
		if ( array_key_exists( 'custom_feed_url', $core ) ) {
			$patch['mergedFeedUrl'] = (string) $core['custom_feed_url'];
		}
		if ( array_key_exists( 'custom_feed_title', $core ) ) {
			$patch['mergedFeedTitle'] = (string) $core['custom_feed_title'];
		}
		if ( array_key_exists( 'custom_feed_limit', $core ) ) {
			$patch['mergedFeedNumItems'] = (int) ( $core['custom_feed_limit'] ?: 0 );
		}
		if ( array_key_exists( 'certificate-path', $core ) ) {
			$patch['sslCertPath'] = $core['certificate-path'];
		}
		if ( array_key_exists( 'feed_request_useragent', $core ) ) {
			$patch['feedUserAgent'] = $core['feed_request_useragent'];
		}
		if ( array_key_exists( 'feed_cache_enabled', $core ) ) {
			$patch['enableFeedCache'] = $core['feed_cache_enabled'];
		}
		if ( array_key_exists( 'keep_edited_posts', $core ) ) {
			$patch['protectEditedPosts'] = $core['keep_edited_posts'];
		}

		return $patch;
	}
}
