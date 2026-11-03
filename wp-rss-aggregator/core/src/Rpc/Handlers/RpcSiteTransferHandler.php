<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Capabilities;
use RebelCode\Aggregator\Core\Transfer\SiteTransfer;
use RuntimeException;

/**
 * An RPC handler for exporting and importing settings, sources, folders and displays.
 *
 * The RPC server already checks the request nonce and that the user can see Aggregator. Importing also requires the
 * capability for each part that is imported.
 *
 * @since 5.7.0
 */
class RpcSiteTransferHandler {

	/** The capability needed to import each part. */
	public const IMPORT_CAPABILITIES = array(
		'settings' => Capabilities::EDIT_SETTINGS,
		'sources' => Capabilities::ADD_SOURCES,
		'folders' => Capabilities::ADD_SOURCES,
		'displays' => Capabilities::ADD_DISPLAYS,
	);

	private SiteTransfer $transfer;

	/**
	 * Constructor.
	 *
	 * @since 5.7.0
	 *
	 * @param SiteTransfer $transfer The site transfer service.
	 */
	public function __construct( SiteTransfer $transfer ) {
		$this->transfer = $transfer;
	}

	/**
	 * Exports the chosen parts.
	 *
	 * @since 5.7.0
	 *
	 * @param list<string> $parts The parts to export.
	 * @return array<string,mixed> The export document.
	 */
	public function export( array $parts ): array {
		return $this->transfer->export( $this->cleanParts( $parts ) );
	}

	/**
	 * Imports the chosen parts of an export document.
	 *
	 * @since 5.7.0
	 *
	 * @param string       $json The JSON export document.
	 * @param list<string> $parts The parts to import.
	 * @return array<string,mixed> The import summary.
	 * @throws RuntimeException If the current user cannot import one of the chosen parts.
	 */
	public function import( string $json, array $parts ): array {
		$parts = $this->cleanParts( $parts );

		foreach ( $parts as $part ) {
			if ( ! current_user_can( self::IMPORT_CAPABILITIES[ $part ] ) ) {
				throw new RuntimeException( esc_html__( 'You are not allowed to import everything that you chose.', 'wp-rss-aggregator' ) );
			}
		}

		return $this->transfer->import( $json, $parts );
	}

	/**
	 * Keeps only the known part names from a request.
	 *
	 * @since 5.7.0
	 *
	 * @param array<mixed> $parts The requested parts.
	 * @return list<string> The known parts.
	 */
	protected function cleanParts( array $parts ): array {
		return array_values( array_intersect( SiteTransfer::PARTS, array_filter( $parts, 'is_string' ) ) );
	}
}
