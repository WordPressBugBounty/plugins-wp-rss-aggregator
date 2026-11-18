<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use Exception;
use Plugin_Upgrader;
use RebelCode\Aggregator\Core\RecommendedPlugins;
use WP_Ajax_Upgrader_Skin;
use WP_Filesystem_Base;

/**
 * RPC actions for the Toolkit page, which recommends other RebelCode plugins.
 *
 * Install and activate use the same WordPress APIs as the core plugin installer,
 * so the user stays inside Aggregator.
 *
 * @since 5.8.0
 */
class RpcRecommendedPluginsHandler {

	protected RecommendedPlugins $plugins;
	protected string $iconBaseUrl;

	/**
	 * @since 5.8.0
	 *
	 * @param RecommendedPlugins $plugins     The recommended plugins service.
	 * @param string             $iconBaseUrl The URL of the directory that holds the bundled plugin icons.
	 */
	public function __construct( RecommendedPlugins $plugins, string $iconBaseUrl ) {
		$this->plugins = $plugins;
		$this->iconBaseUrl = trailingslashit( $iconBaseUrl );
	}

	/**
	 * Gets the recommended plugins for the current user, ready for the admin app.
	 *
	 * @since 5.8.0
	 *
	 * @return list<array<string,mixed>> An empty list when the user can't install plugins.
	 */
	public function getAll(): array {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'toClient' ), $this->plugins->getForSite() ) );
	}

	/**
	 * Gets the average WordPress.org star rating of each recommended plugin.
	 *
	 * The Toolkit page asks for these separately, so the cards don't wait on WordPress.org.
	 *
	 * @since 5.8.0
	 *
	 * @return array<string,float|null> The stars per slug. Empty when the user can't install plugins.
	 */
	public function getRatings(): array {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return array();
		}

		return $this->plugins->getRatings();
	}

	/**
	 * Installs a recommended plugin from WordPress.org.
	 *
	 * @since 5.8.0
	 *
	 * @param string $slug The plugin slug.
	 * @return array<string,mixed> The plugin, with its new status.
	 * @throws Exception If the user isn't allowed, or WordPress couldn't install it.
	 */
	public function install( string $slug ): array {
		$slug = $this->checkSlug( $slug );

		if ( ! current_user_can( 'install_plugins' ) ) {
			throw new Exception( esc_html__( 'Sorry, you are not allowed to install plugins on this site.', 'wp-rss-aggregator' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug' => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) ) {
			throw new Exception( esc_html( $api->get_error_message() ) );
		}

		$skin = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}

		if ( is_wp_error( $skin->result ) ) {
			throw new Exception( esc_html( $skin->result->get_error_message() ) );
		}

		if ( $skin->get_errors()->has_errors() ) {
			throw new Exception( esc_html( $skin->get_error_messages() ) );
		}

		if ( $result === null ) {
			global $wp_filesystem;

			$message = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'wp-rss-aggregator' );
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				$message = $wp_filesystem->errors->get_error_message();
			}

			throw new Exception( esc_html( $message ) );
		}

		return $this->getOne( $slug );
	}

	/**
	 * Activates an installed recommended plugin on the current site.
	 *
	 * @since 5.8.0
	 *
	 * @param string $slug The plugin slug.
	 * @return array<string,mixed> The plugin, with its new status.
	 * @throws Exception If the plugin isn't installed, the user isn't allowed, or activation failed.
	 */
	public function activate( string $slug ): array {
		$slug = $this->checkSlug( $slug );
		$plugin = $this->plugins->getForSite()[ $slug ] ?? null;

		if ( $plugin === null || $plugin['file'] === '' ) {
			throw new Exception( esc_html__( 'This plugin is not installed.', 'wp-rss-aggregator' ) );
		}

		if ( ! current_user_can( 'activate_plugin', $plugin['file'] ) ) {
			throw new Exception( esc_html__( 'Sorry, you are not allowed to activate this plugin.', 'wp-rss-aggregator' ) );
		}

		if ( $plugin['status'] !== RecommendedPlugins::ACTIVE ) {
			$result = activate_plugin( $plugin['file'] );

			if ( is_wp_error( $result ) ) {
				throw new Exception( esc_html( $result->get_error_message() ) );
			}
		}

		return $this->getOne( $slug );
	}

	/**
	 * Sanitizes a slug and checks that it's on the bundled list.
	 *
	 * @param string $slug The raw slug.
	 * @return string The sanitized slug.
	 * @throws Exception If the slug isn't a recommended plugin.
	 */
	protected function checkSlug( string $slug ): string {
		$slug = sanitize_key( $slug );

		if ( ! $this->plugins->has( $slug ) ) {
			throw new Exception( esc_html__( 'Unknown plugin.', 'wp-rss-aggregator' ) );
		}

		return $slug;
	}

	/**
	 * Gets one plugin, ready for the admin app.
	 *
	 * @param string $slug The plugin slug.
	 * @return array<string,mixed>
	 * @throws Exception If the plugin isn't recommended on this site.
	 */
	protected function getOne( string $slug ): array {
		$plugin = $this->plugins->getForSite()[ $slug ] ?? null;

		if ( $plugin === null ) {
			throw new Exception( esc_html__( 'Unknown plugin.', 'wp-rss-aggregator' ) );
		}

		return $this->toClient( $plugin );
	}

	/**
	 * Converts a plugin entry into the shape the admin app reads.
	 *
	 * Install and activate links are the core fallbacks, for when the RPC call can't finish,
	 * for example when WordPress needs FTP credentials.
	 *
	 * @param array<string,mixed> $plugin A plugin from {@see RecommendedPlugins::getRecommended()}.
	 * @return array<string,mixed>
	 */
	protected function toClient( array $plugin ): array {
		$slug = (string) $plugin['slug'];
		$file = (string) $plugin['file'];
		$installerUrl = is_multisite() ? network_admin_url( 'update.php' ) : self_admin_url( 'update.php' );

		return array(
			'slug' => $slug,
			'name' => (string) $plugin['name'],
			'tagline' => (string) $plugin['tagline'],
			'description' => (string) $plugin['description'],
			'iconUrl' => $this->iconBaseUrl . $plugin['icon'],
			'status' => (string) $plugin['status'],
			'canActivate' => $file !== '' && current_user_can( 'activate_plugin', $file ),
			'detailsUrl' => 'https://wordpress.org/plugins/' . rawurlencode( $slug ) . '/',
			// Raw URLs, not wp_nonce_url(): that one HTML-escapes "&", which breaks the link once React sets it as an href.
			'installUrl' => add_query_arg(
				array(
					'action' => 'install-plugin',
					'plugin' => rawurlencode( $slug ),
					'_wpnonce' => wp_create_nonce( 'install-plugin_' . $slug ),
				),
				$installerUrl
			),
			'activateUrl' => $file === '' ? '' : add_query_arg(
				array(
					'action' => 'activate',
					'plugin' => rawurlencode( $file ),
					'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $file ),
				),
				self_admin_url( 'plugins.php' )
			),
		);
	}
}
