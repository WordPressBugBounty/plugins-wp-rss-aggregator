<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'settings',
	array(),
	function () {
		$settings = new Settings( 'wpra_settings' );
		$settings->load();
		return $settings;
	}
);
