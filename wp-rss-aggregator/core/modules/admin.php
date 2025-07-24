<?php

namespace RebelCode\Aggregator\Core;

use WP_Taxonomy;
use RebelCode\WpSdk\Wp\Style;
use RebelCode\WpSdk\Wp\ScriptL10n;
use RebelCode\WpSdk\Wp\Script;
use RebelCode\WpSdk\Wp\AdminSubMenu;
use RebelCode\WpSdk\Wp\AdminPage;
use RebelCode\WpSdk\Wp\AdminMenu;
use RebelCode\Aggregator\Core\Utils\WpUtils;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Rpc\RpcServer;

wpra()->addModule(
	'admin.frame',
	array( 'admin.frame.l10n' ),
	function ( callable $getL10n ) {
		$wpra = wpra();

		$js = new Script( 'wpra-admin-frame', $wpra->url . '/core/js/dist/admin.js', uniqid(), array( 'react', 'react-dom', 'wp-i18n' ) );
		$css = new Style( 'wpra-admin-frame', $wpra->url . '/core/css/admin-frame.css', uniqid(), array( 'wpra-displays' ) );

		add_action(
			'admin_init',
			function () use ( $wpra, $js, $css, $getL10n ) {
				$css->register();
				$js->register();
				$getL10n()->localizeFor( $js->id );

				$arg = filter_input( INPUT_GET, 'wpra-admin-ui', FILTER_VALIDATE_INT );
				if ( $arg === 1 ) {
					require $wpra->path . '/core/admin-frame.php';
					die( 0 );
				}
			}
		);

		add_filter(
			'wpra.admin.frame.head',
			function ( string $output ) use ( $css ) {
				ob_start();
				wp_styles()->do_items( array( $css->id ) );
				return $output . ob_get_clean();
			}
		);

		add_filter(
			'wpra.admin.frame.body.end',
			function ( string $output ) use ( $js ) {
				ob_start();
				wp_scripts()->do_items( array( $js->id ) );
				return $output . ob_get_clean();
			}
		);

		return sprintf(
			'<iframe id="wpra-admin-ui-frame" src="%s"></iframe>',
			admin_url( '?wpra-admin-ui=1' ),
		);
	}
);

wpra()->addModule(
	'admin.shell',
	array( 'admin.frame', 'importer' ),
	function ( string $frame ) {
		$wpra = wpra();

		$slug = 'aggregator';
		$url = admin_url( "admin.php?page={$slug}" );
		$page = "toplevel_page_{$slug}";

		$css = new Style( 'wpra-admin-shell', $wpra->url . '/core/css/admin-shell.css', uniqid() );
		$js = new Script( 'wpra-admin-shell', $wpra->url . '/core/js/admin-shell.js', uniqid() );

		add_action(
			'admin_enqueue_scripts',
			function ( string $hookSuffix ) use ( $wpra, $page, $js, $css ) {
				wp_enqueue_style( 'wpra-admin', $wpra->url . '/core/css/admin.css' );

				if ( $hookSuffix === $page ) {
					$js->enqueue();
					$css->enqueue();
					wp_enqueue_media();
				}
			}
		);

		add_action(
			'admin_menu',
			function () use ( $wpra, $frame, $slug, $url ) {
				$subUrl = $url . '&subPage=';
				$cap = Capabilities::SEE_AGGREGATOR;
				$svg = file_get_contents( $wpra->path . '/core/icons/admin-menu-icon.svg' );
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );

				$count = apply_filters( 'wpra.admin.menu.badge', '' );
				$display = empty( $count ) ? 'none' : 'inline-block';
				$badge = <<<HTML
            <span class="update-plugins wpra-shell-menu-badge" style="display: {$display}">
                <span class="plugins-count">{$count}</span>
            </span>
            HTML;

				$page = new AdminPage( _x( 'Aggregator', 'wp-rss-aggregator' ), fn () => $frame );
				$menu = new AdminMenu( $page, $slug, __( 'Aggregator', 'wp-rss-aggregator' ), $cap, $icon );

				if ( $wpra->getState() === State::Normal ) {
					$menu->items = array(
						AdminSubMenu::forUrl( $subUrl . 'hub', __( 'Hub', 'wp-rss-aggregator' ) . $badge, $cap ),
						AdminSubMenu::forUrl( $subUrl . 'sources', __( 'Sources', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'displays', __( 'Displays', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'folders', __( 'Folders', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'integrations', __( 'Integrations', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'settings', __( 'Settings', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'help', __( 'Help', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'tutorials', __( 'Tutorials', 'wp-rss-aggregator' ), $cap ),
						AdminSubMenu::forUrl( $subUrl . 'upgrade', __( 'Manage Plan', 'wp-rss-aggregator' ), $cap ),
					);

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						$menu->items[] = AdminSubMenu::forUrl( $subUrl . 'wpra-logger', __( 'Logs', 'wp-rss-aggregator' ), $cap );
					}
				}

				$menu->register();
			}
		);
	}
);

wpra()->addModule(
	'admin.frame.l10n',
	array( 'rpc', 'settings', 'licensing' ),
	function ( RpcServer $rpc, Settings $settings, Licensing $licensing ) {
		return function () use ( $rpc, $settings, $licensing ) {
			$wpra = wpra();
			$user = wp_get_current_user();
			$license = $licensing->getLicense();
			$tier = $licensing->getTier();

			$l10n = apply_filters(
				'wpra.admin.frame.l10n',
				array(
					'rpcNonce' => $rpc->getNonce(),
					'state' => $wpra->getState(),
					'v4MigrationState' => get_option( 'wpra_did_v4_migration', false ),
					'settings' => $settings->toArray(),
					'license' => $license ? $license->toArray() : null,
					'premiumInstalled' => $wpra->premiumInstalled,
					'sslCertPath' => implode( '/', array( WPINC, 'certificates', 'ca-bundle.crt' ) ),
					'isMultiSite' => WpUtils::isMultiSite(),
					'isMainSite' => is_main_site(),
					'hasV4Data' => $wpra->hasV4Data(),
					'sites' => WpUtils::getSites(),
					'plans' => $licensing->plans,
					'urls' => array(
						'frame' => rtrim( admin_url( 'admin.php?page=aggregator' ), '/' ),
						'assets' => array(
							'imgs' => $wpra->url . '/core/imgs',
						),
						'wp' => array(
							'site' => rtrim( site_url(), '/' ),
							'admin' => rtrim( admin_url(), '/' ),
							'rest' => rtrim( rest_url(), '/' ),
							'ajax' => rtrim( admin_url(), '/' ) . '/admin-ajax.php',
						),
						'website' => array(
							'docs' => 'https://www.wprssaggregator.com/help/',
							'blog' => 'https://wprssaggregator.com/blog',
							'faqs' => 'https://kb.wprssaggregator.com/category/359-faqs',
							'pricing' => 'https://wprssaggregator.com/pricing',
							'upgrade' => $tier >= Tier::Basic ? 'https://www.wprssaggregator.com/account/upgrades/' : 'https://www.wprssaggregator.com/upgrade',
							'contact' => 'https://wprssaggregator.com/contact',
							'forum' => 'https://wordpress.org/support/plugin/wp-rss-aggregator',
							'integrations' => 'https://www.wprssaggregator.com/integrations/',
							'feature' => 'https://www.wprssaggregator.com/feature-requests/',
						),
						'licensing' => array(
							'store' => $licensing->storeUrl,
							'checkout' => $licensing->storeUrl . '/checkout',
							'account' => $licensing->storeUrl . '/account',
						),
					),
					'user' => array(
						'id' => $user->ID,
						'displayName' => $user->display_name,
						'firstName' => $user->first_name,
						'email' => $user->user_email,
					),
					'taxonomies' => array(
						'all' => array_values(
							Arrays::map(
								get_taxonomies( array( 'public' => true ), 'objects' ),
								function ( WP_Taxonomy $taxonomy ) {
									if ( $taxonomy->name === 'post_format' ) {
										return Arrays::skip();
									}
									return array(
										'slug' => $taxonomy->name,
										'labels' => array(
											'singular' => $taxonomy->labels->singular_name,
											'plural' => $taxonomy->labels->name,
										),
										'postTypes' => $taxonomy->object_type,
									);
								},
							)
						),
					),
					'postTypes' => array_values(
						array_map(
							fn ( $postType ) => array(
								'slug' => $postType->name,
								'labels' => array(
									'singular' => $postType->labels->singular_name,
									'plural' => $postType->label,
								),
							),
							get_post_types( array( 'public' => true ), 'objects' )
						)
					),
					'tokenTypes' => array(),
					'moduleGraph' => $wpra->getModuleGraph(),
					'prevVersion' => get_option( 'wprss_prev_update_page_version', '' ),
				)
			);

			return new ScriptL10n( 'WpraAdminConfig', $l10n );
		};
	}
);
