<?php

namespace RebelCode\Aggregator\Core\RssReader;

use Exception;
use RebelCode\Aggregator\Core\RssReader\RssItem;
use Stringable;

class RssSelector implements Stringable {

	protected string $selector;
	/** @var list<array{0:string,1:string|null}> */
	protected ?array $cache = null;

	public function __construct( string $selector ) {
		$this->selector = trim( $selector );
	}

	public function resolve( RssItem $item ): ?string {
		if ( $this->selector === '' ) {
			return null;
		}

		$currNode = $item;

		foreach ( $this->parse() as [$tag, $attr] ) {
			[$ns, $tag] = $this->extractNs( $tag );

			$nextNode = $currNode->getChild( $ns, $tag );
			if ( $nextNode === null ) {
				return null;
			}

			if ( $attr !== null ) {
				[$attrNs, $attr] = $this->extractNs( $attr );
				$value = $nextNode->getAttr( $attrNs, $attr );
				return $value;
			}

			$currNode = $nextNode;
		}

		return $currNode->getValue();
	}

	/**
	 * Parses the target string into an array of tag-attr pairs.
	 *
	 * Examples:
	 *   parseTarget('media:content[lang]') => [['media:content', 'lang']]
	 *   parseTarget('author email') => [['author', null], ['email', null]]
	 *
	 * @return list<array{0:string,1:string|null}>
	 */
	public function parse(): array {
		if ( $this->cache !== null ) {
			return $this->cache;
		}

		$newSelector = preg_replace( '/\s+\[/', '[', $this->selector );
		$this->selector = $newSelector ?? $this->selector;

		$parts = preg_split( '/\s+/', $this->selector );
		if ( $parts === false ) {
			return array();
		}

		$result = array();

		foreach ( $parts as $part ) {
			if ( empty( $part ) ) {
				continue;
			}

			$left = strpos( $part, '[' );
			$right = strpos( $part, ']' );

			if ( $left !== false && $right === false ) {
				throw new Exception( 'Expected closing bracket "]" at the end of the custom mapping target.' );
			}

			if ( $left === false && $right !== false ) {
				throw new Exception( 'Missing opening bracket "[" in the custom mapping target.' );
			}

			if ( $left === false && $right === false ) {
				$result[] = array( $part, null );
			} else {
				$tag = trim( substr( $part, 0, $left ) );
				$attr = trim( substr( $part, $left + 1, $right - $left - 1 ) );
				$result[] = array( $tag, $attr );
			}
		}

		$this->cache = $result;

		return $result;
	}

	/** @return array{0:string,1:string} */
	protected function extractNs( string $tag ): array {
		$parts = explode( ':', $tag, 2 );

		if ( count( $parts ) === 2 ) {
			return $parts;
		} else {
			return array( '', $tag );
		}
	}

	public function __toString(): string {
		return $this->selector;
	}
}
