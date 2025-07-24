<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'shortcode',
	array( 'renderer' ),
	function ( Renderer $renderer ) {
		$renderFn = function ( array $args ) use ( $renderer ) {
			return $renderer->renderArgs( $args, 'shortcode' );
		};

		add_action(
			'init',
			function () use ( $renderFn ) {
				add_shortcode( 'wp-rss-aggregator', $renderFn );
			}
		);
	}
);
