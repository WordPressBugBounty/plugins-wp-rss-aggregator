<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'migrations',
	array(),
	function () {
		add_action(
			'init',
			function () {
				$wpra = wpra();
				if ( $wpra->getState() !== State::Normal ) {
					return;
				}

				$curr = $wpra->version;
				$prev = get_option( 'wpra_version', '0.0.0' );

				if ( version_compare( $prev, $curr, '<' ) ) {
					do_action( 'wpra.upgrade', $prev, $curr );
					do_action( "wpra.upgrade.to_$curr", $prev );
					do_action( "wpra.upgrade.from_$prev" );
				}

				if ( version_compare( $prev, $curr, '>' ) ) {
					do_action( 'wpra.downgrade', $prev, $curr );
					do_action( "wpra.downgrade.to_$curr", $prev );
					do_action( "wpra.downgrade.from_$prev" );
				}

				if ( $prev === '0.0.0' ) {
					do_action( 'wpra.install', $curr );
					do_action( "wpra.install_$curr" );
				}

				update_option( 'wpra_version', $curr, true );
			}
		);
	}
);
