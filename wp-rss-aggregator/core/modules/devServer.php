<?php

namespace RebelCode\Aggregator\Core;

wpra()->addModule(
	'devServer',
	array( 'admin.frame.l10n' ),
	function ( callable $getL10n ) {
		$wpra = wpra();

		$url = apply_filters( 'wpra.devServer.url', 'http://localhost:5173' );
		$enabled = apply_filters( 'wpra.devServer.enabled', ! is_dir( "{$wpra->path}/core/js/dist" ) );

		// Inject vite runtime and other stuff in the <head>
		add_filter(
			'wpra.admin.frame.head',
			function ( string $output ) use ( $enabled, $url ) {
				if ( ! $enabled ) {
					return $output;
				}
				return $output . <<<HTML
            <script>const global = globalThis</script>
            <script type="module">
            import RefreshRuntime from "{$url}/@react-refresh"
            RefreshRuntime.injectIntoGlobalHook(window)
            window.\$RefreshReg\$ = () => {}
            window.\$RefreshSig\$ = () => (type) => type
            window.__vite_plugin_react_preamble_installed__ = true
            </script>
            <script type="module" src="{$url}/@vite/client"></script>
            HTML;
			}
		);

		// Enqueue app module and manually localized config
		add_filter(
			'wpra.admin.frame.body.end',
			function ( string $output ) use ( $enabled, $url, $getL10n ) {
				if ( ! $enabled ) {
					return $output;
				}

				$l10n = $getL10n();
				$varName = $l10n->name;
				$varVal = wp_json_encode( $l10n->data );

				return <<<HTML
            <script type="text/javascript">
                var {$varName} = {$varVal};
            </script>
            <script type="module" src="{$url}/core/js/src/admin/index.tsx"></script>
            HTML;
			}
		);
	}
);
