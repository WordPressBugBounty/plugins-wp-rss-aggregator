<?php

namespace RebelCode\Aggregator\Core;

use RebelCode\Aggregator\Core\RssReader\RssUtils;
use RebelCode\Aggregator\Core\RssReader\RssItem;

wpra()->addModule(
	'youtube',
	array(),
	function () {
		add_filter(
			'wpra.importer.post.url',
			function ( string $url ) {
				$isYt = stripos( $url, 'youtube.com' ) !== false;

				if ( ! $isYt ) {
					return $url;
				}

				add_filter(
					'wpra.importer.post.content',
					function ( string $content, RssItem $item, $src, IrPost $post ) {
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
							$queryArgs = array();
							$queryStr = wp_parse_url( $post->url, PHP_URL_QUERY ) ?: '';
							parse_str( $queryStr, $queryArgs );

							if ( ! empty( $queryArgs['v'] ) ) {
								$videoId = sanitize_text_field( $queryArgs['v'] );
								$embedUrl = esc_url( sprintf( 'https://www.youtube.com/embed/%s', $videoId ) );

								$iframe = sprintf(
									'<iframe width="560" height="315" src="%s" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>',
									$embedUrl
								);

								return $iframe . "\n" . wp_kses_post( $description );
							}
						}

						return wp_kses_post( $description );
					},
					10,
					4
				);

				add_filter(
					'wpra.importer.post.meta',
					function ( array $meta, IrPost $post, $item ) {
						$meta[ ImportedPost::IS_YT ] = true;

						$query = array();
						$queryStr = parse_url( $post->url, PHP_URL_QUERY ) ?: '';
						parse_str( $queryStr, $query );

						if ( $query && ! empty( $query['v'] ) ) {
							$videoId = $query['v'];
							$embedUrl = sprintf( 'https://youtube.com/embed/%s', $videoId );
							$meta[ ImportedPost::YT_VIDEO_ID ] = $videoId;
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

				return $url;
			}
		);
	}
);
