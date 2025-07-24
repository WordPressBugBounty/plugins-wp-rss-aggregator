<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

/** @psalm-immutable */
interface RssNode {

	/** Retrieves the node's tag. */
	public function getTag(): string;

	/** Retrieves the node's namespace. */
	public function getNamespace(): string;

	/**
	 * Retrieves the text content for this node.
	 *
	 * @return string
	 */
	public function getValue(): string;

	/**
	 * Retrieves the attributes for this node.
	 *
	 * @return array<string,array<string,string>> [ns => [attr => value]]
	 */
	public function getAttrs(): array;

	/**
	 * Retrieves node attributes by namespace.
	 *
	 * @param string $ns The namespace.
	 * @return array<string,string> [attr => value]
	 */
	public function getAttrsByNs( string $ns ): array;

	/**
	 * Retrieves node attributes by name.
	 *
	 * @param string $name The name of the attribute.
	 * @return array<string,string> [ns => value]
	 */
	public function getAttrsByName( string $name ): array;

	/**
	 * Retrieves a single attribute's value from this node.
	 *
	 * @param string $name The name of the attribute to retrieve.
	 * @param string $ns Optional namespace. Defaults to the empty namespace.
	 * @return string|null The value of the attribute, or null if the attribute is not found in the node or namespace.
	 */
	public function getAttr( string $ns, string $name ): ?string;

	/**
	 * Retrieves all the child nodes in a flat list.
	 *
	 * @return list<RssNode>
	 */
	public function getChildren(): array;

	/**
	 * Retrieves child nodes by their type (namespace and tag).
	 *
	 * @param string $ns The namespace.
	 * @param string $tag The tag.
	 * @return list<RssNode>
	 */
	public function getChildrenByType( string $ns, string $tag ): array;

	/**
	 * Retrieves child nodes by namespace.
	 *
	 * @param string $ns The namespace.
	 * @return list<RssNode>
	 */
	public function getChildrenByNs( string $ns ): array;

	/**
	 * Retrieves child nodes by tag.
	 *
	 * @param string $tag The tag.
	 * @return array<string,list<RssNode>> [ns => node[]]
	 */
	public function getChildrenByTag( string $tag ): array;

	/**
	 * Retrieves a single child from this node.
	 *
	 * @param string $ns The namespace name of the child.
	 * @param string $tag The tag of the child.
	 * @return RssNode|null The child node, or null if the child is not found.
	 */
	public function getChild( string $ns, string $tag ): ?RssNode;

	/** Retrieves the node's parent node, or null if the node is the root node. */
	public function getParent(): ?RssNode;
}
