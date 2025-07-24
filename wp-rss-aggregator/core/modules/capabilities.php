<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'capabilities',
	array(),
	function () {
		add_action(
			'init',
			function () {
				$roles = array(
					'administrator' => array(
						Capabilities::SEE_AGGREGATOR,
						Capabilities::ADD_SOURCES,
						Capabilities::EDIT_SOURCES,
						Capabilities::DELETE_SOURCES,
						Capabilities::ADD_DISPLAYS,
						Capabilities::EDIT_DISPLAYS,
						Capabilities::DELETE_DISPLAYS,
						Capabilities::EDIT_SETTINGS,
					),
				);

				foreach ( $roles as $name => $caps ) {
					$role = get_role( $name );
					if ( ! $role ) {
						continue;
					}

					foreach ( $caps as $cap ) {
						$role->add_cap( $cap );
					}
				}
			}
		);
	}
);
