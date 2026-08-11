<?php

use RebelCode\Aggregator\Core\Uninstaller;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( 1 );
}

$cleanup_file = __DIR__ . '/core/src/DataCleanup.php';
$uninstall_file = __DIR__ . '/core/uninstaller.php';

if ( ! file_exists( $cleanup_file ) || ! file_exists( $uninstall_file ) ) {
	return;
}

require_once $cleanup_file;

/** @var Uninstaller $uninstaller */
$uninstaller = require $uninstall_file;
$uninstaller->clearCronEvents();

if ( $uninstaller->shouldUninstall() ) {
	$uninstaller->uninstall();
}
