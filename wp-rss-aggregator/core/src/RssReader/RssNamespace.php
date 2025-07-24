<?php /** @noinspection HttpUrlsUsage */

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

interface RssNamespace {

	public const XML = SIMPLEPIE_NAMESPACE_XML;
	public const ATOM_0_3 = SIMPLEPIE_NAMESPACE_ATOM_03;
	public const ATOM_1_0 = SIMPLEPIE_NAMESPACE_ATOM_10;
	public const RDF = SIMPLEPIE_NAMESPACE_RDF;
	public const RSS_0_9 = SIMPLEPIE_NAMESPACE_RSS_090;
	public const RSS_1_0 = SIMPLEPIE_NAMESPACE_RSS_10;
	public const RSS_1_0_Content = SIMPLEPIE_NAMESPACE_RSS_10_MODULES_CONTENT;
	public const RSS_1_0_Slash = 'http://purl.org/rss/1.0/modules/slash/';
	public const RSS_2_0 = SIMPLEPIE_NAMESPACE_RSS_20;
	public const DC_1_0 = SIMPLEPIE_NAMESPACE_DC_10;
	public const DC_1_1 = SIMPLEPIE_NAMESPACE_DC_11;
	public const W3C_BASIC_GEO = SIMPLEPIE_NAMESPACE_W3C_BASIC_GEO;
	public const GEO_RSS = SIMPLEPIE_NAMESPACE_GEORSS;
	public const MEDIA_RSS = SIMPLEPIE_NAMESPACE_MEDIARSS;
	public const MEDIA_RSS_WRONG = SIMPLEPIE_NAMESPACE_MEDIARSS_WRONG;
	public const MEDIA_RSS_WRONG2 = SIMPLEPIE_NAMESPACE_MEDIARSS_WRONG2;
	public const MEDIA_RSS_WRONG3 = SIMPLEPIE_NAMESPACE_MEDIARSS_WRONG3;
	public const MEDIA_RSS_WRONG4 = SIMPLEPIE_NAMESPACE_MEDIARSS_WRONG4;
	public const MEDIA_RSS_WRONG5 = SIMPLEPIE_NAMESPACE_MEDIARSS_WRONG5;
	public const ITUNES = SIMPLEPIE_NAMESPACE_ITUNES;
	public const XHTML = SIMPLEPIE_NAMESPACE_XHTML;
	public const WFW = 'http://wellformedweb.org/CommentAPI/';
	public const SYNDICATION = 'http://purl.org/rss/1.0/modules/syndication/';
}
