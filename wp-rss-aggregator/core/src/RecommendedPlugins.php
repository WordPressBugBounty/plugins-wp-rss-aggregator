<?php

namespace RebelCode\Aggregator\Core;

/**
 * Recommends other free RebelCode plugins from inside Aggregator.
 *
 * The list is bundled with the plugin. Nothing is fetched from a RebelCode server.
 *
 * @since 5.8.0
 */
class RecommendedPlugins {

	public const SELF_SLUG = 'wp-rss-aggregator';

	public const NOT_INSTALLED = 'not-installed';
	public const INSTALLED = 'installed';
	public const ACTIVE = 'active';

	public const RATINGS_TRANSIENT = 'wpra_recommended_plugin_ratings';

	/**
	 * Gets the bundled list of RebelCode plugins.
	 *
	 * - `dirs`: plugin directories that count as "this plugin is installed" (free and premium builds).
	 * - `pairsWith`: plugin slugs this plugin is recommended alongside.
	 * - `tagline`: the one-line pitch shown under the plugin name on the Toolkit page.
	 *
	 * @since 5.8.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getList(): array {
		return array(
			'vireo-analytics' => array(
				'slug' => 'vireo-analytics',
				'name' => 'Vireo Analytics',
				'tagline' => __( 'Track your aggregated content alongside the rest of your site.', 'wp-rss-aggregator' ),
				'description' => __( 'See which feed sources send the most readers to the original articles. Vireo tracks those clicks without cookies and stores the data in your own WordPress database.', 'wp-rss-aggregator' ),
				'icon' => 'vireo-analytics.png',
				// Vireo Analytics Pro is an add-on that requires this free plugin, so only the free folder counts.
				'dirs' => array( 'vireo-analytics' ),
				'requiresWp' => '6.9',
				'requiresPhp' => '7.4',
				'pairsWith' => array( 'wp-rss-aggregator', 'spotlight-social-photo-feeds' ),
			),
			'spotlight-social-photo-feeds' => array(
				'slug' => 'spotlight-social-photo-feeds',
				'name' => 'Spotlight Social Photo Feed',
				'tagline' => __( 'Put your Instagram photos and videos next to your curated feeds.', 'wp-rss-aggregator' ),
				'description' => __( 'Give readers something fresh to browse alongside your aggregated content. Spotlight brings your latest Instagram posts to your site and automatically keeps the feed up to date.', 'wp-rss-aggregator' ),
				'icon' => 'spotlight-social-photo-feeds.png',
				'dirs' => array( 'spotlight-social-photo-feeds', 'spotlight-social-photo-feeds-premium' ),
				'requiresWp' => '6.3',
				'requiresPhp' => '7.1',
				'pairsWith' => array( 'wp-rss-aggregator', 'vireo-analytics' ),
			),
		);
	}

	/**
	 * Checks whether a slug belongs to the bundled list.
	 *
	 * @since 5.8.0
	 *
	 * @param string $slug The plugin slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return $slug !== self::SELF_SLUG && array_key_exists( $slug, $this->getList() );
	}

	/**
	 * Gets the active plugin files, including network-activated plugins on multisite.
	 *
	 * @since 5.8.0
	 *
	 * @return string[]
	 */
	public function getActiveFiles(): array {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return $active;
	}

	/**
	 * Gets the plugins recommended for this plugin, with their install status.
	 *
	 * @since 5.8.0
	 *
	 * @param array<string,array<string,string>> $installed  Installed plugins, as returned by `get_plugins()`.
	 * @param string[]                           $active     Active plugin files.
	 * @param string                             $wpVersion  The site's WordPress version.
	 * @param string                             $phpVersion The server's PHP version.
	 * @return array<string,array<string,mixed>>
	 */
	public function getRecommended( array $installed, array $active, string $wpVersion, string $phpVersion ): array {
		$result = array();

		foreach ( $this->getList() as $slug => $plugin ) {
			if ( $slug === self::SELF_SLUG || ! in_array( self::SELF_SLUG, $plugin['pairsWith'], true ) ) {
				continue;
			}

			$plugin['file'] = '';
			$plugin['status'] = self::NOT_INSTALLED;

			foreach ( array_keys( $installed ) as $file ) {
				if ( ! in_array( dirname( $file ), $plugin['dirs'], true ) ) {
					continue;
				}

				$isActive = in_array( $file, $active, true );
				if ( $isActive || $plugin['status'] === self::NOT_INSTALLED ) {
					$plugin['file'] = $file;
					$plugin['status'] = $isActive ? self::ACTIVE : self::INSTALLED;
				}
			}

			$isCompatible = version_compare( $wpVersion, $plugin['requiresWp'], '>=' )
				&& version_compare( $phpVersion, $plugin['requiresPhp'], '>=' );

			if ( $plugin['status'] === self::NOT_INSTALLED && ! $isCompatible ) {
				continue;
			}

			$result[ $slug ] = $plugin;
		}

		return $result;
	}

	/**
	 * Gets the recommended plugins for the current site.
	 *
	 * @since 5.8.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getForSite(): array {
		return $this->getRecommended( get_plugins(), $this->getActiveFiles(), get_bloginfo( 'version' ), PHP_VERSION );
	}

	/**
	 * Converts a WordPress.org rating to an average star rating.
	 *
	 * WordPress.org reports the rating as a percentage, where 100 is five stars.
	 *
	 * @since 5.8.0
	 *
	 * @param mixed $percent    The `rating` field from the plugin information API.
	 * @param mixed $numRatings The `num_ratings` field from the plugin information API.
	 * @return float|null The stars to one decimal place, or null when the plugin has no ratings.
	 */
	public function toStars( $percent, $numRatings ): ?float {
		if ( ! is_numeric( $percent ) || (int) $numRatings < 1 ) {
			return null;
		}

		return round( max( 0, min( 100, (float) $percent ) ) / 20, 1 );
	}

	/**
	 * Gets the average WordPress.org star rating of each recommended plugin.
	 *
	 * The ratings are cached for a day. A lookup that fails is cached for an hour, so a site
	 * that can't reach WordPress.org doesn't wait on it every time the Toolkit page opens.
	 *
	 * @since 5.8.0
	 *
	 * @return array<string,float|null> The stars per slug, or null when the rating isn't available.
	 */
	public function getRatings(): array {
		$cached = get_transient( self::RATINGS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$ratings = array();
		$failed = false;

		foreach ( array_keys( $this->getList() ) as $slug ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug' => $slug,
					'fields' => array(
						'sections' => false,
						'description' => false,
						'versions' => false,
						'banners' => false,
						'icons' => false,
						'screenshots' => false,
						'tags' => false,
						'contributors' => false,
						'rating' => true,
						'num_ratings' => true,
					),
				)
			);

			if ( is_wp_error( $api ) || ! is_object( $api ) ) {
				$ratings[ $slug ] = null;
				$failed = true;
				continue;
			}

			$ratings[ $slug ] = $this->toStars( $api->rating ?? null, $api->num_ratings ?? 0 );
		}

		set_transient( self::RATINGS_TRANSIENT, $ratings, $failed ? HOUR_IN_SECONDS : DAY_IN_SECONDS );

		return $ratings;
	}
}
