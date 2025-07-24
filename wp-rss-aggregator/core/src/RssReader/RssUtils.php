<?php

namespace RebelCode\Aggregator\Core\RssReader;

abstract class RssUtils {

	/**
	 * Retrieves the child nodes at a specific path.
	 *
	 * @param list<string> $path A list of `ns:tag` strings.
	 * @return list<RssNode>
	 */
	public static function getPath( RssNode $node, array $path ): array {
		$curr = array( $node );
		$next = array();

		foreach ( $path as $selector ) {
			if ( str_contains( $selector, ':' ) ) {
				[$ns, $tag] = explode( ':', $selector, 2 );
			} else {
				$ns = '';
				$tag = $selector;
			}

			foreach ( $curr as $node ) {
				$next = array_merge( $next, $node->getChildrenByType( $ns, $tag ) );
			}

			if ( empty( $next ) ) {
				return array();
			}

			$curr = $next;
			$next = array();
		}

		return $curr;
	}
}
