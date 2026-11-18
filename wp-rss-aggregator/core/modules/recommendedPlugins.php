<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Rpc\Handlers\RpcRecommendedPluginsHandler;

wpra()->addModule(
	'recommendedPlugins',
	array(),
	function () {
		$wpra = wpra();
		$handler = new RpcRecommendedPluginsHandler( new RecommendedPlugins(), $wpra->url . '/core/imgs/recommended/' );

		// The list itself is loaded over RPC by the Toolkit page, so other Aggregator screens don't scan the plugins folder.
		add_filter(
			'wpra.admin.frame.l10n',
			function ( array $l10n ): array {
				$l10n['canInstallPlugins'] = current_user_can( 'install_plugins' );

				return $l10n;
			}
		);

		return $handler;
	}
);
