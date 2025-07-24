<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Rpc\RpcServer;
use RebelCode\Aggregator\Core\Rpc\RpcClassHandler;
use RebelCode\Aggregator\Core\Rpc\Handlers\RpcLoggerHandler;
use RebelCode\Aggregator\Core\Logger\PostLogger;
use Psr\Log\LogLevel;

wpra()->addModule(
	'logger',
	array( 'db', 'settings', 'rpc' ),
	function ( Database $db, Settings $settings, RpcServer $rpc ) {
		$state = wpra()->getState();
		// Make logging enabled for migration only.
		if ( $state === State::V4Migration ) {
			$loggingEnabled = true;
		} else {
			$loggingEnabled = $settings->register( 'loggerEnabled' )->setDefault( false )->get();
		}

		$logger = new PostLogger( $db, $loggingEnabled );

		$rpcHandler = new RpcLoggerHandler( $logger, $settings );
		$rpc->addHandler( 'logger', new RpcClassHandler( $rpcHandler ) );

		Logger::setLevel( 9999 );
		Logger::push( $logger );

		add_action( 'init', __NAMESPACE__ . '\register_wpra_logger_post_type_and_statuses' );
		function register_wpra_logger_post_type_and_statuses() {
			register_post_type(
				'wpra-logger',
				array(
					'public'            => false,
					'show_ui'           => true,
					'show_in_menu'      => false,
					'capability_type'   => 'post',
					'capabilities'      => array(
						'create_posts' => 'do_not_allow',
					),
					'map_meta_cap'      => true,
					'supports'          => array( 'title', 'editor', 'custom-fields' ),
					'labels'            => array(
						'name'               => _x( 'Logs', 'post type general name', 'wp-rss-aggregator' ),
						'singular_name'      => _x( 'Log', 'post type singular name', 'wp-rss-aggregator' ),
						'menu_name'          => _x( 'Logs', 'admin menu', 'wp-rss-aggregator' ),
						'name_admin_bar'     => _x( 'Log', 'add new on admin bar', 'wp-rss-aggregator' ),
						'new_item'           => __( 'New Log', 'wp-rss-aggregator' ),
						'edit_item'          => __( 'Edit Log', 'wp-rss-aggregator' ),
						'view_item'          => __( 'View Log', 'wp-rss-aggregator' ),
						'all_items'          => __( 'All Logs', 'wp-rss-aggregator' ),
						'search_items'       => __( 'Search Logs', 'wp-rss-aggregator' ),
						'parent_item_colon'  => __( 'Parent Logs:', 'wp-rss-aggregator' ),
						'not_found'          => __( 'No logs found.', 'wp-rss-aggregator' ),
						'not_found_in_trash' => __( 'No logs found in Trash.', 'wp-rss-aggregator' ),
					),
				)
			);

			// Register log levels as post statuses
			$statuses = array(
				LogLevel::EMERGENCY => _x( 'Emergency', 'post status', 'wp-rss-aggregator' ),
				LogLevel::ALERT     => _x( 'Alert', 'post status', 'wp-rss-aggregator' ),
				LogLevel::CRITICAL  => _x( 'Critical', 'post status', 'wp-rss-aggregator' ),
				LogLevel::ERROR     => _x( 'Error', 'post status', 'wp-rss-aggregator' ),
				LogLevel::WARNING   => _x( 'Warning', 'post status', 'wp-rss-aggregator' ),
				LogLevel::NOTICE    => _x( 'Notice', 'post status', 'wp-rss-aggregator' ),
				LogLevel::INFO      => _x( 'Info', 'post status', 'wp-rss-aggregator' ),
				LogLevel::DEBUG     => _x( 'Debug', 'post status', 'wp-rss-aggregator' ),
			);

			foreach ( $statuses as $level => $label ) {
				$status_slug = 'wpra_' . $level;
				register_post_status(
					$status_slug,
					array(
						'label'                     => $label,
						'public'                    => false,
						'exclude_from_search'       => true,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
						'label_count'               => _n_noop(
							$label . ' <span class="count">(%s)</span>',
							$label . ' <span class="count">(%s)</span>',
							'wp-rss-aggregator'
						),
					)
				);
			}
		}
	}
);
