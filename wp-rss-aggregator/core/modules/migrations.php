<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Upgrade\RejectListGuidHashUpgrade;
use RebelCode\Aggregator\Core\Upgrade\UpgradeRunner;
use RebelCode\Aggregator\Core\Upgrade\UpgradeStep;

wpra()->addModule(
	'migrations',
	array( 'db', 'importer' ),
	function ( Database $db, Importer $importer ) {
		add_action(
			'init',
			function () use ( $db, $importer ) {
				$wpra = wpra();
				if ( $wpra->getState() !== State::Normal ) {
					return;
				}

				$curr = $wpra->version;
				$prev = get_option( 'wpra_version', '0.0.0' );

				if ( $prev === '0.0.0' ) {
					do_action( 'wpra.install', $curr );
					do_action( "wpra.install_$curr" );
					update_option( 'wpra_version', $curr, true );
					return;
				}

				if ( version_compare( $prev, $curr, '<' ) ) {
					$runner = new UpgradeRunner(
						array(
							new UpgradeStep(
								'5.4.0/reject-list-guid-hash',
								'5.4.0',
								function () use ( $db, $importer ): void {
									$upgrade = new RejectListGuidHashUpgrade( $db, $db->tableName( 'reject_list' ), $importer->rejectList );
									$upgrade->run();
								}
							),
						)
					);

					$result = $runner->run( $prev, $curr );
					if ( $result->isErr() ) {
						Logger::error( $result->error() );
						return;
					}

					do_action( 'wpra.upgrade', $prev, $curr );
					do_action( "wpra.upgrade.to_$curr", $prev );
					do_action( "wpra.upgrade.from_$prev" );
				}

				if ( version_compare( $prev, $curr, '>' ) ) {
					do_action( 'wpra.downgrade', $prev, $curr );
					do_action( "wpra.downgrade.to_$curr", $prev );
					do_action( "wpra.downgrade.from_$prev" );
				}

				update_option( 'wpra_version', $curr, true );
			}
		);
	}
);
