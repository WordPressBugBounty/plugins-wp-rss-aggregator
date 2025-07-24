<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader\SimplePie;

use RebelCode\Aggregator\Core\RssReader\BaseRssNode;
use RebelCode\Aggregator\Core\RssReader\RssNode;

/** @psalm-immutable */
class SpRssNode extends BaseRssNode {

	/**
	 * A mapping from namespace names to their URLs.
	 *
	 * @var array<string,string>
	 */
	protected array $namespaces;

	/**
	 * Constructor.
	 *
	 * @param string               $ns The node's tag namespace.
	 * @param string               $tag The node's tag.
	 * @param array                $data The SimplePie node data array.
	 * @param array<string,string> $namespaces An associative array that maps namespace names to their respective URLs.
	 * @param RssNode|null         $parent The parent node, or null if the node is the root node.
	 */
	public function __construct(
		string $ns,
		string $tag,
		array $data,
		array $namespaces,
		?RssNode $parent = null
	) {
		parent::__construct( $ns, $tag, $data['data'] ?? '', array(), array(), $parent );

		$this->namespaces = $namespaces;
		$this->attrs = $this->createAttrs( $data['attribs'] ?? array() );
		$this->children = $this->createChildren( $data['child'] ?? array() );
	}

	/**
	 * Retrieves the namespace mappings from names to URLs.
	 *
	 * @return array<string, string>
	 */
	public function getNamespaces(): array {
		return $this->namespaces;
	}

	/** Converts SimplePie attributes into the format expected by {@link BaseRssNode}. */
	protected function createAttrs( array $dataAttrs ): array {
		$result = array();

		foreach ( $dataAttrs as $nsUrl => $attrs ) {
			$nsName = $this->getNamespaceName( $nsUrl );

			foreach ( $attrs as $attr => $value ) {
				$result[ $nsName ][ $attr ] = $value;
			}
		}

		return $result;
	}

	/** Converts SimplePie child data into the format expected by {@link BaseRssNode}. */
	protected function createChildren( array $childData ): array {
		$result = array();

		foreach ( $childData as $nsUrl => $tags ) {
			$ns = $this->getNamespaceName( $nsUrl );

			foreach ( $tags as $tag => $tagChildren ) {
				foreach ( $tagChildren as $child ) {
					$result[ $ns ][ $tag ][] = new self( $ns, $tag, $child, $this->namespaces, $this );
				}
			}
		}

		return $result;
	}

	/** Retrieves the name of a namespace, by its URL. */
	protected function getNamespaceName( string $url ): string {
		$key = array_search( $url, $this->namespaces );
		return $key === false ? '' : $key;
	}
}
