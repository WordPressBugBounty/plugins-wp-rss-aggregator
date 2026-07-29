<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Cli\Commands;

use RebelCode\Aggregator\Core\Cli\BaseCommand;
use RebelCode\Aggregator\Core\Cli\CliIo;
use RebelCode\Aggregator\Core\Cli\CliTable;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Source\Schedule;
use RebelCode\Aggregator\Core\Source\ScheduleFactory;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use WP_CLI;
use WP_Error;

use function WP_CLI\Utils\get_flag_value;

/**
 * Seeds feed sources for local testing and QA.
 */
class SourceSeedCommand extends BaseCommand {

	private const LAST_RUN_OPTION = 'wpra_seed_sources_last_run';

	protected SourcesStore $sources;

	public function __construct( CliIo $io, SourcesStore $sources ) {
		parent::__construct( $io );
		$this->sources = $sources;
	}

	/**
	 * Seeds feed sources for local testing and QA.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<num>]
	 * : The maximum number of sources to create. Default is 200.
	 *
	 * [--profile=<profile>]
	 * : The feed profile to seed. Use one of: mixed, news, tech, ai, business, sports, entertainment, science-health, travel, gaming, wordpress.
	 *
	 * [--activate]
	 * : Activate seeded sources for automatic updates.
	 *
	 * [--schedule=<schedule>]
	 * : Schedule seeded sources, for example "12h" or "1d at 03:00".
	 *
	 * [--validate]
	 * : Fetch each feed before creating the source and skip invalid feeds.
	 *
	 * [--dry-run]
	 * : Preview the sources that would be created.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source-seed run --count=200 --profile=mixed --dry-run
	 * wp rss source-seed run --count=200 --activate --schedule="12h" --yes
	 *
	 * @since 5.3.0
	 *
	 * @param list<string>        $args
	 * @param array<string,mixed> $opts
	 */
	public function run( array $args, array $opts ): void {
		$count = $this->parsePositiveInt( $opts['count'] ?? 200, 'count' );
		$profile = sanitize_key( (string) ( $opts['profile'] ?? 'mixed' ) );
		$dryRun = (bool) get_flag_value( $opts, 'dry-run', false );
		$validate = (bool) get_flag_value( $opts, 'validate', false );
		$activate = (bool) get_flag_value( $opts, 'activate', false );
		$schedule = $this->parseSchedule( $opts['schedule'] ?? null );
		$catalog = $this->loadCatalog( $profile );

		if ( count( $catalog ) === 0 ) {
			WP_CLI::error( sprintf( 'No seed feeds found for profile "%s".', $profile ) );
		}

		if ( ! $dryRun && ! get_flag_value( $opts, 'yes', false ) ) {
			WP_CLI::confirm(
				sprintf(
					'Create up to %1$d WP RSS Aggregator sources using the "%2$s" seed profile?',
					$count,
					$profile
				)
			);
		}

		$existingUrls = $this->getExistingSourceUrls();
		$createdSources = array();
		$rows = array();
		$created = 0;
		$skipped = 0;
		$failed = 0;

		foreach ( $catalog as $feed ) {
			if ( $created >= $count ) {
				break;
			}

			$urlKey = $this->normalizeUrl( $feed['url'] );
			if ( isset( $existingUrls[ $urlKey ] ) ) {
				$skipped++;
				$rows[] = $this->resultRow( $feed, 'skipped', 'Duplicate URL' );
				continue;
			}

			if ( $validate ) {
				$validation = $this->validateFeed( $feed['url'] );
				if ( $validation !== true ) {
					$failed++;
					$rows[] = $this->resultRow( $feed, 'invalid', $validation );
					continue;
				}
			}

			if ( $dryRun ) {
				$created++;
				$existingUrls[ $urlKey ] = true;
				$rows[] = $this->resultRow( $feed, 'would create', '' );
				continue;
			}

			$source = ( new Source( null, $feed['name'] ) )
				->withUrl( $feed['url'] )
				->withActive( $activate );

			if ( $schedule !== null ) {
				$source = $source->withSchedule( $schedule );
			}

			$result = $this->sources->save( $source );
			if ( $result->isOk() ) {
				$created++;
				$src = $result->get();
				$createdSources[] = array(
					'id' => $src->id,
					'url' => $src->url,
				);
				$existingUrls[ $urlKey ] = true;
				$rows[] = $this->resultRow( $feed, 'created', "#{$src->id}" );
			} else {
				$failed++;
				$error = $result->error();
				$rows[] = $this->resultRow(
					$feed,
					'failed',
					$error instanceof \Throwable ? $error->getMessage() : (string) $error
				);
			}
		}

		if ( ! $dryRun ) {
			update_option( self::LAST_RUN_OPTION, array_values( $createdSources ), false );
		}

		CliTable::create( $rows )
			->showColumns( array( 'status', 'name', 'profile', 'url', 'note' ) )
			->columnNames(
				array(
					'status' => 'Status',
					'name' => 'Name',
					'profile' => 'Profile',
					'url' => 'URL',
					'note' => 'Note',
				)
			)
			->render();

		$summary = sprintf(
			'%1$s %2$d source(s). Skipped %3$d duplicate(s), %4$d failed/invalid.',
			$dryRun ? 'Would create' : 'Created',
			$created,
			$skipped,
			$failed
		);

		if ( $dryRun ) {
			$this->io->success( $summary );
		} else {
			$this->io->success( $summary . ' Last-run IDs saved for cleanup.' );
		}
	}

	/**
	 * Deletes sources created by the previous seed run.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 * wp rss source-seed clean --yes
	 *
	 * @since 5.3.0
	 *
	 * @param list<string>        $args
	 * @param array<string,mixed> $opts
	 */
	public function clean( array $args, array $opts ): void {
		$records = get_option( self::LAST_RUN_OPTION, array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}

		$expectedUrls = array();
		$ids = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) ) {
				$id = (int) ( $record['id'] ?? 0 );
				if ( $id > 0 ) {
					$ids[] = $id;
					$expectedUrls[ $id ] = $this->normalizeUrl( (string) ( $record['url'] ?? '' ) );
				}
			} elseif ( is_numeric( $record ) ) {
				$ids[] = (int) $record;
			}
		}

		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( count( $ids ) === 0 ) {
			$this->io->success( 'No seed sources found from the previous run.' );
			return;
		}

		$deleteIds = $this->getCleanableIds( $ids, $expectedUrls );
		if ( count( $deleteIds ) === 0 ) {
			$this->io->success( 'No matching seed sources found to delete.' );
			return;
		}

		if ( ! get_flag_value( $opts, 'yes', false ) ) {
			WP_CLI::confirm( sprintf( 'Delete %d source(s) created by the previous seed run?', count( $deleteIds ) ) );
		}

		$result = $this->sources->deleteManyByIds( $deleteIds );
		if ( $result->isOk() ) {
			delete_option( self::LAST_RUN_OPTION );
			$this->io->success( sprintf( 'Deleted %d seed source(s).', $result->get() ) );
		} else {
			$this->printCliError( $result->error() );
		}
	}

	/**
	 * Parses a positive integer option.
	 *
	 * @since 5.3.0
	 *
	 * @param mixed  $value The raw option value.
	 * @param string $name The option name.
	 * @return int
	 */
	protected function parsePositiveInt( $value, string $name ): int {
		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			WP_CLI::error( sprintf( '--%s must be a positive number.', $name ) );
		}

		return (int) $value;
	}

	/**
	 * Parses the optional schedule string for seeded sources.
	 *
	 * @since 5.3.0
	 *
	 * @param mixed $schedule The raw schedule option.
	 * @return Schedule|null
	 */
	protected function parseSchedule( $schedule ): ?Schedule {
		if ( $schedule === null || $schedule === '' ) {
			return null;
		}

		$result = ScheduleFactory::fromString( (string) $schedule );
		if ( $result->isErr() ) {
			$error = $result->error();
			WP_CLI::error( $error instanceof \Throwable ? $error->getMessage() : (string) $error );
		}

		return $result->get();
	}

	/**
	 * Gets existing source URLs for duplicate checks.
	 *
	 * @since 5.3.0
	 *
	 * @return array<string,true>
	 */
	protected function getExistingSourceUrls(): array {
		$result = $this->sources->getList( '', null );
		if ( $result->isErr() ) {
			$error = $result->error();
			WP_CLI::error( $error instanceof \Throwable ? $error->getMessage() : (string) $error );
		}

		$urls = array();
		foreach ( $result->get() as $source ) {
			$urls[ $this->normalizeUrl( $source->url ) ] = true;
		}

		return $urls;
	}

	/**
	 * Gets the source IDs that still match the previous seed run metadata.
	 *
	 * @since 5.3.0
	 *
	 * @param list<int>          $ids The candidate source IDs.
	 * @param array<int,string>  $expectedUrls Expected source URLs, keyed by source ID.
	 * @return list<int>
	 */
	protected function getCleanableIds( array $ids, array $expectedUrls ): array {
		$result = $this->sources->getManyByIds( $ids );
		if ( $result->isErr() ) {
			$error = $result->error();
			WP_CLI::error( $error instanceof \Throwable ? $error->getMessage() : (string) $error );
		}

		$deleteIds = array();
		foreach ( $result->get() as $source ) {
			$expectedUrl = $expectedUrls[ $source->id ] ?? '';
			if ( $expectedUrl === '' || $expectedUrl === $this->normalizeUrl( $source->url ) ) {
				$deleteIds[] = $source->id;
			} else {
				$this->io->warning( sprintf( 'Skipping source #%d because its URL no longer matches the seed record.', $source->id ) );
			}
		}

		return $deleteIds;
	}

	/**
	 * Loads the seed feed catalog.
	 *
	 * @since 5.3.0
	 *
	 * @param string $profile The selected profile.
	 * @return list<array{name:string,url:string,profile:string}>
	 */
	protected function loadCatalog( string $profile ): array {
		$feeds = $this->baseFeeds();
		$topics = $this->googleNewsTopics();

		foreach ( $topics as $topicProfile => $profileTopics ) {
			foreach ( $profileTopics as $topic ) {
				$feeds[] = array(
					'name' => 'Google News: ' . $topic,
					'url' => 'https://news.google.com/rss/search?q=' . rawurlencode( $topic ) . '&hl=en-US&gl=US&ceid=US:en',
					'profile' => $topicProfile,
				);
			}
		}

		$selected = array();
		$seen = array();

		foreach ( $feeds as $feed ) {
			$name = trim( (string) ( $feed['name'] ?? '' ) );
			$url = trim( (string) ( $feed['url'] ?? '' ) );
			$feedProfile = sanitize_key( (string) ( $feed['profile'] ?? 'mixed' ) );

			if ( $name === '' || $url === '' ) {
				continue;
			}

			if ( $profile !== 'mixed' && $feedProfile !== $profile ) {
				continue;
			}

			$urlKey = $this->normalizeUrl( $url );
			if ( isset( $seen[ $urlKey ] ) ) {
				continue;
			}

			$seen[ $urlKey ] = true;
			$selected[] = array(
				'name' => $name,
				'url' => $url,
				'profile' => $feedProfile,
			);
		}

		return $selected;
	}

	/**
	 * Validates a seed feed URL by making a small request.
	 *
	 * @since 5.3.0
	 *
	 * @param string $url The feed URL.
	 * @return true|string
	 */
	protected function validateFeed( string $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 10,
				'redirection' => 3,
				'limit_response_size' => 1024 * 256,
				'reject_unsafe_urls' => true,
			)
		);

		if ( $response instanceof WP_Error ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return "HTTP $code";
		}

		$body = strtolower( (string) wp_remote_retrieve_body( $response ) );
		if (
			strpos( $body, '<rss' ) === false
			&& strpos( $body, '<feed' ) === false
			&& strpos( $body, '<rdf:rdf' ) === false
		) {
			return 'Response does not look like RSS, Atom, or RDF.';
		}

		return true;
	}

	/**
	 * Normalizes feed URLs for duplicate checks.
	 *
	 * @since 5.3.0
	 *
	 * @param string $url The feed URL.
	 * @return string
	 */
	protected function normalizeUrl( string $url ): string {
		$url = trim( strtolower( $url ) );
		return rtrim( $url, '/' );
	}

	/**
	 * Creates a command result row.
	 *
	 * @since 5.3.0
	 *
	 * @param array{name:string,url:string,profile:string} $feed The seed feed.
	 * @param string                                      $status The row status.
	 * @param string                                      $note Optional row note.
	 * @return array<string,string>
	 */
	protected function resultRow( array $feed, string $status, string $note ): array {
		return array(
			'status' => $status,
			'name' => $feed['name'],
			'profile' => $feed['profile'],
			'url' => $feed['url'],
			'note' => $note,
		);
	}

	/**
	 * Gets direct feed URLs for popular sites.
	 *
	 * @since 5.3.0
	 *
	 * @return list<array{name:string,url:string,profile:string}>
	 */
	protected function baseFeeds(): array {
		return array(
			array( 'profile' => 'news', 'name' => 'BBC News', 'url' => 'https://feeds.bbci.co.uk/news/rss.xml' ),
			array( 'profile' => 'news', 'name' => 'BBC World News', 'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml' ),
			array( 'profile' => 'news', 'name' => 'The Guardian World', 'url' => 'https://www.theguardian.com/world/rss' ),
			array( 'profile' => 'news', 'name' => 'NPR News', 'url' => 'https://feeds.npr.org/1001/rss.xml' ),
			array( 'profile' => 'news', 'name' => 'New York Times Home Page', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml' ),
			array( 'profile' => 'news', 'name' => 'New York Times World', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/World.xml' ),
			array( 'profile' => 'news', 'name' => 'Washington Post World', 'url' => 'https://feeds.washingtonpost.com/rss/world' ),
			array( 'profile' => 'news', 'name' => 'Washington Post National', 'url' => 'https://feeds.washingtonpost.com/rss/national' ),
			array( 'profile' => 'news', 'name' => 'CNN Top Stories', 'url' => 'http://rss.cnn.com/rss/edition.rss' ),
			array( 'profile' => 'news', 'name' => 'CNN World', 'url' => 'http://rss.cnn.com/rss/edition_world.rss' ),
			array( 'profile' => 'news', 'name' => 'Al Jazeera', 'url' => 'https://www.aljazeera.com/xml/rss/all.xml' ),
			array( 'profile' => 'news', 'name' => 'DW News', 'url' => 'https://rss.dw.com/xml/rss-en-all' ),
			array( 'profile' => 'news', 'name' => 'France 24', 'url' => 'https://www.france24.com/en/rss' ),
			array( 'profile' => 'news', 'name' => 'Euronews', 'url' => 'https://www.euronews.com/rss' ),
			array( 'profile' => 'news', 'name' => 'RTE News', 'url' => 'https://www.rte.ie/rss/news.xml' ),
			array( 'profile' => 'news', 'name' => 'CBS News', 'url' => 'https://www.cbsnews.com/latest/rss/main' ),
			array( 'profile' => 'news', 'name' => 'NBC News Top Stories', 'url' => 'https://feeds.nbcnews.com/feeds/topstories' ),
			array( 'profile' => 'news', 'name' => 'ABC News Top Stories', 'url' => 'https://abcnews.go.com/abcnews/topstories' ),
			array( 'profile' => 'news', 'name' => 'Politico Picks', 'url' => 'https://www.politico.com/rss/politicopicks.xml' ),
			array( 'profile' => 'news', 'name' => 'The Hill', 'url' => 'https://thehill.com/feed' ),
			array( 'profile' => 'news', 'name' => 'Time', 'url' => 'https://time.com/feed' ),
			array( 'profile' => 'news', 'name' => 'ProPublica', 'url' => 'https://www.propublica.org/feeds/propublica/main' ),
			array( 'profile' => 'news', 'name' => 'The Daily Beast', 'url' => 'https://www.thedailybeast.com/rss' ),
			array( 'profile' => 'news', 'name' => 'The Atlantic', 'url' => 'https://www.theatlantic.com/feed/all' ),
			array( 'profile' => 'news', 'name' => 'Vox', 'url' => 'https://www.vox.com/rss/index.xml' ),
			array( 'profile' => 'news', 'name' => 'The Intercept', 'url' => 'https://theintercept.com/feed/?lang=en' ),
			array( 'profile' => 'news', 'name' => 'Reason', 'url' => 'https://reason.com/feed' ),
			array( 'profile' => 'news', 'name' => 'Jacobin', 'url' => 'https://jacobin.com/feed' ),
			array( 'profile' => 'business', 'name' => 'Bloomberg Markets', 'url' => 'https://feeds.bloomberg.com/markets/news.rss' ),
			array( 'profile' => 'business', 'name' => 'Bloomberg Technology', 'url' => 'https://feeds.bloomberg.com/technology/news.rss' ),
			array( 'profile' => 'business', 'name' => 'Financial Times', 'url' => 'https://www.ft.com/?format=rss' ),
			array( 'profile' => 'business', 'name' => 'Forbes Business', 'url' => 'https://www.forbes.com/business/feed/' ),
			array( 'profile' => 'business', 'name' => 'Fortune', 'url' => 'https://fortune.com/feed' ),
			array( 'profile' => 'business', 'name' => 'MarketWatch Top Stories', 'url' => 'http://feeds.marketwatch.com/marketwatch/topstories' ),
			array( 'profile' => 'business', 'name' => 'Entrepreneur', 'url' => 'https://www.entrepreneur.com/latest.rss' ),
			array( 'profile' => 'business', 'name' => 'Inc.', 'url' => 'https://www.inc.com/rss' ),
			array( 'profile' => 'business', 'name' => 'Fast Company', 'url' => 'https://www.fastcompany.com/rss' ),
			array( 'profile' => 'business', 'name' => 'Harvard Business Review', 'url' => 'https://hbr.org/feed' ),
			array( 'profile' => 'business', 'name' => 'Quartz', 'url' => 'https://qz.com/feed' ),
			array( 'profile' => 'tech', 'name' => 'TechCrunch', 'url' => 'https://techcrunch.com/feed/' ),
			array( 'profile' => 'tech', 'name' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml' ),
			array( 'profile' => 'tech', 'name' => 'Wired', 'url' => 'https://www.wired.com/feed/rss' ),
			array( 'profile' => 'tech', 'name' => 'Ars Technica', 'url' => 'https://feeds.arstechnica.com/arstechnica/index' ),
			array( 'profile' => 'tech', 'name' => 'Engadget', 'url' => 'https://www.engadget.com/rss.xml' ),
			array( 'profile' => 'tech', 'name' => 'Gizmodo', 'url' => 'https://gizmodo.com/rss' ),
			array( 'profile' => 'tech', 'name' => 'Mashable', 'url' => 'https://mashable.com/feed' ),
			array( 'profile' => 'tech', 'name' => 'CNET News', 'url' => 'https://www.cnet.com/rss/news/' ),
			array( 'profile' => 'tech', 'name' => 'ZDNET', 'url' => 'https://www.zdnet.com/news/rss.xml' ),
			array( 'profile' => 'tech', 'name' => 'VentureBeat', 'url' => 'http://feeds.feedburner.com/venturebeat/SZYF' ),
			array( 'profile' => 'tech', 'name' => 'The Next Web', 'url' => 'https://thenextweb.com/feed' ),
			array( 'profile' => 'tech', 'name' => '9to5Mac', 'url' => 'https://9to5mac.com/feed' ),
			array( 'profile' => 'tech', 'name' => 'Android Authority', 'url' => 'https://www.androidauthority.com/feed' ),
			array( 'profile' => 'tech', 'name' => 'Hacker News', 'url' => 'https://news.ycombinator.com/rss' ),
			array( 'profile' => 'tech', 'name' => 'Slashdot', 'url' => 'http://rss.slashdot.org/Slashdot/slashdot' ),
			array( 'profile' => 'tech', 'name' => 'MIT Technology Review', 'url' => 'https://www.technologyreview.com/feed/' ),
			array( 'profile' => 'ai', 'name' => 'OpenAI Blog', 'url' => 'https://openai.com/blog/rss/' ),
			array( 'profile' => 'ai', 'name' => 'Google Research Blog', 'url' => 'https://research.google/blog/rss/' ),
			array( 'profile' => 'ai', 'name' => 'MIT AI News', 'url' => 'https://news.mit.edu/rss/topic/artificial-intelligence2' ),
			array( 'profile' => 'ai', 'name' => 'MarkTechPost', 'url' => 'https://www.marktechpost.com/feed/' ),
			array( 'profile' => 'ai', 'name' => 'VentureBeat AI', 'url' => 'https://venturebeat.com/category/ai/feed/' ),
			array( 'profile' => 'ai', 'name' => 'DeepLearning.AI The Batch', 'url' => 'https://www.deeplearning.ai/the-batch/feed/' ),
			array( 'profile' => 'sports', 'name' => 'ESPN News', 'url' => 'https://www.espn.com/espn/rss/news' ),
			array( 'profile' => 'sports', 'name' => 'BBC Sport', 'url' => 'https://feeds.bbci.co.uk/sport/rss.xml' ),
			array( 'profile' => 'sports', 'name' => 'Sky Sports', 'url' => 'https://www.skysports.com/rss/12040' ),
			array( 'profile' => 'sports', 'name' => 'Yahoo Sports', 'url' => 'https://sports.yahoo.com/rss/' ),
			array( 'profile' => 'sports', 'name' => 'TalkSport', 'url' => 'https://talksport.com/rss/sports-news/all/feed' ),
			array( 'profile' => 'sports', 'name' => 'Sports Illustrated', 'url' => 'https://www.si.com/rss/si_topstories.rss' ),
			array( 'profile' => 'sports', 'name' => 'New York Times Sports', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/Sports.xml' ),
			array( 'profile' => 'sports', 'name' => 'Motorsport.com', 'url' => 'https://www.motorsport.com/rss/google/' ),
			array( 'profile' => 'sports', 'name' => 'GOLF.com', 'url' => 'https://golf.com/feed/' ),
			array( 'profile' => 'entertainment', 'name' => 'Rolling Stone', 'url' => 'https://www.rollingstone.com/feed/' ),
			array( 'profile' => 'entertainment', 'name' => 'Variety', 'url' => 'https://variety.com/feed/' ),
			array( 'profile' => 'entertainment', 'name' => 'Hollywood Reporter', 'url' => 'https://www.hollywoodreporter.com/feed/' ),
			array( 'profile' => 'entertainment', 'name' => 'Vanity Fair', 'url' => 'https://www.vanityfair.com/feed/rss' ),
			array( 'profile' => 'entertainment', 'name' => 'The New Yorker', 'url' => 'https://www.newyorker.com/feed/everything' ),
			array( 'profile' => 'entertainment', 'name' => 'E! News', 'url' => 'https://www.eonline.com/syndication/feeds/rssfeeds/topstories.xml' ),
			array( 'profile' => 'entertainment', 'name' => 'TMZ', 'url' => 'https://www.tmz.com/rss.xml' ),
			array( 'profile' => 'entertainment', 'name' => 'Entertainment Weekly', 'url' => 'https://ew.com/feed/' ),
			array( 'profile' => 'science-health', 'name' => 'ScienceDaily Top News', 'url' => 'https://www.sciencedaily.com/rss/top_news.xml' ),
			array( 'profile' => 'science-health', 'name' => 'New Scientist', 'url' => 'https://www.newscientist.com/feed/home/' ),
			array( 'profile' => 'science-health', 'name' => 'Nature Current Issue', 'url' => 'http://www.nature.com/nature/current_issue/rss' ),
			array( 'profile' => 'science-health', 'name' => 'Scientific American', 'url' => 'http://rss.sciam.com/ScientificAmerican-Global' ),
			array( 'profile' => 'science-health', 'name' => 'NASA Image of the Day', 'url' => 'https://www.nasa.gov/rss/dyn/lg_image_of_the_day.rss' ),
			array( 'profile' => 'science-health', 'name' => 'NASA Breaking News', 'url' => 'https://www.nasa.gov/news-release/feed/' ),
			array( 'profile' => 'science-health', 'name' => 'Space.com', 'url' => 'https://www.space.com/feeds/all' ),
			array( 'profile' => 'science-health', 'name' => 'Live Science', 'url' => 'https://www.livescience.com/feeds/all' ),
			array( 'profile' => 'science-health', 'name' => 'WebMD Health News', 'url' => 'https://rssfeeds.webmd.com/rss/rss.aspx?RSSSource=RSS_PUBLIC' ),
			array( 'profile' => 'science-health', 'name' => 'NIH News in Health', 'url' => 'https://newsinhealth.nih.gov/syndication/rss' ),
			array( 'profile' => 'travel', 'name' => 'Lonely Planet News', 'url' => 'https://www.lonelyplanet.com/news/feed/' ),
			array( 'profile' => 'travel', 'name' => 'Travel + Leisure', 'url' => 'https://www.travelandleisure.com/rss' ),
			array( 'profile' => 'travel', 'name' => 'Conde Nast Traveler', 'url' => 'https://www.cntraveler.com/feed/rss' ),
			array( 'profile' => 'travel', 'name' => 'The Points Guy', 'url' => 'https://thepointsguy.com/feed/' ),
			array( 'profile' => 'gaming', 'name' => 'IGN News', 'url' => 'http://feeds.ign.com/ign/news' ),
			array( 'profile' => 'gaming', 'name' => 'Kotaku', 'url' => 'https://kotaku.com/rss' ),
			array( 'profile' => 'gaming', 'name' => 'Polygon', 'url' => 'https://www.polygon.com/rss/index.xml' ),
			array( 'profile' => 'gaming', 'name' => 'PC Gamer', 'url' => 'https://www.pcgamer.com/rss/' ),
			array( 'profile' => 'gaming', 'name' => 'Eurogamer', 'url' => 'https://www.eurogamer.net/?format=rss' ),
			array( 'profile' => 'gaming', 'name' => 'Rock Paper Shotgun', 'url' => 'https://www.rockpapershotgun.com/feed/' ),
			array( 'profile' => 'gaming', 'name' => 'GameSpot News', 'url' => 'https://www.gamespot.com/feeds/news/' ),
			array( 'profile' => 'wordpress', 'name' => 'WordPress News', 'url' => 'https://wordpress.org/news/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'WordPress Developer Blog', 'url' => 'https://developer.wordpress.org/news/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'Make WordPress Core', 'url' => 'https://make.wordpress.org/core/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'Make WordPress Plugins', 'url' => 'https://make.wordpress.org/plugins/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'Make WordPress Themes', 'url' => 'https://make.wordpress.org/themes/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'Make WordPress Design', 'url' => 'https://make.wordpress.org/design/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'Make WordPress Accessibility', 'url' => 'https://make.wordpress.org/accessibility/feed/' ),
			array( 'profile' => 'wordpress', 'name' => 'WP Tavern', 'url' => 'https://wptavern.com/feed' ),
			array( 'profile' => 'wordpress', 'name' => 'WooCommerce Developer Blog', 'url' => 'https://developer.woocommerce.com/feed/' ),
		);
	}

	/**
	 * Gets Google News topic feeds for broader source coverage.
	 *
	 * @since 5.3.0
	 *
	 * @return array<string,list<string>>
	 */
	protected function googleNewsTopics(): array {
		return array(
			'news' => array(
				'breaking news', 'world politics', 'climate change', 'public health', 'education policy',
				'immigration', 'elections', 'courts', 'diplomacy', 'human rights', 'energy policy',
				'infrastructure', 'housing market', 'cybersecurity policy', 'local journalism',
				'media industry', 'supply chains', 'labor unions', 'agriculture', 'transportation',
			),
			'business' => array(
				'stock market', 'interest rates', 'inflation', 'small business', 'startups', 'venture capital',
				'private equity', 'retail earnings', 'banking', 'fintech', 'cryptocurrency', 'real estate',
				'commercial real estate', 'oil prices', 'renewable energy business', 'automotive industry',
				'airlines', 'supply chain management', 'consumer spending', 'mergers acquisitions',
			),
			'tech' => array(
				'consumer technology', 'software development', 'cloud computing', 'open source software',
				'cybersecurity', 'data privacy', 'developer tools', 'semiconductors', 'mobile apps',
				'virtual reality', 'augmented reality', 'electric vehicles', 'robotics', 'quantum computing',
				'space technology', 'streaming technology', 'enterprise software', 'databases',
				'programming languages', 'web development',
			),
			'ai' => array(
				'artificial intelligence', 'generative AI', 'machine learning', 'large language models',
				'AI regulation', 'AI safety', 'AI startups', 'AI chips', 'computer vision',
				'natural language processing', 'open source AI', 'AI agents', 'AI search',
				'AI coding tools', 'AI in healthcare', 'AI in education', 'AI copyright',
				'AI benchmarks', 'multimodal AI', 'robotics AI',
			),
			'sports' => array(
				'NFL news', 'NBA news', 'MLB news', 'NHL news', 'soccer news', 'Formula 1',
				'tennis news', 'golf news', 'college football', 'college basketball', 'WNBA news',
				'UFC news', 'boxing news', 'cricket news', 'rugby news', 'cycling news',
				'Olympics news', 'fantasy sports', 'sports business', 'sports injuries',
			),
			'entertainment' => array(
				'movies', 'television', 'streaming shows', 'music industry', 'celebrity news',
				'box office', 'film festivals', 'award shows', 'book publishing', 'comedy',
				'theater', 'podcasts', 'anime', 'documentaries', 'entertainment business',
			),
			'science-health' => array(
				'science news', 'space exploration', 'astronomy', 'medical research', 'mental health',
				'nutrition', 'fitness', 'genomics', 'vaccines', 'cancer research', 'neuroscience',
				'public health research', 'environmental science', 'ocean science', 'archaeology',
				'paleontology', 'physics', 'chemistry', 'biology', 'health technology',
			),
			'travel' => array(
				'travel deals', 'airline news', 'hotel industry', 'digital nomads', 'national parks',
				'adventure travel', 'family travel', 'luxury travel', 'budget travel', 'food travel',
				'travel safety', 'tourism industry', 'cruise news', 'rail travel', 'city guides',
			),
			'gaming' => array(
				'video games', 'gaming industry', 'esports', 'PC gaming', 'console gaming',
				'game development', 'Nintendo', 'PlayStation', 'Xbox', 'indie games',
				'mobile gaming', 'game reviews', 'gaming hardware', 'virtual reality games',
				'streaming games',
			),
			'wordpress' => array(
				'WordPress', 'WooCommerce', 'Gutenberg WordPress', 'WordPress plugins',
				'WordPress themes', 'WordPress security', 'WordPress performance',
				'WordPress hosting', 'WordPress accessibility', 'WordPress releases',
			),
		);
	}
}
