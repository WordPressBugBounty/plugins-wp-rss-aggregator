<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'plugin',
	array(),
	function () {
		$wpra = wpra();

		add_action(
			'init',
			function () use ( $wpra ) {
				$state = $wpra->getState();

				if ( $state === State::Onboarding ) {
					$didOb = filter_input( INPUT_GET, 'wpraDidOnboarding', FILTER_DEFAULT );

					if ( $didOb === 'finished' || $didOb === 'cancelled' ) {
						update_option( 'wpra_version', $wpra->version );
						wp_redirect( admin_url( 'admin.php?page=wprss-aggregator&subPage=hub' ) );
						exit;
					}
				}

				if ( $state === State::V4Migration ) {
					$didMigration = filter_input( INPUT_GET, 'wpraDidV4Migration', FILTER_DEFAULT );
					if ( $didMigration === 'finished' || $didMigration === 'cancelled' ) {
						update_option( 'wpra_did_v4_migration', $didMigration );
						update_option( 'wpra_version', $wpra->version );
						wp_redirect( admin_url( 'admin.php?page=wprss-aggregator&subPage=hub' ) );
						exit;
					}
				}
			}
		);

		add_action(
			'admin_init',
			function () {
				$transient = get_transient( 'wprss_redirect_to_v5' );
				if ( $transient === '1' ) {
					delete_transient( 'wprss_redirect_to_v5' );
					wp_redirect( admin_url( 'admin.php?page=wprss-aggregator' ) );
					exit;
				}
			}
		);

		return $wpra;
	}
);
