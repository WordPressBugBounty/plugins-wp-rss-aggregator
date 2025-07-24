<?php

namespace RebelCode\Aggregator\Core;

use WP_Post;

wpra()->addModule(
	'rowActions',
	array( 'importer' ),
	function ( Importer $importer ) {
		add_filter(
			'post_row_actions',
			function ( array $actions, WP_Post $post ) {
				if ( $post->post_status === 'trash' ) {
					return $actions;
				}
				$srcId = get_post_meta( $post->ID, ImportedPost::SOURCE, true );
				if ( ! is_numeric( $srcId ) ) {
					return $actions;
				}

				$source = $_GET['wpra_source'] ?? '';

				$rejectNonce = wp_create_nonce( 'wpra_reject' );
				$rejectUrl = sprintf( admin_url( 'post.php?wpraRowAction=wpra-delete-reject&post=%d&_wpnonce=%s&source=%s' ), $post->ID, $rejectNonce, esc_attr( $source ) );
				$rejectLink = sprintf( '<a href="%s">%s</a>', esc_url( $rejectUrl ), esc_html( __( 'Trash and reject', 'wp-rss-aggregator' ) ) );

				$i = array_search( 'trash', array_keys( $actions ) );
				return array_slice( $actions, 0, $i + 1 ) + array( 'wpra-delete-reject' => $rejectLink ) + array_slice( $actions, $i + 1 );
			},
			10,
			2
		);

		add_action(
			'admin_init',
			function () use ( $importer ) {
				if ( ! isset( $_GET['_wpnonce'], $_GET['post'], $_GET['wpraRowAction'] ) ) {
					return;
				}

				check_admin_referer( 'wpra_reject' );
				$action = filter_input( INPUT_GET, 'wpraRowAction', FILTER_DEFAULT ) ?: '';
				$source = filter_input( INPUT_GET, 'source', FILTER_VALIDATE_INT ) ?: 0;
				$postId = filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT ) ?: 0;

				$post = get_post( (int) $postId );
				if ( $post === null ) {
					return;
				}

				$returnUrl = sprintf( admin_url( 'edit.php?post_type=%s' ), $post->post_type );
				if ( ! empty( $source ) ) {
					$returnUrl .= '&wpra_source=' . $source;
				}

				switch ( $action ) {
					case 'wpra-delete-reject':
						$guid = get_post_meta( $post->ID, ImportedPost::GUID, true );
						if ( empty( $guid ) ) {
							set_transient( 'wpra_notice_rejected_post', __( 'Cannot reject a post that was not imported by Aggregator.', 'wp-rss-aggregator' ) );
							break;
						}

						$user = wp_get_current_user();
						$note = sprintf( _x( 'Deleted and rejected by %s', 'wp-rss-aggregator' ), $user->display_name );
						$result = $importer->rejectList->add( new RejectedItem( $guid, null, $note ) );

						if ( $result->isOk() ) {
							wp_delete_post( $post->ID );
							set_transient( 'wpra_notice_rejected_post', '1' );
						} else {
							set_transient( 'wpra_notice_rejected_post', $result->error()->getMessage() );
						}
						break;
				}

				wp_redirect( $returnUrl );
				die( 0 );
			}
		);

		add_action(
			'admin_notices',
			function () {
				$rejectedPost = get_transient( 'wpra_notice_rejected_post' );
				if ( ! empty( $rejectedPost ) ) {
					delete_transient( 'wpra_notice_rejected_post' );
					if ( $rejectedPost === '1' ) {
						printf( '<div class="notice notice-success notice-dismissible"><p>%s</p></div>', __( 'The post has been successfully rejected and moved to the Trash.', 'wp-rss-aggregator' ) );
					} else {
						printf( '<div class="notice notice-error notice-dismissible"><p>%s</p></div>', $rejectedPost );
					}
				}
			}
		);
	}
);
