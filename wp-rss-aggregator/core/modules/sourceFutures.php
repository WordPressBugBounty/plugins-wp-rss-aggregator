<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\WpSdk\Wp\CronJob;

wpra()->addModule(
	'sourceFutures',
	array( 'importer' ),
	function ( Importer $importer ) {
		add_action(
			'wpra.source.future.activate',
			function ( $id ) use ( $importer ) {
				$id = (int) ( $id ?? 0 );
				if ( ! $id ) {
					return;
				}

				updateSrcState( $id, true, $importer );
			}
		);

		add_action(
			'wpra.source.future.pause',
			function ( $id ) use ( $importer ) {
				$id = (int) ( $id ?? 0 );
				if ( ! $id ) {
					return;
				}

				updateSrcState( $id, false, $importer );
			}
		);

		add_action(
			'wpra.source.store.inserted',
			function ( Source $src ) {
				updateSrcFutures( $src );
			}
		);

		add_action(
			'wpra.sources.store.updated',
			function ( $_set, $where, $num, $order ) use ( $importer ) {
				$res = $importer->sources->query( $where, $num, 1, $order );
				if ( $res->isErr() ) {
					Logger::error( $res );
				}
				$srcs = $res->get();
				foreach ( $srcs as $src ) {
					updateSrcFutures( $src );
				}
			},
			10,
			4
		);
	}
);

function updateSrcFutures( Source $src ) {
	$fActivate = $src->settings->futureActivate;
	$fPause = $src->settings->futurePause;

	$activateCron = new CronJob( 'wpra.source.future.activate', array( $src->id ) );
	$pauseCron = new CronJob( 'wpra.source.future.pause', array( $src->id ) );

	$activateCron->unschedule();
	if ( $fActivate !== null ) {
		$activateCron->schedule( $fActivate->getTimestamp() );
	}

	$pauseCron->unschedule();
	if ( $fPause !== null ) {
		$pauseCron->schedule( $fPause->getTimestamp() );
	}
}

function updateSrcState( int $id, bool $active, Importer $importer ) {
	$res = $importer->sources->getById( $id );
	if ( $res->isErr() ) {
		Logger::error( $res->error() );
		return;
	}

	$src = $res->get();
	$src->isActive = $active;
	$src->settings->futureActivate = null;
	$src->settings->futurePause = null;

	$res = $importer->sources->save( $src );
	if ( $res->isErr() ) {
		if ( $active ) {
			Logger::error( 'Failed to activate feed source. ' . $res->error()->getMessage() );
		} else {
			Logger::error( 'Failed to pause feed source. ' . $res->error()->getMessage() );
		}
	}
}
