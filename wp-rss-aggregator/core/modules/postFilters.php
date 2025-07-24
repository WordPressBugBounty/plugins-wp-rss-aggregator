<?php

namespace RebelCode\Aggregator\Core;

use WP_Query;
use RebelCode\Aggregator\Core\IrPost\IrAuthor;

wpra()->addModule(
	'postFilters',
	array( 'settings' ),
	function ( Settings $settings ) {
		$showInBlog = $settings->register( 'showPostsInBlog' )->setDefault( true )->get();
		$showInAdmin = $settings->register( 'showPostsInWpAdmin' )->setDefault( true )->get();
		$hideEmptyCurationSection = $settings->register( 'hideEmptyCurationSection' )->setDefault( false )->get();
		$hideEmptyImportedSection = $settings->register( 'hideEmptyImportedSection' )->setDefault( false )->get();

		function hideImportedPosts( WP_Query $query ) {
			$mq = $query->get( 'meta_query' ) ?: array( 'relation' => 'AND' );
			$mq[] = array(
				'key' => ImportedPost::SOURCE,
				'compare' => 'NOT EXISTS',
				'value' => '',
			);
			$query->set( 'meta_query', $mq );
		}

		function filterBySourceId( WP_Query $query, int $id ) {
			$mq = $query->get( 'meta_query' ) ?: array( 'relation' => 'AND' );
			$mq[] = array(
				'key' => ImportedPost::SOURCE,
				'compare' => 'EQUALS',
				'value' => $id,
			);
			$query->set( 'meta_query', $mq );
		}

		add_filter(
			'pre_get_posts',
			function ( WP_Query $query ) use ( $showInBlog, $showInAdmin ) {
				if ( is_admin() && $query->is_main_query() ) {
					$id = filter_input( INPUT_GET, 'wpra_source', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );

					if ( is_int( $id ) || is_numeric( $id ) ) {
						filterBySourceId( $query, (int) $id );
					} elseif ( ! $showInAdmin ) {
						hideImportedPosts( $query );
					}

					return $query;
				}

				if ( is_home() && ! $showInBlog ) {
					hideImportedPosts( $query );
					return $query;
				}

				return $query;
			}
		);

		$filterAuthor = function ( string $name ) {
			global $post;
			if ( $post === null ) {
				return $name;
			}

			$rssAuthorName = get_post_meta( $post->ID, ImportedPost::AUTHOR_NAME, true );

			if ( empty( $rssAuthorName ) || ! IrAuthor::isDefault( (int) $post->post_author ) ) {
				return $name;
			}

			if ( is_admin() ) {
				return $name . ' (' . $rssAuthorName . ')';
			}

			return $rssAuthorName;
		};

		add_filter( 'the_author', $filterAuthor );
		add_filter( 'get_the_author_display_name', $filterAuthor );
	}
);
