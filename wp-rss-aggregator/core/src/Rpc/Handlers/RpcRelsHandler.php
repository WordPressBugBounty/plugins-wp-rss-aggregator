<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Renderer;
use RebelCode\Aggregator\Core\Importer;
use RebelCode\Aggregator\Core\Display;

/**
 * An RPC handler dedicated to getting and counting relationships between
 * the different entities.
 */
class RpcRelsHandler {

	private Importer $importer;
	private Renderer $renderer;

	public function __construct( Importer $importer, Renderer $renderer ) {
		$this->importer = $importer;
		$this->renderer = $renderer;
	}

	/**
	 * Gets the relationships for a list of items.
	 *
	 * @param string   $which The type of item: "sources", "displays", "folders".
	 * @param iterable $items
	 * @return array
	 */
	public function get( string $which, iterable $items ): array {
		switch ( $which ) {
			case 'sources':
				return $this->getSourceRels( $items );
			case 'displays':
				return $this->getDisplayRels( $items );
			default:
				return apply_filters( "wpra.rpc.rels.$which", array(), $items );
		}
	}

	/**
	 * Gets the relationships for a list of sources.
	 *
	 * @param iterable<Source> $sources
	 * @return array<int,array{pending:int,imported:int,displays:list}>
	 */
	public function getSourceRels( iterable $sources ): array {
		$ids = Arrays::map( $sources, fn ( Source $s ) => $s->id );
		if ( empty( $ids ) ) {
			return [];
		}

		$rels = array();
		foreach ( $ids as $sid ) {
			$rels[ $sid ] = [
				'imported' => 0,
				'displays' => [],
			];
		}

		$importedCounts = $this->importer->wpPosts->getCountsBySource( $ids )->getOrThrow();
		foreach ( $importedCounts as $sid => $count ) {
			if ( isset( $rels[ $sid ] ) ) {
				$rels[ $sid ]['imported'] = $count;
			}
		}

		$displays = $this->renderer->displays->getWithSources( $ids )->getOrThrow();
		foreach ( $displays as $display ) {
			foreach ( $display->sources as $sid ) {
				if ( isset( $rels[ $sid ] ) ) {
					$rels[ $sid ]['displays'][] = array(
						'id' => $display->id,
						'name' => $display->name,
					);
				}
			}
		}
		unset( $displays );

		return apply_filters( 'wpra.rpc.rels.sources', $rels, $ids );
	}

	/**
	 * Gets the relationships for a list of displays.
	 *
	 * @param iterable<Display> $displays
	 * @return array
	 */
	public function getDisplayRels( iterable $sources ): array {
		$ids = Arrays::map( $sources, fn ( Source $s ) => $s->id );
		$rels = array();

		return apply_filters( 'wpra.rpc.rels.displays', $rels, $ids );
	}
}
