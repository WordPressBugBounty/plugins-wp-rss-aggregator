<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\WpSdk\Wp\PostType;

wpra()->addModule(
	'feedItems',
	array( 'importer' ),
	function ( Importer $importer ) {
		$cpt = new PostType(
			'wprss_feed_item',
			array(
				'labels' => array(),
				'public' => true,
				'show_in_rest' => true,
				'has_archive' => false,
				'exclude_from_search' => true,
				'publicly_queryable' => false,
				'menu_icon' => 'dashicons-rss',
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
			)
		);

		add_action(
			'init',
			function () use ( $cpt ) {
				$cpt->args['labels'] = array(
					'name' => __( 'Feed Items', 'wp-rss-aggregator' ),
					'singular_name' => __( 'Feed Item', 'wp-rss-aggregator' ),
					'plural_name' => __( 'Feed Items', 'wp-rss-aggregator' ),
					'add_new' => __( 'Add New Feed Item', 'wp-rss-aggregator' ),
					'add_new_item' => __( 'Add New Feed Item', 'wp-rss-aggregator' ),
					'edit_item' => __( 'Edit Feed Item', 'wp-rss-aggregator' ),
					'new_item' => __( 'New Feed Item', 'wp-rss-aggregator' ),
					'view_item' => __( 'View Feed Item', 'wp-rss-aggregator' ),
					'view_items' => __( 'View Feed Items', 'wp-rss-aggregator' ),
					'search_items' => __( 'Search Feed Items', 'wp-rss-aggregator' ),
					'not_found' => __( 'No feed items found', 'wp-rss-aggregator' ),
					'not_found_in_trash' => __( 'No feed items found in trash', 'wp-rss-aggregator' ),
					'parent_item_colon' => __( 'Parent Feed Item:', 'wp-rss-aggregator' ),
					'all_items' => __( 'All Feed Items', 'wp-rss-aggregator' ),
					'archives' => __( 'Feed Item Archives', 'wp-rss-aggregator' ),
					'attributes' => __( 'Feed Item Attributes', 'wp-rss-aggregator' ),
					'insert_into_item' => __( 'Insert into feed item', 'wp-rss-aggregator' ),
					'uploaded_to_this_item' => __( 'Uploaded to this feed item', 'wp-rss-aggregator' ),
					'featured_image' => __( 'Featured Image', 'wp-rss-aggregator' ),
					'set_featured_image' => __( 'Set featured image', 'wp-rss-aggregator' ),
					'remove_featured_image' => __( 'Remove featured image', 'wp-rss-aggregator' ),
					'use_featured_image' => __( 'Use as featured image', 'wp-rss-aggregator' ),
					'filter_items_list' => __( 'Filter feed items list', 'wp-rss-aggregator' ),
					'filter_by_date' => __( 'Filter by date', 'wp-rss-aggregator' ),
					'items_list_navigation' => __( 'Feed items list navigation', 'wp-rss-aggregator' ),
					'items_list' => __( 'Feed items list', 'wp-rss-aggregator' ),
					'item_published' => __( 'Feed item published', 'wp-rss-aggregator' ),
					'item_published_privately' => __( 'Feed item published privately', 'wp-rss-aggregator' ),
					'item_reverted_to_draft' => __( 'Feed item reverted to draft', 'wp-rss-aggregator' ),
					'item_trashed' => __( 'Feed item trashed', 'wp-rss-aggregator' ),
					'item_schedule' => __( 'Feed item scheduled', 'wp-rss-aggregator' ),
					'item_updated' => __( 'Feed item updated', 'wp-rss-aggregator' ),
					'item_link' => __( 'Feed item link', 'wp-rss-aggregator' ),
					'item_link_description' => __( 'A link to a feed item', 'wp-rss-aggregator' ),
				);

				$cpt->register();
			}
		);

		add_action(
			'wpra.deactivate',
			function () {
				unregister_post_type( 'wprss_feed_item' );
				flush_rewrite_rules();
			}
		);

		add_filter(
			'manage_wprss_feed_item_posts_columns',
			function ( array $columns ) {
				unset( $columns['author'], $columns['date'], $columns['title'] );

				// Add new columns.
				$columns['wprss-title'] = __( 'Title', 'wp-rss-aggregator' );
				$columns['source-link'] = __( 'Source Link', 'wp-rss-aggregator' );
				$columns['source'] = __( 'Source', 'wp-rss-aggregator' );

				// Re-add author and date columns.
				$columns['author'] = __( 'Author', 'wp-rss-aggregator' );
				$columns['date'] = __( 'Date', 'wp-rss-aggregator' );

				return $columns;
			},
			10,
			2
		);

		add_action(
			'manage_wprss_feed_item_posts_custom_column',
			function ( string $column, $postId ) use ( $importer ) {
				switch ( $column ) {
					case 'wprss-title':
						$title = get_the_title( $postId );

						if ( empty( $title ) ) {
							printf( '<strong><span>%s</span></strong>', esc_html__( '(no title)', 'wp-rss-aggregator' ) );
							break;
						}
							printf( '<strong><span>%s</span></strong>', esc_html( $title ) );
						break;

					case 'source-link':
						$wpra_url = get_post_meta( $postId, ImportedPost::URL, true );

						if ( ! $wpra_url ) {
							esc_html_e( 'No source link', 'wp-rss-aggregator' );
							break;
						}

						printf( '<a href="%s">%s</a><br>', esc_url( $wpra_url ), esc_url( $wpra_url ) );
						break;

					case 'source':
						$srcIds = get_post_meta( $postId, ImportedPost::SOURCE );
						$srcs = $importer->sources->getManyByIds( $srcIds )->getOr( array() );

						foreach ( $srcs as $src ) {
							printf( '<a href="edit.php?post_type=wprss_feed_item&wpra_source=%d">%s</a><br>', $src->id, esc_html( $src->name ) );
						}
						break;
				}
			},
			10,
			2
		);

		add_filter(
			'post_row_actions',
			function ( $actions, $post ) {
				if ( $post->post_type === 'wprss_feed_item' ) {
					unset( $actions['edit'] );
					unset( $actions['inline hide-if-no-js'] );
					unset( $actions['view'] );
				}
				return $actions;
			},
			10,
			2
		);

		add_action(
			'admin_enqueue_scripts',
			function () {
				if ( ! function_exists( 'get_current_screen' ) ) {
					return;
				}
				$screen = get_current_screen();
				if ( $screen === null || $screen->id !== 'edit-wprss_feed_item' ) {
					return;
				}
				$wpra = wpra();
				wp_enqueue_style( 'wpra-feed-items', $wpra->url . '/core/css/feed-items.css', array(), $wpra->version );
			}
		);

		return $cpt;
	}
);
