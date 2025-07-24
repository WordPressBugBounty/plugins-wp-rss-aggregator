<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Store\DisplaysStore;

wpra()->addModule(
	'renderer',
	array( 'db', 'importer', 'settings' ),
	function ( Database $db, Importer $importer, Settings $settings ) {
		$wpra = wpra();

		$displaysStore = new DisplaysStore( $db, $db->tableName( 'displays' ) );
		$displaysStore->createTable();

		$renderer = new Renderer(
			$db,
			$importer->sources,
			$importer->wpPosts,
			$displaysStore,
		);

		$disableStyles = $settings->register( 'disableStyles' )->setDefault( false )->get();
		if ( ! $disableStyles ) {
			add_action(
				'init',
				function () use ( $wpra ) {
					wp_enqueue_style( 'wpra-lightbox', $wpra->url . '/core/css/jquery-colorbox.css', array(), '1.4.33' );
					wp_register_script( 'wpra-lightbox', $wpra->url . '/core/js/jquery-colorbox.min.js', array( 'jquery' ), $wpra->version );

					wp_register_script( 'wpra-htmx', $wpra->url . '/core/js/htmx-1.9.12.min.js', array(), '1.9.12' );

					wp_register_style( 'wpra-displays', $wpra->url . '/core/css/displays.css', array( 'wpra-lightbox' ), $wpra->version );
					wp_register_script( 'wpra-displays', $wpra->url . '/core/js/displays.js', array( 'wpra-lightbox', 'wpra-htmx' ), $wpra->version );
				}
			);
		}

		$ajaxRender = function () use ( $renderer ) {
			$dataJson = filter_input( INPUT_POST, 'data' );
			$data = json_decode( $dataJson, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				status_header( 400 );
				echo 'Could not decode JSON.';
				die();
			}

			$id = $data['id'] ?? null;
			$page = $data['page'] ?? null;

			if ( ! is_numeric( $id ) || ! is_numeric( $page ) ) {
				status_header( 400 );
				echo 'Invalid ID or page number.';
				die();
			}

			// The $data array now contains all persisted shortcode attributes
			// from hx-vals, including id, page, sources, limit, exclude, pagination, template.
			// Pass the whole $data array to renderArgs.
			// Specify 'shortcode' as type to ensure shortcode-specific logic (like limit/pagination overrides) applies.
			echo $renderer->renderArgs( $data, 'shortcode' );
			die();
		};

		add_action( 'wp_ajax_wpra.render.display', $ajaxRender );
		add_action( 'wp_ajax_nopriv_wpra.render.display', $ajaxRender );

		return $renderer;
	}
);
