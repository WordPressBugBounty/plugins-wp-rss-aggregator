<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Rpc\Handlers\RpcSiteTransferHandler;
use RebelCode\Aggregator\Core\Rpc\RpcClassHandler;
use RebelCode\Aggregator\Core\Rpc\RpcServer;
use RebelCode\Aggregator\Core\Source\SourcesTransfer;
use RebelCode\Aggregator\Core\Transfer\SiteTransfer;

wpra()->addModule(
	'siteTransfer',
	array( 'settings', 'importer', 'rpc' ),
	function ( Settings $settings, Importer $importer, RpcServer $rpc ) {
		$transfer = new SiteTransfer( $settings, $importer->sources, new SourcesTransfer( $importer->sources ) );

		$rpc->addHandler( 'siteTransfer', new RpcClassHandler( new RpcSiteTransferHandler( $transfer ) ) );

		// Tells the admin app which parts this site can export and import.
		add_filter(
			'wpra.admin.frame.l10n',
			function ( array $l10n ) use ( $transfer ): array {
				$l10n['transferParts'] = $transfer->getAvailableParts();
				return $l10n;
			}
		);

		return $transfer;
	}
);
