<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Source;

interface FtImageToUse {

	public const NO_IMAGE = 'no_image';
	public const AUTO = 'auto';
	public const CONTENT_FIRST = 'content_first';
	public const CONTENT_LAST = 'content_last';
	public const CONTENT_BEST = 'content_best';
	public const USER = 'user';
	public const FEED_IMAGE = 'feed';
	public const ITUNES = 'itunes';
	public const MEDIA = 'media';
	public const ENCLOSURE = 'enclosure';
	public const SOCIAL = 'social';
}
