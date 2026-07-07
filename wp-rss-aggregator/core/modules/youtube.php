<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\RssReader\RssUtils;
use RebelCode\Aggregator\Core\RssReader\RssItem;

wpra()->addModule(
	'youtube',
	array(),
	function () {
		$extractVideoId = static function ( string $url ): ?string {
			$url = trim( $url );
			if ( $url === '' ) {
				return null;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) ) {
				return null;
			}

			$host  = strtolower( $parts['host'] ?? '' );
			$path  = $parts['path'] ?? '';
			$query = $parts['query'] ?? '';

			if ( $query !== '' ) {
				$queryArgs = array();
				parse_str( $query, $queryArgs );
				if ( ! empty( $queryArgs['v'] ) ) {
					return sanitize_text_field( $queryArgs['v'] );
				}
			}

			if ( $host !== '' && stripos( $host, 'youtu.be' ) !== false ) {
				$path = trim( $path, '/' );
				if ( $path !== '' ) {
					$parts = explode( '/', $path );
					return sanitize_text_field( $parts[0] );
				}
			}

			if ( preg_match( '~^/(shorts|embed|v|live)/([^/?#]+)~', $path, $matches ) ) {
				return sanitize_text_field( $matches[2] );
			}

			return null;
		};

		$getItemUrls = static function ( RssItem $item, IrPost $post ): array {
			$urls = array();

			if ( ! empty( $post->url ) ) {
				$urls[] = $post->url;
			}

			$permalink = $item->getPermalink();
			if ( $permalink ) {
				$urls[] = $permalink;
			}

			$links = $item->getLinks();
			if ( ! empty( $links ) ) {
				foreach ( $links as $link ) {
					if ( $link ) {
						$urls[] = $link;
					}
				}
			}

			$playerNodes = RssUtils::getPath(
				$item,
				array(
					'media:group',
					'media:player',
				)
			);

			if ( count( $playerNodes ) > 0 ) {
				foreach ( $playerNodes as $node ) {
					$playerUrl = $node->getAttr( '', 'url' );
					if ( $playerUrl ) {
						$urls[] = $playerUrl;
					}
				}
			}

			$contentNodes = RssUtils::getPath(
				$item,
				array(
					'media:group',
					'media:content',
				)
			);

			if ( count( $contentNodes ) > 0 ) {
				foreach ( $contentNodes as $node ) {
					$contentUrl = $node->getAttr( '', 'url' );
					if ( $contentUrl ) {
						$urls[] = $contentUrl;
					}
				}
			}

			return array_values( array_unique( array_filter( $urls ) ) );
		};

		$isYoutubeItem = static function ( RssItem $item, IrPost $post ) use ( $getItemUrls ): bool {
			foreach ( $getItemUrls( $item, $post ) as $candidateUrl ) {
				if (
					stripos( $candidateUrl, 'youtube.com' ) !== false
					|| stripos( $candidateUrl, 'youtu.be' ) !== false
				) {
					return true;
				}
			}

			return false;
		};

		add_filter(
			'wpra.importer.post.content',
			function ( string $content, RssItem $item, $src, IrPost $post ) use ( $extractVideoId, $getItemUrls, $isYoutubeItem ) {
				if ( ! $isYoutubeItem( $item, $post ) ) {
					return $content;
				}

				$descNodes = RssUtils::getPath(
					$item,
					array(
						'media:group',
						'media:description',
					)
				);

				$description = $content;
				if ( count( $descNodes ) > 0 ) {
					foreach ( $descNodes as $node ) {
						$desc = $node->getValue();
						if ( $desc ) {
							$description = $desc;
							break;
						}
					}
				}

				if ( $post->format === 'video' ) {
					$videoId = null;
					foreach ( $getItemUrls( $item, $post ) as $candidateUrl ) {
						$videoId = $extractVideoId( $candidateUrl );
						if ( $videoId ) {
							break;
						}
					}

					if ( $videoId ) {
						$embed_url    = sprintf( 'https://youtube.com/embed/%s', $videoId );
						$watch_url    = sprintf( 'https://www.youtube.com/watch?v=%s', $videoId );
						$is_gutenberg = function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post->type );
						$use_raw_url = apply_filters( 'wpra.youtube.raw_url', false, $videoId, $post );

						if ( $use_raw_url ) {
							$block = esc_url( $embed_url );
						} elseif ( $is_gutenberg ) {
							$block_content = sprintf(
								'<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n" . '%1$s' . "\n" . '</div></figure>',
								esc_url( $watch_url )
							);

							$block = serialize_block(
								array(
									'blockName'    => 'core/embed',
									'attrs'        => array(
										'url'  => esc_url( $watch_url ),
										'type' => 'video',
										'providerNameSlug' => 'youtube',
										'responsive' => true,
									),
									'innerBlocks'  => array(),
									'innerHTML'    => $block_content,
									'innerContent' => array( $block_content ),
								)
							);
						} else {
							$block = sprintf( '[embed]%s[/embed]', esc_url( $watch_url ) );
						}

						$body = trim( $content );
						if ( $body === '' ) {
							$body = wp_kses_post( $description );
						}

						return $block . "\n" . $body;
					}
				}

				if ( trim( $content ) !== '' ) {
					return $content;
				}

				return wp_kses_post( $description );
			},
			20,
			4
		);

		add_filter(
			'wpra.importer.post.meta',
			function ( array $meta, IrPost $post, RssItem $item ) use ( $extractVideoId, $getItemUrls, $isYoutubeItem ) {
				if ( ! $isYoutubeItem( $item, $post ) ) {
					return $meta;
				}

				$meta[ ImportedPost::IS_YT ] = true;

				$videoId = null;
				foreach ( $getItemUrls( $item, $post ) as $candidateUrl ) {
					$videoId = $extractVideoId( $candidateUrl );
					if ( $videoId ) {
						break;
					}
				}

				if ( $videoId ) {
					$embedUrl                           = sprintf( 'https://youtube.com/embed/%s', $videoId );
					$meta[ ImportedPost::YT_VIDEO_ID ]  = $videoId;
					$meta[ ImportedPost::YT_EMBED_URL ] = $embedUrl;
				}

				$statNodes = RssUtils::getPath(
					$item,
					array(
						'media:group',
						'media:community',
						'media:statistics',
					)
				);

				if ( count( $statNodes ) > 0 ) {
					foreach ( $statNodes as $node ) {
						$views = $node->getAttr( '', 'views' );
						if ( $views && is_numeric( $views ) ) {
							$meta[ ImportedPost::YT_VIEWS ] = (int) $views;
							break;
						}
					}
				}

				return $meta;
			},
			10,
			3
		);
	}
);
