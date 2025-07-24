<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

/**
 * A generic abstract RSS node that serves as a base for implementations.
 *
 * @psalm-immutable
 */
class BaseRssNode implements RssNode {

	protected string $tag;
	protected string $ns;
	protected string $value;
	protected ?RssNode $parent;
	/** @var array<string,array<string,string>> [namespace => [attribute => value]] */
	protected array $attrs;
	/** @var array<string,array<string,list<RssNode>>> [namespace => [tag => node[]]] */
	protected array $children;

	/**
	 * Constructor.
	 *
	 * @param string                                    $tag The node tag name.
	 * @param string                                    $ns The namespace of the node's tag.
	 * @param string                                    $value The node value.
	 * @param array<string,array<string,string>>        $attrs [namespace => [attribute => value]]
	 * @param array<string,array<string,list<RssNode>>> $children [namespace => [tag => node[]]]
	 * @param RssNode|null                              $parent The parent node.
	 */
	public function __construct(
		string $ns,
		string $tag,
		string $value = '',
		array $attrs = array(),
		array $children = array(),
		?RssNode $parent = null
	) {
		$this->tag = $tag;
		$this->ns = $ns;
		$this->value = $value;
		$this->attrs = $attrs;
		$this->children = $children;
		$this->parent = $parent;
	}

	public function getTag(): string {
		return $this->tag;
	}

	public function getNamespace(): string {
		return $this->ns;
	}

	public function getValue(): string {
		return $this->value;
	}

	public function getAttrs(): array {
		return $this->attrs;
	}

	public function getAttrsByNs( string $ns ): array {
		return $this->attrs[ $ns ] ?? array();
	}

	public function getAttrsByName( string $name ): array {
		$result = array();

		foreach ( $this->attrs as $ns => $attrs ) {
			if ( array_key_exists( $name, $attrs ) ) {
				$result[ $ns ] = $attrs[ $name ];
			}
		}

		return $result;
	}

	public function getAttr( string $ns, string $name ): ?string {
		return $this->attrs[ $ns ][ $name ] ?? null;
	}

	public function getChildren(): array {
		$result = array();

		foreach ( $this->children as $ns => $tag2Child ) {
			foreach ( $tag2Child as $tag => $children ) {
				$result = array_merge( $result, $children );
			}
		}

		return $result;
	}

	public function getChildrenByType( string $ns, string $tag ): array {
		$nsChildren = $this->children[ $ns ] ?? array();

		$result = array();
		foreach ( $nsChildren as $childTag => $children ) {
			if ( $childTag === $tag ) {
				$result = array_merge( $result, $children );
			}
		}

		return $result;
	}

	public function getChildrenByNs( string $ns ): array {
		$result = array();
		foreach ( $this->children[ $ns ] ?? array() as $tag => $children ) {
			$result = array_merge( $result, $children );
		}
		return $result;
	}

	public function getChildrenByTag( string $tag ): array {
		$result = array();

		foreach ( $this->children as $ns => $nsChildren ) {
			foreach ( $nsChildren as $childTag => $children ) {
				if ( $childTag === $tag ) {
					$result = array_merge( $result, $children );
				}
			}
		}

		return $result;
	}

	public function getChild( string $ns, string $tag ): ?RssNode {
		$children = $this->children[ $ns ][ $tag ] ?? array();

		return reset( $children ) ?: null;
	}

	public function getParent(): ?RssNode {
		return $this->parent;
	}
}
