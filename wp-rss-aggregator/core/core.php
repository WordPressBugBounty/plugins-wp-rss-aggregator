<?php

namespace RebelCode\Aggregator\Core;

wpra()->loadPackages( WPRA_FILE, array( 'core' ) );

add_action(
	'plugins_loaded',
	function () {
		$wpra = wpra();

		do_action( 'wpra.run.before', $wpra );
		$wpra->run();
		do_action( 'wpra.run.after', $wpra );
	}
);
