<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Transfer;

use DomainException;
use RebelCode\Aggregator\Core\Logger;
use RebelCode\Aggregator\Core\Settings;
use RebelCode\Aggregator\Core\Source\SourcesTransfer;
use RebelCode\Aggregator\Core\Store\SourcesStore;

/**
 * Exports the settings and sources of a site to one document, and imports them on another site.
 *
 * Folders and displays are added by a {@see SiteTransferLinks} implementation when the plan includes them. They link
 * to sources by database IDs, which differ on every site, so the document keeps each source's original ID as its
 * "ref" and the import passes the new ID of each ref to the links implementation.
 *
 * @since 5.7.0
 */
class SiteTransfer {

	/** The value of the "format" field that identifies an export document. */
	public const FORMAT = 'wpra-site';

	/** The current version of the export document format. */
	public const VERSION = 1;

	/** The parts that a document can contain, in import order. */
	public const PARTS = array( 'settings', 'sources', 'folders', 'displays' );

	/** The parts that Core exports and imports by itself. */
	public const CORE_PARTS = array( 'settings', 'sources' );

	private Settings $settings;
	private SourcesStore $sources;
	private SourcesTransfer $sourcesTransfer;

	/**
	 * Constructor.
	 *
	 * @since 5.7.0
	 *
	 * @param Settings        $settings The plugin settings.
	 * @param SourcesStore    $sources The sources store.
	 * @param SourcesTransfer $sourcesTransfer The sources entry converter.
	 */
	public function __construct( Settings $settings, SourcesStore $sources, SourcesTransfer $sourcesTransfer ) {
		$this->settings = $settings;
		$this->sources = $sources;
		$this->sourcesTransfer = $sourcesTransfer;
	}

	/**
	 * Gets the folders and displays implementation, if the plan includes one.
	 *
	 * @since 5.7.0
	 *
	 * @return SiteTransferLinks|null The implementation, or null.
	 */
	public function getLinks(): ?SiteTransferLinks {
		/**
		 * Filters the implementation that adds folders and displays to the site export and import.
		 *
		 * Core exports and imports settings and sources only. Aggregator Pro and Elite return a
		 * {@see SiteTransferLinks} here. It's a filter rather than a dependency so that Premium can check that this
		 * interface exists first, and pause itself on an older Core instead of causing a fatal error.
		 *
		 * @since 5.7.0
		 *
		 * @param SiteTransferLinks|null $links The implementation, or null when the plan has none. Any other value is
		 *                                     ignored.
		 */
		$links = apply_filters( 'wpra.siteTransfer.links', null );

		return $links instanceof SiteTransferLinks ? $links : null;
	}

	/**
	 * Gets the parts that this site can export and import.
	 *
	 * @since 5.7.0
	 *
	 * @return list<string> The parts, from {@see self::PARTS}.
	 */
	public function getAvailableParts(): array {
		$links = $this->getLinks();
		$parts = array_merge( self::CORE_PARTS, $links ? $links->getParts() : array() );

		return array_values( array_intersect( self::PARTS, $parts ) );
	}

	/**
	 * Exports the chosen parts of the site.
	 *
	 * Folders and displays are only useful with the sources they link to, so choosing either one also exports the
	 * sources.
	 *
	 * @since 5.7.0
	 *
	 * @param list<string> $parts The parts to export, from {@see self::PARTS}.
	 * @return array<string,mixed> The export document.
	 */
	public function export( array $parts ): array {
		$parts = $this->normalizeParts( $parts );

		$doc = array(
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'pluginVersion' => defined( 'WPRA_VERSION' ) ? (string) WPRA_VERSION : '',
			'exportedAt' => gmdate( DATE_ATOM ),
		);

		if ( in_array( 'settings', $parts, true ) ) {
			$doc['settings'] = $this->settings->toArray();
		}

		if ( in_array( 'sources', $parts, true ) ) {
			$doc['sources'] = array();
			foreach ( $this->sources->getList()->getOrThrow() as $source ) {
				$doc['sources'][] = array( 'ref' => $source->id ) + $this->sourcesTransfer->sourceToExport( $source );
			}
		}

		$links = $this->getLinks();
		$linkParts = $links ? array_values( array_intersect( $links->getParts(), $parts ) ) : array();
		if ( $links && count( $linkParts ) > 0 ) {
			$doc += $links->export( $linkParts );
		}

		Logger::info( 'Exported ' . $this->describeDocument( $doc ) . '.' );

		return $doc;
	}

	/**
	 * Imports the chosen parts of an export document.
	 *
	 * Sources are always created as new and paused, and existing sources are never changed. Settings are replaced
	 * when they are chosen. Folders and displays are only imported together with the sources they link to.
	 *
	 * A settings export from before this format existed (the plain settings object) is also accepted, as settings only.
	 *
	 * @since 5.7.0
	 *
	 * @param string       $json The JSON export document.
	 * @param list<string> $parts The parts to import, from {@see self::PARTS}.
	 * @return array<string,mixed> The import summary.
	 * @throws DomainException If the document is invalid as a whole. Nothing is imported in that case.
	 */
	public function import( string $json, array $parts ): array {
		Logger::setContext( array( 'transfer' => uniqid() ) );

		try {
			$result = $this->runImport( $json, $parts );
			$this->logImport( $result );

			return $result;
		} catch ( DomainException $e ) {
			Logger::warning( 'Rejected the import file: ' . $e->getMessage() );
			throw $e;
		} finally {
			Logger::clearContext();
		}
	}

	/**
	 * Runs an import. See {@see self::import()}.
	 *
	 * @since 5.7.0
	 *
	 * @param string       $json The JSON export document.
	 * @param list<string> $parts The parts to import, from {@see self::PARTS}.
	 * @return array<string,mixed> The import summary.
	 * @throws DomainException If the document is invalid as a whole.
	 */
	protected function runImport( string $json, array $parts ): array {
		$doc = $this->parseDocument( $json );
		$links = $this->getLinks();

		$result = array(
			'settings' => null,
			'sources' => null,
			'folders' => null,
			'displays' => null,
			'notes' => array(),
		);

		$inFile = $this->getDocumentParts( $doc );
		$parts = array_values( array_intersect( self::PARTS, $parts, $inFile ) );
		Logger::info( 'Importing ' . implode( ', ', $parts ) . ' from an export file with ' . implode( ', ', $inFile ) . '.' );

		$available = $this->getAvailableParts();
		if ( count( array_diff( $parts, $available ) ) > 0 ) {
			$parts = array_values( array_intersect( $parts, $available ) );
			$result['notes'][] = __( 'Folders and displays in this file were not imported, because they need Aggregator Pro or Elite.', 'wp-rss-aggregator' );
		}

		if ( ! in_array( 'sources', $parts, true ) && array_intersect( array( 'folders', 'displays' ), $parts ) ) {
			$parts = array_values( array_diff( $parts, array( 'folders', 'displays' ) ) );
			$result['notes'][] = __( 'Folders and displays were not imported, because they need the sources from the same file.', 'wp-rss-aggregator' );
		}

		$linkParts = $links ? array_values( array_intersect( $links->getParts(), $parts ) ) : array();
		if ( $links && count( $linkParts ) > 0 ) {
			$links->validate( $doc );
		}

		if ( in_array( 'settings', $parts, true ) ) {
			$this->settings->patch( $doc['settings'] )->save();
			$result['settings'] = $this->settings->toArray();
		}

		$sourceIds = array();
		if ( in_array( 'sources', $parts, true ) ) {
			$entries = array_values( $doc['sources'] );
			$imported = $this->sourcesTransfer->importEntries( $entries );

			foreach ( $imported['sources'] as $i => $source ) {
				$ref = is_array( $entries[ $i ] ) ? ( $entries[ $i ]['ref'] ?? null ) : null;
				if ( is_int( $ref ) ) {
					$sourceIds[ $ref ] = $source->id;
				}
			}

			unset( $imported['sources'] );
			$result['sources'] = $imported;
		}

		if ( $links && count( $linkParts ) > 0 ) {
			$result = array_merge( $result, $links->import( $doc, $linkParts, $sourceIds ) );
		}

		return $result;
	}

	/**
	 * Gets the parts that a document contains.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $doc A parsed export document.
	 * @return list<string> The parts, from {@see self::PARTS}.
	 */
	public function getDocumentParts( array $doc ): array {
		$parts = array();
		foreach ( self::PARTS as $part ) {
			if ( isset( $doc[ $part ] ) && is_array( $doc[ $part ] ) ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * Parses and validates an export document.
	 *
	 * @since 5.7.0
	 *
	 * @param string $json The JSON export document.
	 * @return array<string,mixed> The document.
	 * @throws DomainException If the document is invalid.
	 */
	protected function parseDocument( string $json ): array {
		$data = json_decode( $json, true );

		// The document is a JSON object. An empty or list-shaped array can't be one.
		if ( ! is_array( $data ) || $data === array() || isset( $data[0] ) ) {
			throw new DomainException( esc_html__( 'The file is not a valid Aggregator export file.', 'wp-rss-aggregator' ) );
		}

		// Settings exports made before this format are the plain settings object. Any other JSON object has none of
		// this site's settings, so it is rejected instead of being imported as settings that change nothing.
		if ( ! isset( $data['format'] ) ) {
			if ( count( array_intersect_key( $data, $this->settings->toArray() ) ) === 0 ) {
				throw new DomainException( esc_html__( 'The file is not a valid Aggregator export file.', 'wp-rss-aggregator' ) );
			}

			return array( 'settings' => $data );
		}

		if ( $data['format'] !== self::FORMAT ) {
			throw new DomainException( esc_html__( 'The file is not a valid Aggregator export file.', 'wp-rss-aggregator' ) );
		}

		$version = $data['version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 || $version > self::VERSION ) {
			throw new DomainException(
				esc_html__( 'This export file was made with an unsupported version of Aggregator.', 'wp-rss-aggregator' )
			);
		}

		foreach ( self::PARTS as $part ) {
			if ( isset( $data[ $part ] ) && ! is_array( $data[ $part ] ) ) {
				throw new DomainException( esc_html__( 'The file is not a valid Aggregator export file.', 'wp-rss-aggregator' ) );
			}
		}

		if ( count( $this->getDocumentParts( $data ) ) === 0 ) {
			throw new DomainException( esc_html__( 'The file is not a valid Aggregator export file.', 'wp-rss-aggregator' ) );
		}

		$max = $this->sourcesTransfer->getMaxSources();
		if ( isset( $data['sources'] ) && count( $data['sources'] ) > $max ) {
			throw new DomainException(
				esc_html(
					sprintf(
						/* translators: %d: maximum number of sources per import. */
						__( 'The file contains too many sources. Import at most %d sources at a time.', 'wp-rss-aggregator' ),
						$max
					)
				)
			);
		}

		return $data;
	}

	/**
	 * Logs the summary of an import, with each skipped item and note.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $result The import summary.
	 */
	protected function logImport( array $result ): void {
		if ( $result['settings'] !== null ) {
			Logger::info( 'Imported settings.' );
		}

		$sources = $result['sources'];
		if ( is_array( $sources ) ) {
			Logger::info( sprintf( 'Imported %d sources, skipped %d.', $sources['imported'], $sources['skipped'] ) );
		}

		$folders = $result['folders'];
		if ( is_array( $folders ) ) {
			Logger::info( sprintf( 'Folders: created %d, reused %d, skipped %d.', $folders['created'], $folders['reused'], $folders['skipped'] ) );
		}

		$displays = $result['displays'];
		if ( is_array( $displays ) ) {
			Logger::info( sprintf( 'Imported %d displays, skipped %d.', $displays['imported'], $displays['skipped'] ) );

			foreach ( $displays['ids'] as $id ) {
				Logger::info( sprintf( 'Display "%s" #%d is now #%d.', $id['name'], $id['oldId'], $id['newId'] ) );
			}
		}

		foreach ( array( $sources, $folders, $displays ) as $part ) {
			foreach ( is_array( $part ) ? $part['errors'] : array() as $error ) {
				Logger::warning( $error );
			}
		}

		$notes = array_merge( $result['notes'], is_array( $displays ) ? $displays['notes'] : array() );
		foreach ( $notes as $note ) {
			Logger::notice( $note );
		}
	}

	/**
	 * Describes what an export document contains, for the log.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $doc The export document.
	 * @return string The description, such as "settings, 21 sources, 3 folders".
	 */
	protected function describeDocument( array $doc ): string {
		$parts = array();
		foreach ( $this->getDocumentParts( $doc ) as $part ) {
			$parts[] = $part === 'settings' ? 'settings' : count( $doc[ $part ] ) . ' ' . $part;
		}

		return count( $parts ) > 0 ? implode( ', ', $parts ) : 'nothing';
	}

	/**
	 * Adds the sources part when folders or displays are chosen, and drops parts this site can't export.
	 *
	 * @since 5.7.0
	 *
	 * @param list<string> $parts The chosen parts.
	 * @return list<string> The parts to export.
	 */
	protected function normalizeParts( array $parts ): array {
		if ( array_intersect( array( 'folders', 'displays' ), $parts ) ) {
			$parts[] = 'sources';
		}

		return array_values( array_intersect( $this->getAvailableParts(), $parts ) );
	}
}
