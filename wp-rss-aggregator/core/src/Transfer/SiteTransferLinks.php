<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Transfer;

/**
 * Adds folders and displays to the export and import of a site.
 *
 * Core exports and imports settings and sources. Plans that include folders and displays in the transfer provide an
 * implementation through the `wpra.siteTransfer.links` filter.
 *
 * @since 5.7.0
 */
interface SiteTransferLinks {

	/**
	 * Gets the parts this implementation adds.
	 *
	 * @since 5.7.0
	 *
	 * @return list<string> Parts from {@see SiteTransfer::PARTS}.
	 */
	public function getParts(): array;

	/**
	 * Exports the chosen parts.
	 *
	 * @since 5.7.0
	 *
	 * @param list<string> $parts The chosen parts that this implementation adds.
	 * @return array<string,mixed> The document fields to add, such as "folders" and "displays".
	 */
	public function export( array $parts ): array;

	/**
	 * Imports the chosen parts.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $doc The parsed export document.
	 * @param list<string>        $parts The chosen parts that this implementation adds.
	 * @param array<int,int>      $sourceIds The IDs of the imported sources, by their ID on the original site.
	 * @return array<string,mixed> The import summary for each part, keyed by part.
	 */
	public function import( array $doc, array $parts, array $sourceIds ): array;

	/**
	 * Checks the size of the chosen parts before anything is imported.
	 *
	 * @since 5.7.0
	 *
	 * @param array<string,mixed> $doc The parsed export document.
	 * @throws \DomainException If a part has too many items.
	 */
	public function validate( array $doc ): void;
}
