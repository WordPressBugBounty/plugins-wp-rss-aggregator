<?php

namespace RebelCode\Aggregator\Core;

use Throwable;
use SimpleXMLElement;
use RebelCode\Aggregator\Core\Utils\Time;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Store\WpPostsStore;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use RebelCode\Aggregator\Core\Store\RejectListStore;
use RebelCode\Aggregator\Core\Store\ProgressStore;
use RebelCode\Aggregator\Core\Source\ReconcileStrategy;
use RebelCode\Aggregator\Core\Source\ImportOrder;
use RebelCode\Aggregator\Core\RssReader\RssItem;
use RebelCode\Aggregator\Core\Importer\WpPostBuilder;
use RebelCode\Aggregator\Core\Importer\IrPostBuilder;
use DateTime;

class Importer {

	public RssReader $rssReader;
	public SourcesStore $sources;
	public WpPostsStore $wpPosts;
	public RejectListStore $rejectList;
	public IrPostBuilder $postBuilder;
	public ProgressStore $progress;

	public function __construct(
		RssReader $rssReader,
		SourcesStore $sources,
		WpPostsStore $wpPosts,
		RejectListStore $rejectList,
		IrPostBuilder $postBuilder,
		ProgressStore $progress
	) {
		$this->rssReader = $rssReader;
		$this->sources = $sources;
		$this->wpPosts = $wpPosts;
		$this->rejectList = $rejectList;
		$this->postBuilder = $postBuilder;
		$this->progress = $progress;
	}

	/**
	 * Finds RSS feeds for a given URI.
	 *
	 * @param string $uri The URI of the RSS feed.
	 * @return Result<RssFeedInfo[]>
	 */
	public function findRssFeeds( string $uri ): Result {
		return $this->rssReader->findFeeds( $uri );
	}

	/**
	 * Reads items from an RSS feed.
	 *
	 * @param int         $srcId The ID of the source.
	 * @param int|null    $num The maximum number of items to fetch.
	 * @param int         $page The page number to fetch.
	 * @param string|null $pid Optional ID of the progress to update.
	 * @return Result<array{0:RssItem[],1:int}> An array of RSS items and the total number of items in the feed.
	 */
	public function read( string $url, ?int $num = null, int $page = 1, ?string $pid = null ): Result {
		$url = trim( $url );

		if ( strlen( $url ) === 0 ) {
			return Result::Err( 'RSS feed URL is empty' );
		}

		$this->progress->setMessage( $pid, __( 'Reading RSS feed', 'wprss' ) );

		Logger::info( "Reading RSS feed at {$url}" );

		$result = $this->rssReader->read( $url, true );

		if ( $result->isOk() ) {
			$feed = $result->get();
			$total = $feed->getNumItems();

			if ( $num === null ) {
				$start = 0;
			} else {
				$start = ( $page - 1 ) * $num;
			}

			$items = Arrays::fromIterable( $feed->getItems( $start, $num ) );
			$num = count( $items );

			$this->progress->setMessage(
				$pid,
				sprintf(
					_n( 'Found %d item in the RSS feed', 'Found %d items in the RSS feed', $num, 'wprss' ),
					$num
				)
			);

			Logger::info( "Found $num items in the feed" );

			return Result::Ok( array( $items, $total ) );
		} else {
			return Result::Err( $result->error() );
		}
	}

	/**
	 * Checks if an RSS feed is valid.
	 *
	 * @param string $url The URL of the RSS feed to validate.
	 */
	public function validate( string $url ): Result {
		$url = urldecode( trim( $url ) );
		$soapUrl = 'http://validator.w3.org/feed/check.cgi?output=soap12&url=' . urlencode( $url );

		$wpVersion = get_bloginfo( 'version' );
		$wpUrl = get_bloginfo( 'url' );

		$response = wp_remote_get(
			$soapUrl,
			array(
				'timeout' => 10,
				'httpversion' => '1.1',
				'user-agent' => "WordPress/{$wpVersion}; {$wpUrl}",
			)
		);

		if ( is_wp_error( $response ) ) {
			return Result::Err( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );

		try {
			$xml = new SimpleXMLElement( $body );
			$xml->registerXPathNamespace( 'env', 'http://www.w3.org/2003/05/soap-envelope' );
			$xml->registerXPathNamespace( 'm', 'http://www.w3.org/2005/10/feed-validator' );
			$validity = $xml->xpath( '//env:Envelope/env:Body/m:feedvalidationresponse/m:validity' );

			if ( $validity === null || count( $validity ) === 0 ) {
				$isValid = false;
			} else {
				$validText = (string) $validity[0];
				$isValid = strtolower( $validText ) === 'true';
			}

			return Result::Ok( $isValid );
		} catch ( Throwable $t ) {
			return Result::Err( $t );
		}
	}

	/**
	 * Fetches new items for a source.
	 *
	 * @param Source      $src The source.
	 * @param int|null    $num The maximum number of items to fetch.
	 * @param int         $page The page number to fetch.
	 * @param bool        $all If true, all items will be fetched, even existing ones.
	 * @param string|null $pid Optional ID of the progress to update.
	 * @return Result<iterable<IrPost>> The list of fetched items.
	 */
	public function fetch( Source $src, ?int $num = null, int $page = 1, bool $all = false, ?string $pid = null ): Result {
		$res = $this->read( $src->url, $num, $page, $pid );
		if ( $res->isErr() ) {
			return Result::Err( $res->error() );
		}

		[$items] = $res->get();

		$posts = $this->convert( $src, $items, $all, $pid );

		return Result::Ok( $posts );
	}

	/**
	 * Generates a preview of the items what would be imported for a source.
	 *
	 * @param Source      $src The source.
	 * @param int|null    $num Optional number of items to preview.
	 * @param int         $page The page number to preview.
	 * @param string|null $pid Optional ID of the progress to update.
	 * @return Result<array{posts:IrPost[],total:int}> The list of items.
	 */
	public function preview( Source $src, ?int $num = null, int $page = 1, ?string $pid = null ): Result {
		$this->progress->touch( $pid, 1 );

		$res = $this->read( $src->url, $num, $page, $pid );
		if ( $res->isErr() ) {
			return Result::Err( $res->error() );
		}

		[$items, $total] = $res->get();

		$posts = $this->convert( $src, $items, true, $pid, $num, $page );
		$posts = apply_filters( 'wpra.importer.preview', $posts, $src );
		remove_filter( 'the_content', 'wpautop' );
		return Result::Ok(
			array(
				'posts' => Arrays::fromIterable( $posts ),
				'total' => $total,
			)
		);
	}

	/**
	 * Imports items using {@link fetch()}, {@link convert()}, and {@link store()}.
	 *
	 * @param Source      $src The source.
	 * @param int|null    $num The maximum number of items to import.
	 * @param int         $page The page number to import.
	 * @param string|null $pid Optional ID of the progress to update.
	 * @return Result<IrPost[]> A result that contains the list of stored IR posts.
	 */
	public function import( Source $src, ?int $num = null, int $page = 1, ?string $pid = null ): Result {
		if ( trim( $src->url ) === '' ) {
			return Result::Err( __( 'The source does not have a URL.', 'wprss' ) );
		}

		Logger::setContext(
			array(
				'source' => $src->id,
				'import' => uniqid(),
			)
		);

		$this->progress->touch( $pid, 1 );

		$res = $this->fetch( $src, $num, $page, false, $pid );

		if ( $src->id ) {
			$newError = $res->isErr() ? $res->error()->getMessage() : null;
			$this->sources->updateLastError( array( $src->id ), $newError );
		}

		if ( $res->isErr() ) {
			return $res;
		}

		$items = $res->get();

		$this->progress->setMessage( $pid, __( 'Saving items', 'wprss' ) );

		$res = $this->store( $src, $items, $pid );

		if ( $res->isErr() ) {
			return Result::Err( $res->error() );
		}

		$posts = $res->get();

		if ( count( $posts ) === 0 ) {
			Logger::info( 'Source is already up to date' );
		}

		$res = $this->truncate( $src->id );
		if ( $res->isErr() ) {
			Logger::warning( $res->error() );
		}

		Logger::clearContext();

		return Result::Ok( $posts );
	}

	/**
	 * Same as {@link import()} but fetches the source by ID.
	 *
	 * @param int         $srcId The ID of the source.
	 * @param int|null    $num The maximum number of items to import.
	 * @param int         $page The page number to import.
	 * @param string|null $pid Optional ID of the progress to update.
	 * @return Result<IrPost[]> A result that contains the list of stored IR posts.
	 */
	public function importById( int $srcId, ?int $num = null, int $page = 1, ?string $pid = null ): Result {
		$res = $this->sources->getById( $srcId );
		if ( $res->isErr() ) {
			return $res;
		}

		// Force the update when it's triggered by user.
		return $this->import( $res->get(), $num, $page, $pid );
	}

	/**
	 * Imports items for all sources that are currently pending an update.
	 *
	 * @return Result<int> The number of updated sources.
	 */
	public function importPending(): Result {
		$res = $this->sources->getPendingUpdate();

		if ( ! $res->isOk() ) {
			return Result::Err( $res->error() );
		}

		$srcs = $res->get();
		$num = 0;

		foreach ( $srcs as $src ) {
			set_time_limit( 600 );

			$res = $this->import( $src );

			if ( $res->isErr() ) {
				return $res;
			}

			$num++;
		}

		return Result::Ok( $num );
	}

	/**
	 * Converts RSS items into IR posts.
	 *
	 * @param Source            $source The source that fetched the items.
	 * @param iterable<RssItem> $items The fetched RSS items.
	 * @param bool              $all If true, existing items will not be reconciled.
	 * @param string|null       $pid Optional ID of the progress to update.
	 * @param int|null          $num The maximum number of items to fetch.
	 * @param int               $page The page number to fetch.
	 * @return iterable<IrPost> A list of IR posts.
	 */
	public function convert( Source $source, iterable $items, bool $all = false, ?string $pid = null, ?int $num = null, ?int $page = 1 ): iterable {
		$items = Arrays::fromIterable( $items );
		$items_count_before_dup = count( $items );
		$items = $this->filterDuplicateItems( $source, $items, $all );
		$items = $this->maybeLoadMoreItems( $source, $items, $items_count_before_dup, $all, $pid, $num, $page );
		$items = $this->sortItems( $source, $items );
		$items = $this->rejectListFilter( $source, $items );
		$items = $this->limitItems( $source, $items );

		$this->progress->setTotal( $pid, count( $items ) );

		if ( $all ) {
			$existingMap = array();
		} else {
			$existingMap = $this->getExistingItemsMap( $items );
		}

		$count = count( $items );

		foreach ( $items as $i => $item ) {
			$this->progress->advance(
				$pid,
				1,
				sprintf( _x( '%1$d/%2$d', 'Progress bar', 'wprss' ), $i + 1, $count )
			);

			$id = $item->getId();

			if ( $id === null ) {
				Logger::warning( __( 'Ignoring RSS item with no GUID or permalink.', 'wprss' ) );
				continue;
			}

			$existingPost = $existingMap[ $id ] ?? null;

			$strat = $source->settings->reconcileStrategy;

			// On preview or import button we will change the ReconcileStrategy to OVERWRITE to force the import items regardless of actual settings.
			if (null === $existingPost) {
				$strat = ReconcileStrategy::OVERWRITE;
			}

			switch ( $strat ) {
				default:
					Logger::warning( "Unknown reconciliation strategy: \"{$strat}\". Ignoring item." );
					break;

				case ReconcileStrategy::PRESERVE:
					Logger::debug( "Item already exists: {$item->getId()}" );
					break;

				case ReconcileStrategy::OVERWRITE:
					Logger::debug( "Converting item: {$item->getId()}" );
					$newPost = $this->postBuilder->build( $item, $source );

					if ( $existingPost !== null ) {
						$newPost->sources = array_merge( $existingPost->sources, $newPost->sources );
						$newPost->sources = array_unique( $newPost->sources );
						$newPost->postId = $existingPost->postId;
						$newPost->id = $existingPost->id;
					}

					/** @var IrPost|null $finalPost */
					$finalPost = apply_filters( 'wpra.importer.post.final', $newPost, $item, $source );

					if ( $finalPost !== null ) {
						yield $newPost;
					}
			}
		}
	}

	/**
	 * Saves fetched IR posts to the store.
	 *
	 * @param Source           $source The feed source.
	 * @param iterable<IrPost> $irPosts The list of IR posts to store.
	 * @return Result<list<IrPost>> The list of stored IR posts.
	 */
	public function store( Source $source, iterable $irPosts, ?string $pid = null ): Result {
		$newPosts = array();

		// Update last updated time on source store.
		if ( $source->id ) {
			$this->sources->updateLastUpdateTime( array( $source->id ) );
		}

		foreach ( $irPosts as $irPost ) {
			$irPost = apply_filters( 'wpra.importer.post.store', $irPost, $source, $pid );
			if ( $irPost === null ) {
				continue;
			}

			$result = $this->createWpPost( $irPost );

			if ( $result->isOk() ) {
				$newPost = $result->get();
				$newPosts[] = $newPost;
				Logger::info( sprintf( __( 'Imported post (#%1$d) "%2$s"', 'wprss' ), $newPost->postId ?? 0, $newPost->title ) );
			} else {
				Logger::error( $result->error() );
			}
		}

		return Result::Ok( $newPosts );
	}

	public function truncate( int $srcId ): Result {
		$result = $this->sources->getById( $srcId );
		if ( $result->isErr() ) {
			return $result;
		}

		$src = $result->get();
		$numDeleted = 0;

		$limit = $src->settings->importLimit;
		$ageLimit = $src->settings->ageLimit;
		$ageUnit = $src->settings->ageLimitUnit;

		if ( $limit > 0 ) {
			$numImported = $this->wpPosts->getCount( array( $srcId ) )->getOr( 0 );
			$numToDelete = max( 0, $numImported - $limit );

			if ( absint( $numToDelete ) > 0 ) {
				Logger::info( sprintf( 'Truncating %d old items', $numToDelete ) );

				$result = $this->wpPosts->deleteFromSources( array( $srcId ), false, $numToDelete, 1, 'ASC' );
				if ( $result->isErr() ) {
					return $result;
				}

				$numDeleted += $result->get();
				Logger::debug( sprintf( 'Deleted %d items', $numDeleted ) );
			}
		}

		$ageTs = strtotime( "-$ageLimit $ageUnit" );
		if ( $ageLimit > 0 && $ageTs !== false ) {
			$minDate = new DateTime();
			$minDate->setTimestamp( $ageTs );

			Logger::info( sprintf( 'Truncating items older than %s', Time::toHumanFormat( $minDate ) ) );

			$result = $this->wpPosts->deleteOlderThan( $minDate, $srcId );
			if ( $result->isErr() ) {
				return $result;
			}

			$numDeleted += $result->get();
		}

		return Result::Ok( $numDeleted );
	}

	/**
	 * Creates a WordPress post from an IR Post.
	 *
	 * @return Result<IrPost>
	 */
	public function createWpPost( IrPost $post ): Result {
		do_action( 'wpra.importer.post.beforeCreate', $post );

		$result = WpPostBuilder::buildWpPost( $post );

		if ( $result->isOk() ) {
			$post = $result->get();
			do_action( 'wpra.importer.post.created', $post );
		}

		do_action( 'wpra.importer.post.afterCreate', $post );

		return $result;
	}

	/**
	 * Sorts the items according to the feed's settings.
	 *
	 * @param  Source        $source The feed source.
	 * @param  list<RssItem> $items  The items to sort.
	 * @return list<RssItem> The sorted items.
	 */
	protected function sortItems( Source $source, array $items ): array {
		Logger::debug( "Sorting items in {$source->settings->importOrder} order" );

		$mult = ( $source->settings->importOrder === ImportOrder::ASC ) ? 1 : -1;

		usort(
			$items,
			function ( RssItem $a, RssItem $b ) use ( $mult ) {
				$dateA = $a->getDateCreated() ?? $a->getDateModified();
				$dateB = $b->getDateCreated() ?? $b->getDateModified();

				return ( $dateA <=> $dateB ) * $mult;
			}
		);

		return $items;
	}

	/**
	 * Filters reject list of RSS items according to the source's settings.
	 *
	 * @param Source        $source The feed source.
	 * @param list<RssItem> $items The items to filter.
	 * @return list<RssItem> The filtered items.
	 */
	protected function rejectListFilter( Source $source, array $items ): array {
		Logger::debug( 'Filtering reject list items' );

		$fItems = array();
		foreach ( $items as $item ) {
			$url = $item->getPermalink();
			$id = $item->getId() ?? $url;

			if ( empty( $id ) ) {
				Logger::warning( 'Item has no ID or permalink', array( 'source' => $source ) );
				continue;
			}

			$result = $this->rejectList->contains( array_filter( array( $id, $url ) ) );

			if ( $result->isOk() ) {
				$rejected = $result->get();
			} else {
				$rejected = false;
				Logger::warning( $result->error(), array( 'source' => $source ) );
			}

			if ( $rejected ) {
				Logger::debug( "Item {$id} is rejected" );
				continue;
			}

			$item = apply_filters( 'wpra.importer.item.filter', $item, $source );

			if ( null === $item ) {
				continue;
			}

			$fItems[] = $item;
		}

		return $fItems;
	}

	/**
	 * Filters duplicate of RSS items based to the source's settings.
	 *
	 * @param Source        $source The feed source.
	 * @param list<RssItem> $items The items to filter.
	 * @param bool          $preview Whether the items are being filtered for a preview.
	 * @return list<RssItem> The filtered items.
	 */
	protected function filterDuplicateItems( Source $source, array $items, bool $preview = false ): array {
		if ( ! $source->settings->uniqueTitles ) {
			return $items;
		}

		Logger::debug( 'Filtering items' );

		$fItems = array();
		$titles = array();
		foreach ( $items as $item ) {
			$url = $item->getPermalink();
			$id = $item->getId() ?? $url;
			$title = $item->getTitle();

			if ( null === $title ) {
				continue;
			}

			if ( in_array( $title, $titles ) ) {
				continue;
			}

			$titles[] = $title;

			$titleExists = $this->wpPosts->titleExists( $title )->getOr( false );
			$titleExists = apply_filters( 'wpra.importer.titleExists', $titleExists, $title, $source );

			if ( ! $preview && $titleExists ) {
				Logger::debug( "Item {$id} does not have a unique title" );
				continue;
			}

			$fItems[] = $item;
		}

		return $fItems;
	}

	/**
	 * Load more items if condition met.
	 *
	 * @param Source        $source The feed source.
	 * @param list<RssItem> $items The items to filter.
	 * @param int           $items_count_before_dup Item counts before duplication filter.
	 * @param bool          $all If true, existing items will not be reconciled.
	 * @param string|null   $pid Optional ID of the progress to update.
	 * @param int|null      $num The maximum number of items to fetch.
	 * @param int           $page The page number to fetch.
	 * @return list<RssItem> The filtered items.
	 */
	protected function maybeLoadMoreItems( Source $source, array $items, int $items_count_before_dup, bool $all = false, ?string $pid = null, ?int $num = null, ?int $page = 1 ): array {
		if ( 0 === $source->settings->importLimit ) {
			return $items;
		}

		if ( ! $source->settings->uniqueTitles ) {
			return $items;
		}

		$items_count_after_dup = count( $items );
		$max_iterations = 5;
		$iteration = 0;

		while ( (int) $items_count_after_dup < (int) $items_count_before_dup && $iteration < $max_iterations ) {
			$iteration++;
			$offset = $items_count_before_dup - $items_count_after_dup;
			$num_to_fetch = $num ? $num + $offset + $iteration : null;

			$res = $this->read( $source->url, $num_to_fetch, $page, $pid );

			if ( $res->isErr() ) {
				break;
			}

			[$items, $total] = $res->get();

			$items = $this->filterDuplicateItems( $source, Arrays::fromIterable( $items ), $all );
			$items_count_after_dup = count( $items );

			if ( (int) $items_count_after_dup >= (int) $items_count_before_dup || $total <= $items_count_before_dup ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Trims the list of items to the maximum number of items allowed by the source.
	 *
	 * @param  Source        $source The feed source.
	 * @param  list<RssItem> $items  The items to trim.
	 * @return list<RssItem> The trimmed items.
	 */
	protected function limitItems( Source $source, array $items ): array {
		$limit = $source->settings->importLimit;
		$numItems = count( $items );

		if ( $limit > 0 && $numItems > $limit ) {
			Logger::debug( "Applying limit of {$limit} items" );
			$items = array_slice( $items, 0, $limit );
		}

		$ageLimit = $source->settings->ageLimit;
		$ageUnit = $source->settings->ageLimitUnit;
		$minDate = strtotime( "- $ageLimit $ageUnit" );
		if ( $ageLimit <= 0 || $minDate === false ) {
			return $items;
		}

		$results = array();
		foreach ( $items as $item ) {
			/** @var RssItem $item */
			$date = $item->getDateCreated() ?? $item->getDateModified();
			if ( $date === null ) {
				continue;
			}
			if ( $date->getTimestamp() < $minDate ) {
				continue;
			}
			$results[] = $item;
		}

		return $results;
	}

	/**
	 * Gets a map of the matching existing items for a list of incoming items.
	 *
	 * @param list<RssItem> $items The incoming items.
	 * @return array<string,IrPost> The existing items, keyed by their IDs.
	 */
	protected function getExistingItemsMap( array $items ): array {
		/** @var list<string> $guids */
		$guids = Arrays::map( $items, fn ( RssItem $item ) => $item->getId() ?? Arrays::skip() );

		$result = $this->wpPosts->getManyByGuids( $guids );
		$wpPosts = $result->getOr( array() );

		if ( $result->isErr() ) {
			Logger::error( $result->error() );
		}

		$map = array();
		foreach ( $wpPosts as $wpPost ) {
			$map[ $wpPost->guid ] = $wpPost;
		}

		return apply_filters( 'wpra.importer.existingItemsMap', $map, $guids, $items );
	}
}
