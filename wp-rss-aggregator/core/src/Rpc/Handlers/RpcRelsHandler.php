<?php

namespace RebelCode\Aggregator\Core\Rpc\Handlers;

use RebelCode\Aggregator\Core\Display;
use RebelCode\Aggregator\Core\Importer;
use RebelCode\Aggregator\Core\Renderer;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Utils\Arrays;

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
		$rels = array();

		$wpPosts = $this->importer->wpPosts->getFromSources( $ids )->getOrThrow();
		foreach ( $wpPosts as $post ) {
			foreach ( $post->sources as $sid ) {
				$rels[ $sid ]['imported'] ??= 0;
				$rels[ $sid ]['imported']++;
			}
		}
		unset( $wpPosts );

		$displays = $this->renderer->displays->getWithSources( $ids )->getOrThrow();
		foreach ( $displays as $display ) {
			foreach ( $display->sources as $sid ) {
				$rels[ $sid ]['displays'][] = array(
					'id' => $display->id,
					'name' => $display->name,
				);
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
