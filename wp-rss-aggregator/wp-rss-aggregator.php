<?php

/**
 * @wordpress-plugin
 *
 * Plugin Name:       WP RSS Aggregator
 * Plugin URI:        https://wprssaggregator.com
 * Description:       An RSS importer, aggregator, and auto-blogger plugin for WordPress.
 * Version:           5.0.3
 * Requires at least: 5.5
 * Requires PHP:      7.4.0
 * Author:            RebelCode
 * Author URI:        https://rebelcode.com
 * Text Domain:       wp-rss-aggregator
 * Domain Path:       /languages
 * License:           GPL-3.0
 */

use RebelCode\Aggregator\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPRA_V5_USE_V4' ) ) {
	define( 'WPRA_V5_USE_V4', get_option( 'wprss_prev_update_page_version', false ) && get_option( 'wprss_enable_v5', '0' ) !== '1' );
}

if ( WPRA_V5_USE_V4 ) {
	define( 'WPRSS_FILE_CONSTANT', __FILE__ );
	define( 'WPRSS_DIR', __DIR__ . '/v4/' );
	define( 'WPRSS_URI', plugin_dir_url( __FILE__ ) . '/v4/' );
	add_filter( 'wpra/core/plugin_dir_path', fn () => __DIR__ . '/v4/' );
	if ( file_exists( __DIR__ . '/v4.php' ) ) {
		require __DIR__ . '/v4.php';
	}
	return;
}

if ( ! defined( 'WPRA_VERSION' ) ) {
	define( 'WPRA_VERSION', '5.0.3' );
	define( 'WPRA_MIN_PHP_VERSION', '7.4.0' );
	define( 'WPRA_MIN_WP_VERSION', '5.5' );
	define( 'WPRA_FILE', __FILE__ );
	define( 'WPRA_DIR', __DIR__ );
	define( 'WPRA_URL', plugin_dir_url( __FILE__ ) );
	define( 'WPRA_BASENAME', plugin_basename( __FILE__ ) );
}

add_action(
	'init',
	function () {
		$directory = dirname( WPRA_BASENAME ) . '/languages';
		load_plugin_textdomain( 'wp-rss-aggregator', false, $directory );
	}
);

if ( version_compare( PHP_VERSION, WPRA_MIN_PHP_VERSION, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					_x( '%1$s requires PHP version %2$s or higher.', '%s = plugin name', 'wp-rss-aggregator' ),
					'<b>WP RSS Aggregator</b>',
					'<code>' . WPRA_MIN_PHP_VERSION . '</code>',
				)
			);
		}
	);
	return;
}

global $wp_version;
if ( version_compare( $wp_version, WPRA_MIN_WP_VERSION, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					_x( '%1$s requires WordPress version %2$s or higher.', '%s = plugin name', 'wp-rss-aggregator' ),
					'<b>WP RSS Aggregator</b>',
					'<code>' . WPRA_MIN_WP_VERSION . '</code>',
				)
			);
		}
	);
	return;
}

register_activation_hook(
	__FILE__,
	function () {
		do_action( 'wpra.activate' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		do_action( 'wpra.deactivate' );
	}
);

require_once __DIR__ . '/vendor/autoload.php';

if ( ! WPRA_V5_USE_V4 ) {
	function wpra(): Plugin {
		static $instance = null;
		return $instance ??= new Plugin( WPRA_FILE, WPRA_VERSION );
	}
}

if ( file_exists( __DIR__ . '/dev/loader.php' ) ) {
	require_once __DIR__ . '/dev/loader.php';
}

require_once __DIR__ . '/core/core.php';
