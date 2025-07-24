<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\IrPost;

use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\Result;
use WP_Post;
use WP_Term;

use function add_filter;
use function is_wp_error;
use function remove_filter;

class IrTerm {

	/** @var array<string,string[]> */
	protected static array $taxCache = array();

	public ?int $id = null;
	public string $taxonomy;
	public string $slug;
	public ?string $label;
	public ?IrTerm $parent;

	/**
	 * Constructor.
	 *
	 * @param string      $taxonomy The taxonomy of the term.
	 * @param string      $slug The slug of the term.
	 * @param string|null $label Optional label for the term.
	 * @param IrTerm|null $parent Optional parent term.
	 */
	public function __construct( string $taxonomy, string $slug, ?string $label = null, IrTerm $parent = null ) {
		$this->taxonomy = $taxonomy;
		$this->slug = $slug;
		$this->label = $label;
		$this->parent = $parent;
	}

	/**
	 * Gets the corresponding WordPress term instance, creating it if necessary.
	 *
	 * @param string|null $lang The language to use.
	 * @return Result<WP_Term> The result containing the WordPress term instance, if successful.
	 */
	public function getOrCreate( ?string $lang = null ): Result {
		$info = term_exists( $this->slug, $this->taxonomy );
		$exists = is_array( $info );

		if ( $exists ) {
			$term = get_term_by( 'id', $info['term_id'], $this->taxonomy );
		} else {
			$extra = array( 'slug' => $this->slug );

			if ( $this->parent !== null ) {
				$result = $this->parent->getOrCreate( $lang );

				if ( $result->isOk() ) {
					$extra['parent'] = $result->get()->term_id;
				} else {
					$err = $result->error();
					Logger::warning( sprintf( __( 'Could not get or create parent term: %s', 'wprss' ), $err->getMessage() ) );
				}
			}

			$info = wp_insert_term( $this->label ?? $this->slug, $this->taxonomy, $extra );

			if ( is_wp_error( $info ) ) {
				return Result::Err( $info->get_error_message() );
			}

			$term = get_term_by( 'id', $info['term_id'], $this->taxonomy );
			if ( ! ( $term instanceof WP_Term ) ) {
				return Result::Err( sprintf( __( 'Could not get WordPress term "%s".', 'wprss' ), $info['term_id'] ) );
			}
		}

		// WPML compatibility
		// Translate the term according to the given language
		global $sitepress;
		if ( $lang && is_object( $sitepress ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			// Translate the term using WPML
			$translatedId = $sitepress->get_object_id( $term->term_id, $this->taxonomy, false, $lang );

			if ( $translatedId ) {
				// Remove WPML term ID translation
				remove_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1 );

				// Get the term for the given ID without WPML's ID translation
				$translatedTerm = get_term_by( 'id', $translatedId, $this->taxonomy );

				if ( $translatedTerm instanceof WP_Term ) {
					$term = $translatedTerm;
				}

				// Restore WPML term ID translation
				add_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1, 1 );
			}
		}

		return Result::Ok( $term );
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'id' => $this->id,
			'taxonomy' => $this->taxonomy,
			'slug' => $this->slug,
			'label' => $this->label,
			'parent' => $this->parent ? $this->parent->toArray() : null,
		);
	}

	/**
	 * Creates an IR term instance from an array.
	 *
	 * @param array $data The array data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$term = new self(
			$data['taxonomy'] ?? '',
			$data['slug'] ?? '',
			$data['label'] ?? null,
			$data['parent'] ?? null
		);

		if ( $data['id'] ) {
			$term->id = (int) $data['id'];
		}

		return $term;
	}

	/** Creates an IR term from a WordPress term instance. */
	public static function fromWpTerm( WP_Term $term ): IrTerm {
		$parent = null;

		if ( ! empty( $term->parent ) ) {
			$parentTerm = get_term_by( 'id', $term->parent, $term->taxonomy );

			if ( $parentTerm instanceof WP_Term ) {
				$parent = static::fromWpTerm( $parentTerm );
			} else {
				Logger::warning( "Parent term {$term->parent} not found for term {$term->term_id}." );
			}
		}

		$irTerm = new self( $term->taxonomy, $term->slug, $term->name, $parent );
		$irTerm->id = $term->term_id;
		return $irTerm;
	}

	/** @return array<string,IrTerm[]> */
	public static function getForWpPost( WP_Post $post ): array {
		if ( ! array_key_exists( $post->post_type, self::$taxCache ) ) {
			self::$taxCache[ $post->post_type ] = get_object_taxonomies( $post->post_type );
		}

		$result = array();
		foreach ( self::$taxCache[ $post->post_type ] as $taxonomy ) {
			$wpTerms = get_the_terms( $post->ID, $taxonomy );

			if ( is_wp_error( $wpTerms ) ) {
				continue;
			}

			$wpTerms = ( $wpTerms === false ) ? array() : $wpTerms;
			$result[ $taxonomy ] = Arrays::map( $wpTerms, array( self::class, 'fromWpTerm' ) );
		}

		return $result;
	}
}
