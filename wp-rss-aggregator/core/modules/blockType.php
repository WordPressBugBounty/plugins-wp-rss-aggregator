<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\WpSdk\Wp\Style;
use RebelCode\WpSdk\Wp\ScriptL10n;
use RebelCode\WpSdk\Wp\Script;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Rpc\RpcServer;

wpra()->addModule(
	'blockType',
	array( 'rpc', 'renderer' ),
	function ( RpcServer $rpc, Renderer $renderer ) {
		$wpra = wpra();

		$renderFn = function ( array $attrs ) use ( $renderer ) {
			return $renderer->renderArgs( $attrs, 'block' );
		};

		add_action(
			'init',
			function () use ( $rpc, $wpra, $renderer, $renderFn ) {
				$displays = $renderer->displays->getList()->getOr( array() );
				$l10n = new ScriptL10n(
					'WpraDisplayBlock',
					array(
						'ajaxURL' => admin_url( 'admin-ajax.php' ),
						'rpcNonce' => $rpc->getNonce(),
						'displayOptions' => Arrays::map(
							$displays,
							fn ( $d ) => array(
								'value' => $d->id,
								'label' => $d->name,
							)
						),
					)
				);
				$js = new Script( 'wpra-block', $wpra->url . '/core/js/blocks/dist/display/display.js', null, array(), $l10n );
				$js->register();
				wp_set_script_translations( $js->id, 'wp-rss-aggregator', $wpra->path . '/languages/' );

				$block_args =
				array(
					'attributes' => array(
						'id' => array(
							'type' => 'integer',
							'default' => 0,
						),
						'align' => array(
							'type' => 'string',
						),
						'isAll' => array(
							'type' => 'boolean',
						),
						'template' => array(
							'type' => 'string',
						),
						'pagination' => array(
							'type' => 'boolean',
						),
						'page' => array(
							'type' => 'number',
						),
						'limit' => array(
							'type' => 'number',
						),
						'exclude' => array(
							'type' => 'string',
						),
						'source' => array(
							'type' => 'string',
						),
						'className' => array(
							'type' => 'string',
						),
					),
					'render_callback' => $renderFn,
				);
				register_block_type_from_metadata( $wpra->path . '/core/js/blocks/dist/display/block.json', $block_args );
			}
		);
	}
);


wpra()->addModule(
	'admin.gutenberg',
	array(),
	function () {
		$wpra = wpra();
		add_action(
			'enqueue_block_editor_assets',
			function () use ( $wpra ) {
				global $post;

				$post_id = isset( $post ) ? $post->ID : null;
				if ( ! $post_id ) {
					return;
				}

				$meta_value = get_post_meta( $post_id, ImportedPost::URL, true );
				$l10n = new ScriptL10n(
					'WpraGutenbergPlugin',
					array(
						'wpraUrl' => esc_url( $meta_value ),
					)
				);
				$js = new Script( 'wpra-admin-gutenberg', $wpra->url . '/core/js/blocks/dist/gutenberg/gutenberg.js', null, array(), $l10n );
				$css = new Style( 'wpra-admin-gutenberg', $wpra->url . '/core/js/blocks/dist/gutenberg/style-gutenberg.css' );
				$js->register();
				wp_set_script_translations( $js->id, 'wp-rss-aggregator', $wpra->path . '/languages/' );
				$js->enqueue();
				$css->enqueue();
			}
		);
	}
);
