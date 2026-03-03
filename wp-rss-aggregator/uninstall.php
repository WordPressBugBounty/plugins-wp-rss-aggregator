<?php

use RebelCode\Aggregator\Core\Uninstaller;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( 1 );
}

$uninstall_file = __DIR__ . '/core/uninstall.php';
if ( ! file_exists( $uninstall_file ) ) {
	return;
}

/** @var Uninstaller $uninstaller */
$uninstaller = require $uninstall_file;
if ( $uninstaller->shouldUninstall() ) {
	$uninstaller->uninstall();
}
