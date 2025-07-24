<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'db',
	array(),
	function () {
		global $wpdb;

		$prefix = apply_filters( 'wpra.db.prefix', 'agg_' );
		$db = new Database( $wpdb, $prefix );

		return $db;
	}
);

wpra()->addModule(
    'cleanup',
	array(),
    function () {
        return new DataCleanup();
    }
);
