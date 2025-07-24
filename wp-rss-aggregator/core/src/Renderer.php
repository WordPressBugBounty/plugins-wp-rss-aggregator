<?php

namespace RebelCode\Aggregator\Core;

use WP_Post;
use Throwable;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Store\WpPostsStore;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\DisplaysStore;
use RebelCode\Aggregator\Core\Display\ListLayout;
use RebelCode\Aggregator\Core\Display\LayoutInterface;
use RebelCode\Aggregator\Core\Display\DisplayState;
use RebelCode\Aggregator\Core\Display\DisplaySettings;
use ArrayObject;

class Renderer {

	private Database $db;
	public SourcesStore $sources;
	public WpPostsStore $wpPosts;
	public DisplaysStore $displays;
	/** @var array<string,callable(DisplaySettings):LayoutInterface */
	public array $layouts = array();

	/**
	 * @param array<string,callable(DisplaySettings):LayoutInterface $layouts
	 *        A mapping of layout IDs to functions that display settings as an
	 *        argument and return a layout instance.
	 */
	public function __construct(
		Database $db,
		SourcesStore $sources,
		WpPostsStore $wpPosts,
		DisplaysStore $displays,
		array $layouts = array()
	) {
		$this->db = $db;
		$this->sources = $sources;
		$this->wpPosts = $wpPosts;
		$this->displays = $displays;
		$this->layouts = $layouts;
	}

	/**
	 * Add a new layout type.
	 *
	 * @param string                                    $id The ID of the layout.
	 * @param callable(DisplaySettings):LayoutInterface $factory A factory
	 *        function that takes display settings as an argument and returns
	 *        the layout instance.
	 */
	public function addLayout( string $id, callable $factory ): self {
		$this->layouts[ $id ] = $factory;
		return $this;
	}

	/**
	 * Renders a display from a set of arguments.
	 *
	 * @param array<string,mixed> $args The render arguments.
	 * @return string The rendered HTML.
	 */
	public function renderArgs( array $args, $type = 'block' ): string {
		$result = $this->parseArgs( $args, $type );
		if ( $result->isErr() ) {
			return $this->adminMessage( $result->error()->getMessage() );
		}

		[$display, $page] = $result->get();
		return $this->renderDisplay( $display, $page, $args, $type );
	}

	/**
	 * Renders a display.
	 *
	 * @param Display              $display The display to render.
	 * @param int                  $page The page to render.
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return string The rendered HTML.
	 */
	public function renderDisplay( Display $display, int $page = 1, array $attributes = array(), $type = 'block' ): string {
		$num = $display->settings->numItems;
		// Apply block or shortcode specific overrides for limit and pagination
		if ( 'shortcode' === $type || 'block' === $type ) {
			if ( isset( $attributes['limit'] ) && is_numeric( $attributes['limit'] ) ) {
				$num = $display->settings->numItems = (int) $attributes['limit'];
			}

			if ( isset( $attributes['pagination'] ) ) {
				// For blocks, pagination is boolean. For shortcodes, it's 'on'/'off'.
				if ( 'block' === $type ) {
					$display->settings->enablePagination = filter_var( $attributes['pagination'], FILTER_VALIDATE_BOOLEAN );
				} elseif ( 'shortcode' === $type ) {
					if ( $attributes['pagination'] === 'on' ) {
						$display->settings->enablePagination = true;
					} elseif ( $attributes['pagination'] === 'off' ) {
						$display->settings->enablePagination = false;
					}
				}
			}
		}

		if ( $num < 1 ) {
			return $this->adminMessage(
				__( 'The display is set to show 0 items.', 'wprss' ),
			);
		}

		$result = $this->queryDisplay( $display, $page );

		if ( $result->isErr() ) {
			return $this->adminMessage(
				__( 'Failed to get the posts for this display.', 'wprss' ),
			);
		}
		$posts = $result->get();

		$total = $this->queryTotal( $display )->getOr( $num );
		$numPages = ceil( $total / $num );
		$state = new DisplayState( $page, $numPages, $total );

		$layout = $this->createLayout( $display->settings->layout, $display->settings );

		$styleId = $layout->getStyleId();
		if ( $styleId !== null ) {
			wp_enqueue_style( $styleId );
		}

		$scriptId = $layout->getScriptId();
		if ( $scriptId !== null ) {
			wp_enqueue_script( $scriptId );
		}

		$align = isset( $attributes['align'] ) ? esc_attr( $attributes['align'] ) : '';

		return sprintf(
			'<div class="wpra-display align%s" data-display-id="%d" hx-target="this" hx-swap="outerHTML">%s %s</div>',
			$align,
			$display->id ?? '',
			$layout->render( $posts, $state ),
			$this->renderPagination( $display, $state ),
		);
	}

	/**
	 * Embeds a display in a post using a shortcode or block.
	 *
	 * @param int    $displayId The ID of the display.
	 * @param string $title The title of the post.
	 * @param string $postType The type of post to create.
	 * @return Result<int> The ID of the created post.
	 */
	public function embed( int $displayId, string $title, string $postType = 'page' ): Result {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require ABSPATH . 'wp-admin/includes/post.php';
		}

		if ( use_block_editor_for_post_type( $postType ) ) {
			$content = '<!-- wp:wpra-shortcode/wpra-shortcode {"id":' . $displayId . '} /-->';
		} else {
			$content = '[wp-rss-aggregator id="' . $displayId . '"]';
		}

		$postArgs = array(
			'post_type' => $postType,
			'post_title' => $title,
			'post_content' => $content,
		);

		$postId = wp_insert_post( $postArgs );

		if ( is_wp_error( $postId ) ) {
			return Result::Err( $postId->get_error_message() );
		}

		return Result::Ok( $postId );
	}

	public function createLayout( string $layoutId, DisplaySettings $settings ): LayoutInterface {
		if ( array_key_exists( $layoutId, $this->layouts ) ) {
			return call_user_func( $this->layouts[ $layoutId ], $settings, $this->sources );
		}

		return new ListLayout( $settings, $this->sources );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return Result<array{0:Display,1:int}>
	 */
	private function parseArgs( array $args, $type = 'block' ): Result {
		$id = trim( $args['id'] ?? '' );

		// Remove v4 args when display id is set, but preserve block-level overrides.
		if ( 'block' === $type && ! empty( $id ) ) {
			$preserved_args = array(
				'id' => $id,
				'align' => $args['align'] ?? null,
				'limit' => $args['limit'] ?? null,
				'pagination' => $args['pagination'] ?? null,
			);
			// Filter out null values to keep $args clean
			$args = array_filter($preserved_args, fn($value) => $value !== null);
		}

		$v4Slug = trim( $args['template'] ?? '' );
		$display = new Display( null );

		if ( ! empty( $v4Slug ) ) {
			$result = $this->displays->getByV4Slug( $v4Slug );
			if ( $result->isErr() ) {
				return $result;
			}
			$display = $result->get();
		} elseif ( empty( $id ) ) {
			$displays = $this->displays->getList( '', 1, 1, 'asc', 'id' )->getOr( array() );
			// Try to load the migrated default display ID
			$defaultDisplayId = get_option( 'wpra_default_display_id' );
			if ( ! empty( $defaultDisplayId ) ) {
				$defaultDisplayResult = $this->displays->getById( (int) $defaultDisplayId );
				if ( $defaultDisplayResult->isOk() ) {
					$display = $defaultDisplayResult->get();
				} else {
					// Default display ID is set but not found, fall back to first display
					// Optionally, log an admin notice here if desired
					$display = Arrays::first( $displays )->getOr( $display );
				}
			} else {
				// No default display ID set, fall back to first display
				$display = Arrays::first( $displays )->getOr( $display );
			}
		} elseif ( ! is_numeric( $id ) ) {
			return Result::Err( __( 'Invalid display ID.', 'wpra' ) );
		} else {
			$result = $this->displays->getById( (int) $id );
			if ( $result->isErr() ) {
				return $result;
			} else {
				$display = $result->get();
			}
		}

		assert( $display instanceof Display );

		// Process exclusions first
		$excludeSrcsRaw = explode( ',', $args['exclude'] ?? '' );
		$excludeSrcsInput = array_filter( array_map( 'trim', $excludeSrcsRaw ), 'is_numeric' );
		if (!empty($excludeSrcsInput)) {
			$v4IdMapExclude = $this->sources->resolveV4Ids( $excludeSrcsInput )->getOr( array() );
			$v4IdsExclude = array_keys( $v4IdMapExclude );
			$v5IdsFromV4Exclude = array_values( $v4IdMapExclude );
			$originalV5IdsExclude = array_diff( $excludeSrcsInput, $v4IdsExclude );
			$display->settings->excludeSrcs = array_unique(
				array_merge(
					array_map( 'intval', $originalV5IdsExclude ),
					array_map( 'intval', $v5IdsFromV4Exclude )
				)
			);
		} else {
			// If 'exclude' is not in $args, keep existing display settings (if any)
			// or ensure it's an empty array if not set.
			$display->settings->excludeSrcs = $display->settings->excludeSrcs ?? [];
		}

		// Process sources: if any source-defining attributes are in $args,
		// they override any sources set on the loaded $display.
		$sourceArg = $args['source'] ?? '';
		$sourcesArg = $args['sources'] ?? '';
		$feedsArg = $args['feeds'] ?? '';

		if ( ! empty( $sourceArg ) || ! empty( $sourcesArg ) || ! empty( $feedsArg ) ) {
			$display->sources = []; // Reset sources if specified in args

			$sourceIdsInput = [];
			$sourceExploded = explode( ',', $sourceArg );
			$sourcesExploded = explode( ',', $sourcesArg );

			foreach ( array_merge( $sourceExploded, $sourcesExploded ) as $srcId ) {
				$srcId = trim( $srcId );
				if ( is_numeric( $srcId ) ) {
					$sourceIdsInput[] = (int) $srcId;
				}
			}

			$feedSlugsInput = [];
			$feedsExploded = explode( ',', $feedsArg );
			foreach ( $feedsExploded as $slug ) {
				$slug = trim( $slug );
				if ( ! empty( $slug ) ) {
					$feedSlugsInput[] = $slug;
				}
			}
			$v4SourcesFromFeeds = $this->sources->getManyByV4Slugs( $feedSlugsInput )->getOr( array() );
			foreach ( $v4SourcesFromFeeds as $src ) {
				$sourceIdsInput[] = $src->id;
			}

			$display->sources = array_unique($sourceIdsInput);
		}
		// If no source args, $display->sources remains as loaded (or default empty).

		// Resolve V4 IDs for the final list of sources
		if ( ! empty( $display->sources ) ) {
			$v4IdMap = $this->sources->resolveV4Ids( $display->sources )->getOr( array() );
			foreach ( $v4IdMap as $v4Id => $v5Id ) {
				$display->sources = Arrays::replace( $display->sources, $v4Id, $v5Id );
			}
		}

		$categories = explode( ',', $args['category'] ?? '' );
		$folders = explode( ',', $args['folders'] ?? '' );
		foreach ( array_merge( $categories, $folders ) as $folderName ) {
			$folderName = trim( $folderName );
			if ( ! empty( $folderName ) ) {
				$display->folders[] = $folderName;
			}
		}

		$className1 = trim( $args['className'] ?? '' );
		$className2 = trim( $display->settings->htmlClass ?? '' );
		$display->settings->htmlClass = trim( $className1 . ' ' . $className2 );

		$page = max( 1, $args['page'] ?? 1 );

		$display = apply_filters( 'wpra.renderer.parseArgs', $display, $args );

		// When it's not v4 block and display is not selected we go for empty state.
		if ( empty( $display ) && empty( $v4Slug ) && empty( $id ) ) {
			return Result::Err( __( 'Please select a display from the block settings →', 'wpra' ) );
		}

		return Result::Ok( array( $display, $page ) );
	}

	private function adminMessage( string $s ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return $s;
	}

	/** Renders the pagination, if enabled and applicable. */
	private function renderPagination( Display $display, DisplayState $state ): string {
		if ( ! $display->settings->enablePagination || $state->numPages <= 1 ) {
			return '';
		}

		if ( $display->settings->paginationStyle === 'numbered' ) {
			return $this->numberedPagination( $display, $state );
		}

		return $this->defaultPagination( $display, $state );
	}

	/** Renders the default "Older/Newer" pagination links. */
	private function defaultPagination( Display $display, DisplayState $state ): string {
		$older = '';
		if ( $state->page < $state->numPages ) {
			$nextPage = $state->page + 1;
			$nextText = __( 'Older posts', 'wprss' );
			$older = <<<HTML
                <div class="nav-previous alignleft">
                    {$this->pageLink($display,$nextPage,$nextText)}
                </div>
            HTML;
		}

		$newer = '';
		if ( $state->page > 1 ) {
			$prevPage = $state->page - 1;
			$prevText = __( 'Newer posts', 'wprss' );
			$newer = <<<HTML
                <div class="nav-next alignright">
                    {$this->pageLink($display,$prevPage,$prevText)}
                </div>
            HTML;
		}

		return <<<HTML
            <div class="nav-links wpra-nav-links wpra-default-nav-links">
                {$older}
                {$newer}
            </div>
        HTML;
	}

	/** Renders the numbered pagination links. */
	private function numberedPagination( Display $display, DisplayState $state ): string {
		$prev = $leftDots = $middle = $rightDots = $next = '';

		if ( $state->page > 1 ) {
			$prevPage = $state->page - 1;
			$prevText = __( 'Previous', 'wprss' );
			$prev = <<<HTML
                <div class="nav-previous alignleft wpra-feed-prev-page">
                    {$this->pageLink($display,$prevPage,$prevText)}
                </div>
            HTML;
		}

		if ( $state->page < $state->numPages ) {
			$nextPage = $state->page + 1;
			$nextText = __( 'Next', 'wprss' );
			$next = <<<HTML
                <div class="nav-next alignleft wpra-feed-next-pages">
                    {$this->pageLink($display,$nextPage,$nextText)}
                </div>
            HTML;
		}

		$leftPage = max( 1, $state->page - 2 > 0 ? $state->page - 2 : 1 );
		$rightPage = min( $state->numPages, $state->page + 2 );

		if ( $leftPage !== 1 ) {
			$leftDots = '<span class="alignleft wpra-feed-more-pages">…</span>';
		}

		if ( $rightPage !== $state->numPages ) {
			$rightDots = '<span class="alignleft wpra-feed-more-pages">…</span>';
		}

		$pages = range( $leftPage, $rightPage );
		$middle = '';

		foreach ( $pages as $page ) {
			if ( $page === $state->page ) {
				$middle .= <<<HTML
                    <span class="alignleft wpra-feed-current-page">
                        {$page}
                    </span>
                HTML;
				continue;
			}
			$middle .= <<<HTML
                <div class="nav-next alignleft wpra-feed-page">
                    {$this->pageLink($display,$page,$page)}
                </div>
            HTML;
		}

		return <<<HTML
            <div class="nav-links wpra-nav-links numbered">
                {$prev}
                {$leftDots}
                {$middle}
                {$rightDots}
                {$next}
            </div>
        HTML;
	}

	private function pageLink( Display $display, int $page, string $text ): string {
		$url = esc_attr( rtrim( admin_url(), '/' ) . '/admin-ajax.php' );

		$shortcode_args = array(
			'id' => $display->id ?? 0,
			'page' => $page,
		);

		if ( ! empty( $display->sources ) ) {
			$shortcode_args['sources'] = implode( ',', $display->sources );
		}

		if ( ! empty( $display->settings->excludeSrcs ) ) {
			$shortcode_args['exclude'] = implode( ',', $display->settings->excludeSrcs );
		}

		// numItems is the effective limit, potentially overridden by shortcode 'limit'
		if ( isset( $display->settings->numItems ) ) {
			$shortcode_args['limit'] = $display->settings->numItems;
		}

		// Persist pagination enable/disable status if it was set
		// In renderDisplay, $display->settings->enablePagination is modified by shortcode 'pagination'
		if (isset($display->settings->enablePagination)) {
			$shortcode_args['pagination'] = $display->settings->enablePagination ? 'on' : 'off';
		}

        // If there's a V4 slug associated with the display, persist it.
        // parseArgs uses 'template' to load a display.
        // If an 'id' is present, 'id' takes precedence for loading, but 'template' might
        // still be used by filters or other logic in parseArgs if present.
        if (!empty($display->v4Slug)) {
            $shortcode_args['template'] = $display->v4Slug;
        }

		$vals_data = array(
			'action' => 'wpra.render.display',
			// The 'data' key matches what the AJAX handler in core/modules/renderer.php expects
			'data' => $shortcode_args,
		);

		$vals = esc_attr( json_encode( $vals_data ) );

		return <<<HTML
            <a data-wpra-page="{$page}" hx-post="{$url}" hx-vals="{$vals}">
                {$text}
            </a>
        HTML;
	}

	private function queryDisplay( Display $display, int $page = 1 ): Result {
		$num = $display->settings->numItems;
		if ( $num < 1 ) {
			return Result::Ok( array() );
		}

		/** @var \wpdb $wpdb */
		global $wpdb;

		[$where, $args] = $this->buildQueryWhere( $display );
		$pagination = $this->db->pagination( $num, $page );

		$sql = "SELECT * FROM {$wpdb->posts}
                LEFT JOIN {$wpdb->postmeta} AS `m` ON `m`.`post_id` = `ID`
                WHERE {$where}
                GROUP BY `ID`
                ORDER BY `post_date` DESC
                {$pagination}";

		try {
			$rows = $this->db->getResults( $sql, $args );

			$irPosts = array();
			foreach ( $rows as $row ) {
				$postObj = (object) sanitize_post( $row, 'raw' );
				$post = new WP_Post( $postObj );
				$irPosts[] = IrPost::fromWpPost( $post );
			}

			return Result::Ok( $irPosts );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/** @return Result<int> */
	private function queryTotal( Display $display ): Result {
		/** @var \wpdb $wpdb */
		global $wpdb;

		[$where, $args] = $this->buildQueryWhere( $display );

		$sql = "SELECT COUNT(`ID`) as `count` FROM {$wpdb->posts}
                LEFT JOIN {$wpdb->postmeta} AS `m` ON `m`.`post_id` = `ID`
                WHERE {$where}";

		try {
			$row = $this->db->getRow( $sql, $args );
			$row ??= array();
			$count = (int) ( $row['count'] ?? 0 );

			return Result::Ok( $count );
		} catch ( Throwable $err ) {
			return Result::Err( $err );
		}
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private function buildQueryWhere( Display $display, array &$args = array() ): array {
		$whereList = array( '`m`.`meta_key` = %s' );
		$args[] = ImportedPost::SOURCE;

		$srcIds = apply_filters( 'wpra.renderer.display.sources', $display->sources, $display );
		if ( ! empty( $srcIds ) ) {
			$srcIdList = $this->db->prepareList( $srcIds, '', $args );
			$whereList[] = "(`m`.`meta_value` IN ({$srcIdList}))";
		}

		$excludeIds = apply_filters( 'wpra.renderer.display.exclude', $display->settings->excludeSrcs, $display );
		if ( ! empty( $excludeIds ) ) {
			$excIdList = $this->db->prepareList( $excludeIds, '', $args );
			$whereList[] = "(`m`.`meta_value` NOT IN ({$excIdList}))";
		}

		$argsObj = new ArrayObject( $args );
		$whereList = apply_filters( 'wpra.renderer.display.where', $whereList, $argsObj, $display );
		$args = $argsObj->getArrayCopy();

		$whereStr = implode( ' AND ', $whereList );

		return array( $whereStr, $args );
	}
}
