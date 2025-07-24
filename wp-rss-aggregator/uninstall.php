<?php

use RebelCode\Aggregator\Core\Uninstaller;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( 1 );
}

$path = __DIR__ . '/core/uninstall.php';
if ( ! file_exists( $path ) ) {
	return;
}

/** @var Uninstaller $uninstaller */
$uninstaller = require $path;
if ( $uninstaller->shouldUninstall() ) {
	$uninstaller->uninstall();
}
