<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

use DomainException;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Source;
use RebelCode\Aggregator\Core\Store\SourcesStore;
use Throwable;

/**
 * Converts sources to portable export entries and creates new sources from them, so sources can be copied between
 * sites. The export document itself is handled by {@see \RebelCode\Aggregator\Core\Transfer\SiteTransfer}.
 *
 * @since 5.7.0
 */
class SourcesTransfer {

	/** The default maximum number of sources accepted in a single import. */
	public const MAX_SOURCES = 1000;

	/**
	 * Settings that are not exported, because they only make sense on the site they were set on.
	 *
	 * The scheduled activation and pause dates would otherwise re-activate the paused imported sources. The fallback
	 * featured image and author are post and user IDs, which usually exist on another site but point at a different
	 * image or user there, so they are always cleared instead of being matched.
	 */
	public const EXCLUDED_SETTINGS = array( 'futureActivate', 'futurePause', 'fallbackFtImageId', 'fallbackAuthorId' );

	/** Settings that hold HTML which is output on the front end. */
	public const HTML_SETTINGS = array(
		'preContentTemplate',
		'postContentTemplate',
		'attributionTemplate',
		'excerptSuffix',
		'excerptGenSuffix',
	);

	private SourcesStore $sources;

	/**
	 * Constructor.
	 *
	 * @since 5.7.0
	 *
	 * @param SourcesStore $sources The sources store.
	 */
	public function __construct( SourcesStore $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Creates a new, paused source for each export entry.
	 *
	 * Invalid entries are skipped without stopping the others.
	 *
	 * @since 5.7.0
	 *
	 * @param array<int,mixed> $entries The source entries of an export document.
	 * @return array{imported:int,skipped:int,errors:list<string>,sources:array<int,Source>} The import summary. The
	 *         created sources are keyed by the index of their entry.
	 */
	public function importEntries( array $entries ): array {
		$created = array();
		$errors = array();

		foreach ( array_values( $entries ) as $i => $entry ) {
			try {
				$created[ $i ] = $this->sources->save( $this->exportToSource( $entry ) )->getOrThrow();
			} catch ( Throwable $e ) {
				// Only my own validation messages are meant for users. Anything else (such as a type error from
				// malformed settings) is reported generically and logged for debugging.
				if ( $e instanceof DomainException ) {
					$reason = $e->getMessage();
				} else {
					$reason = __( 'invalid source data.', 'wp-rss-aggregator' );
					Logger::warning( 'Skipped source #' . ( $i + 1 ) . ' while importing sources: ' . $e->getMessage() );
				}

				$errors[] = sprintf(
					/* translators: 1: position of the source in the file, 2: reason the source was skipped. */
					__( 'Source #%1$d skipped: %2$s', 'wp-rss-aggregator' ),
					$i + 1,
					$reason
				);
			}
		}

		return array(
			'imported' => count( $created ),
			'skipped' => count( $errors ),
			'errors' => $errors,
			'sources' => $created,
		);
	}

	/**
	 * Gets the maximum number of sources accepted in a single import.
	 *
	 * @since 5.7.0
	 *
	 * @return int The maximum number of sources.
	 */
	public function getMaxSources(): int {
		/**
		 * Filters the maximum number of sources accepted in a single import.
		 *
		 * A file with more sources is rejected before anything is imported.
		 *
		 * @since 5.7.0
		 *
		 * @param int $max The maximum number of sources. Default 1000.
		 */
		return (int) apply_filters( 'wpra.siteTransfer.import.maxSources', self::MAX_SOURCES );
	}

	/**
	 * Converts a source into an export entry.
	 *
	 * @since 5.7.0
	 *
	 * @param Source $source The source.
	 * @return array<string,mixed> The export entry.
	 */
	public function sourceToExport( Source $source ): array {
		$settings = $source->settings->toArray();
		foreach ( self::EXCLUDED_SETTINGS as $key ) {
			unset( $settings[ $key ] );
		}

		return array(
			'name' => $source->name,
			'url' => $source->url,
			'isActive' => $source->isActive,
			'schedule' => $source->schedule ? $source->schedule->toArray() : null,
			'settings' => $settings,
		);
	}

	/**
	 * Creates a new, paused source from an export entry.
	 *
	 * @since 5.7.0
	 *
	 * @param mixed $entry The export entry.
	 * @return Source The new source, without an ID.
	 * @throws DomainException If the entry is invalid.
	 */
	public function exportToSource( $entry ): Source {
		if ( ! is_array( $entry ) ) {
			throw new DomainException( esc_html__( 'invalid source data.', 'wp-rss-aggregator' ) );
		}

		$url = is_string( $entry['url'] ?? null ) ? esc_url_raw( trim( $entry['url'] ), array( 'http', 'https' ) ) : '';
		if ( $url === '' ) {
			throw new DomainException( esc_html__( 'missing or invalid feed URL.', 'wp-rss-aggregator' ) );
		}

		$name = is_string( $entry['name'] ?? null ) ? sanitize_text_field( $entry['name'] ) : '';

		$settings = $entry['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			throw new DomainException( esc_html__( 'invalid source settings.', 'wp-rss-aggregator' ) );
		}
		foreach ( self::EXCLUDED_SETTINGS as $key ) {
			unset( $settings[ $key ] );
		}

		$source = new Source( null, $name !== '' ? $name : $url );
		$source->url = $url;
		$source->isActive = false;
		$source->schedule = ScheduleFactory::from( $entry['schedule'] ?? null )->getOrThrow();
		$source->settings = SourceSettings::fromArray( $settings );

		$this->resetSiteReferences( $source->settings );
		$this->filterHtml( $source->settings );

		return $source;
	}

	/**
	 * Resets the multisite target site when it does not exist on this network.
	 *
	 * The fallback featured image and author never reach this point, see {@see self::EXCLUDED_SETTINGS}.
	 *
	 * @since 5.7.0
	 *
	 * @param SourceSettings $settings The settings to modify.
	 */
	protected function resetSiteReferences( SourceSettings $settings ): void {
		if ( $settings->postSite !== null && ( ! is_multisite() || ! get_site( $settings->postSite ) ) ) {
			$settings->postSite = null;
		}
	}

	/**
	 * Filters HTML settings when the current user may not post unfiltered HTML.
	 *
	 * @since 5.7.0
	 *
	 * @param SourceSettings $settings The settings to modify.
	 */
	protected function filterHtml( SourceSettings $settings ): void {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		foreach ( self::HTML_SETTINGS as $key ) {
			$settings->$key = wp_kses_post( $settings->$key );
		}
	}
}
