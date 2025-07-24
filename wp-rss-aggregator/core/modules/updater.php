<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\WpSdk\Wp\CronJob;

wpra()->addModule(
	'updater',
	array( 'importer' ),
	function ( Importer $importer ) {
		$schedule = apply_filters( 'wpra.updater.schedule.name', 'wpra_update_schedule' );
		$interval = apply_filters( 'wpra.updater.schedule.interval', 60 );

		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				return array_merge(
					$schedules,
					array(
						'fifteen_min' => array(
							'interval' => 15 * 60,
							'display'  => __( 'Every 15 Minutes' ),
						),
						'thirty_min' => array(
							'interval' => 30 * 60,
							'display'  => __( 'Every 30 Minutes' ),
						),
						'two_hours' => array(
							'interval' => 2 * HOUR_IN_SECONDS,
							'display'  => __( 'Every 2 Hours' ),
						),
					)
				);
			}
		);

		add_filter(
			'cron_schedules',
			function ( array $schedules ) use ( $schedule, $interval ) {
				$schedules[ $schedule ] = array(
					'interval' => $interval,
					'display' => __( 'Aggregator\'s schedule', 'wp-rss-aggregator' ),
				);
				return $schedules;
			}
		);

		$cron = new CronJob(
			'wpra.update',
			array(),
			$schedule,
			array(
				function () use ( $importer ) {
					$res = $importer->importPending();
					if ( $res->isErr() ) {
						Logger::error( $res->error() );
					}
				},
			)
		);

		$cron->ensureScheduled();
		$cron->registerHandlers();

		return $cron;
	}
);
