<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\Rpc\RpcServer;

wpra()->addModule(
	'aiHubPromotionalNotice',
	array( 'licensing', 'rpc' ),
	function ( Licensing $licensing, RpcServer $rpc ) {
		$dismissAction = 'wpra_dismiss_ai_hub_promotional_notice';

		$allowNoticeHtml = function ( string $html ): string {
			return wp_kses(
				$html,
				array(
					'a' => array(
						'href' => true,
						'rel' => true,
						'target' => true,
					),
					'strong' => array(),
					'em' => array(),
				)
			);
		};

		$getNotice = function ( int $tier ) use ( $allowNoticeHtml ): ?array {
			$learnMoreUrl = 'https://www.wprssaggregator.com/help-topics/ai-rewriting/';
			$pricingUrl = 'https://www.wprssaggregator.com/upgrade/?utm_source=plugin_hub&utm_medium=notice&utm_campaign=ai-features';
			$upgradeUrl = 'https://www.wprssaggregator.com/account/upgrades/?utm_source=plugin_hub&utm_medium=notice&utm_campaign=ai-features';

			if ( $tier === Tier::Elite ) {
				return array(
					'title' => __( 'Your imported articles can now rewrite themselves into something new.', 'wp-rss-aggregator' ),
					'body' => $allowNoticeHtml(
						__( 'Aggregator’s AI rewrites your feeds’ articles into original pieces. <strong>Eliminate duplicate content</strong> and automatically publish unique pieces on a regular basis.', 'wp-rss-aggregator' )
					),
					'action' => $allowNoticeHtml(
						sprintf(
							/* translators: %1$s: AI Rewriting help URL */
							__( 'Start using it now from the <em>Advanced → AI Rewriting</em> source settings. It’s included in your plan at <strong>no extra cost.</strong> <a href="%1$s" target="_blank" rel="noopener noreferrer">Learn more</a>', 'wp-rss-aggregator' ),
							esc_url( $learnMoreUrl )
						)
					),
					'dismissedKey' => 'aiHubPromotionalIncludedNoticeDismissed',
					'pointer' => 'wpra_ai_hub_promotional_included_notice',
				);
			}

			if ( $tier === Tier::Pro ) {
				return array(
					'title' => __( 'Want your imported articles to become new, original content?', 'wp-rss-aggregator' ),
					'body' => $allowNoticeHtml(
						__( 'Aggregator can rewrite the articles from your feeds into original pieces, ready to publish and unique to your site, with <strong>no more duplicate content.</strong>', 'wp-rss-aggregator' )
					),
					'action' => $allowNoticeHtml(
						sprintf(
							/* translators: %1$s: account upgrades URL */
							__( 'AI Rewriting is part of the Elite plan, along with <strong>20x more AI credits.</strong> <strong><a href="%1$s" target="_blank" rel="noopener noreferrer">Upgrade to get instant access</a></strong>', 'wp-rss-aggregator' ),
							esc_url( $upgradeUrl )
						)
					),
					'dismissedKey' => 'aiHubPromotionalUpsellNoticeDismissed:' . $tier,
					'pointer' => 'wpra_ai_hub_promotional_upsell_notice_' . $tier,
				);
			}

			if ( in_array( $tier, array( Tier::Free, Tier::Basic, Tier::Plus ), true ) ) {
				$url = $tier === Tier::Free ? $pricingUrl : $upgradeUrl;

				return array(
					'title' => __( 'Automatically rewrite imported articles into something new and original. No more duplicate content.', 'wp-rss-aggregator' ),
					'body' => $allowNoticeHtml(
						__( 'Pro and Elite plans can now automatically rewrite imported articles into unique, ready-to-publish pieces, with <strong>no extra setup or charges.</strong>', 'wp-rss-aggregator' )
					),
					'action' => $allowNoticeHtml(
						sprintf(
							/* translators: %1$s: upgrade URL */
							__( 'Want to try it out? <strong><a href="%1$s" target="_blank" rel="noopener noreferrer">Upgrade to get instant access</a></strong>', 'wp-rss-aggregator' ),
							esc_url( $url )
						)
					),
					'dismissedKey' => 'aiHubPromotionalUpsellNoticeDismissed:' . $tier,
					'pointer' => 'wpra_ai_hub_promotional_upsell_notice_' . $tier,
				);
			}

			return null;
		};

		$dismissNotice = function ( string $pointer ): void {
			$dismissed = array_filter( explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) ) );
			if ( ! in_array( $pointer, $dismissed, true ) ) {
				$dismissed[] = $pointer;
			}

			update_user_meta( get_current_user_id(), 'dismissed_wp_pointers', implode( ',', $dismissed ) );
		};

		add_action(
			'wp_ajax_' . $dismissAction,
			function () use ( $getNotice, $dismissNotice ) {
				check_ajax_referer( RpcServer::NONCE_ACTION, 'nonce' );

				if ( ! current_user_can( Capabilities::SEE_AGGREGATOR ) ) {
					wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-rss-aggregator' ) ), 403 );
				}

				$tier = absint( filter_input( INPUT_POST, 'tier', FILTER_DEFAULT ) );
				$notice = $getNotice( $tier );

				if ( null === $notice ) {
					wp_send_json_error( array( 'message' => __( 'Invalid promotional notice state.', 'wp-rss-aggregator' ) ), 400 );
				}

				$dismissNotice( (string) $notice['pointer'] );
				wp_send_json_success();
			}
		);

		add_action(
			'admin_notices',
			function () use ( $licensing, $getNotice, $dismissAction, $rpc ) {
				if ( ! current_user_can( Capabilities::SEE_AGGREGATOR ) ) {
					return;
				}

				$screen = get_current_screen();
				if ( ! $screen || $screen->id === 'toplevel_page_wprss-aggregator' ) {
					return;
				}

				$license = $licensing->getLicense();
				$tier = $license ? $license->tier : Tier::Free;
				$notice = $getNotice( $tier );
				if ( $notice === null ) {
					return;
				}

				$dismissed = get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true );
				if ( in_array( $notice['pointer'], explode( ',', (string) $dismissed ), true ) ) {
					return;
				}

				$nonce = $rpc->getNonce();

				?>
				<div
					class="notice is-dismissible wpra-ai-hub-promotional-notice"
					data-dismissed-key="<?php echo esc_attr( $notice['dismissedKey'] ); ?>"
					data-pointer="<?php echo esc_attr( $notice['pointer'] ); ?>"
					data-tier="<?php echo esc_attr( (string) $tier ); ?>"
				>
					<div class="wpra-ai-hub-promotional-notice-icon">
						<img src="<?php echo esc_url( WPRA_URL . 'core/imgs/ai-summaries.svg' ); ?>" alt="" />
					</div>

					<div class="wpra-ai-hub-promotional-notice-content">
						<h3><?php echo esc_html( $notice['title'] ); ?></h3>
						<p><?php echo wp_kses_post( $notice['body'] ); ?></p>
						<p><?php echo wp_kses_post( $notice['action'] ); ?></p>
					</div>
				</div>
				<?php

				$script = "
				<script>
					jQuery( function( $ ) {
						$( '.wpra-ai-hub-promotional-notice' ).each( function() {
							var notice = $( this );
							var dismissedKey = notice.data( 'dismissed-key' );

							if ( window.localStorage && localStorage.getItem( dismissedKey ) === 'true' ) {
								$.post( ajaxurl, {
									action: " . wp_json_encode( $dismissAction ) . ",
									nonce: " . wp_json_encode( $nonce ) . ",
									tier: notice.data( 'tier' )
								} );
								notice.remove();
								return;
							}

							notice.on( 'click', '.notice-dismiss', function() {
								if ( window.localStorage ) {
									localStorage.setItem( dismissedKey, 'true' );
								}

								$.post( ajaxurl, {
									action: " . wp_json_encode( $dismissAction ) . ",
									nonce: " . wp_json_encode( $nonce ) . ",
									tier: notice.data( 'tier' )
								} );
							} );
						} );
					} )
				</script>";

				$style = '
				<style type="text/css">
				.wpra-ai-hub-promotional-notice {
					position: relative;
					display: flex;
					gap: 20px;
					padding: 0 38px 0 0;
					border-top: 1px solid #CCC;
					border-right: 1px solid #CCC;
					border-bottom: 1px solid #CCC;
					border-left: 0;
					background: #fff;
					box-shadow: none;
				}
				.wpra-ai-hub-promotional-notice-icon {
					display: flex;
					align-items: flex-start;
					justify-content: center;
					width: 44px;
					padding: 28px 12px 12px;
					border-left: 5px solid #7A00DF;
					background: #FAF5FE;
					color: #7A00DF;
					flex-shrink: 0;
				}
				.wpra-ai-hub-promotional-notice-icon img {
					width: 25px;
					height: 25px;
				}
				.wpra-ai-hub-promotional-notice-content {
					display: flex;
					flex-direction: column;
					gap: 16px;
					padding: 24px 0;
				}
				.wpra-ai-hub-promotional-notice h3 {
					margin: 0;
					font-size: 14px;
					font-weight: 600;
					line-height: 1.4;
					color: #1E1E1E;
				}
				.wpra-ai-hub-promotional-notice p {
					margin: 0;
					font-size: 13px;
					line-height: 1.35;
					color: #2F2F2F;
				}
				.wpra-ai-hub-promotional-notice a {
					color: #14248A;
					text-decoration: underline;
				}
				.wpra-ai-hub-promotional-notice .notice-dismiss {
					top: 8px;
					right: 8px;
				}
				</style>';

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline admin JS is intentionally emitted for dismiss behavior.
				echo $script;
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline admin CSS is intentionally emitted for notice styling.
				echo $style;
			}
		);
	}
);
