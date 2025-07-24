<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'debugInfo',
	array( 'licensing' ),
	function ( Licensing $licensing ) {
		add_filter(
			'debug_information',
			function ( array $info ) use ( $licensing ) {
				$wpra = wpra();

				$wpraInfo = array(
					'label' => __( 'WP RSS Aggregator', 'wp-rss-aggregator' ),
					'private' => false,
					'fields' => array(
						'version' => array(
							'label' => __( 'Version', 'wp-rss-aggregator' ),
							'value' => $wpra->version,
						),
						'version' => array(
							'label' => __( 'Plan', 'wp-rss-aggregator' ),
							'value' => Tier::getName( $licensing->getTier() ),
						),
						'state' => array(
							'label' => __( 'State', 'wp-rss-aggregator' ),
							'value' => $wpra->getState(),
						),
						'fsockopen' => array(
							'label' => __( 'fsockopen', 'wp-rss-aggregator' ),
							'value' => function_exists( 'fsockopen' )
								? _x( 'Supported', 'fsockopen status in Site Health Info', 'wp-rss-aggregator' )
								: _x( 'Unsupported', 'fsockopen status in Site Health Info', 'wp-rss-aggregator' ),
						),
					),
				);

				foreach ( array( 'libxml', 'SimpleXML', 'json', 'dom', 'SPL' ) as $ext ) {
					$wpraInfo['fields'][ $ext . '-ext' ] = array(
						'label' => sprintf( _x( '%s extension', 'Label for extension in Site Health Info', 'wp-rss-aggregator' ), $ext ),
						'value' => extension_loaded( $ext )
							? __( 'Yes', 'wp-rss-aggregator' )
							: __( 'No', 'wp-rss-aggregator' ),
					);
				}

				$info['wp-rss-aggregator'] = $wpraInfo;

				return $info;
			}
		);
	}
);
