<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\RssReader;

interface RssEnclosureType {

	public const IMAGE = 'image';
	public const AUDIO = 'audio';
	public const VIDEO = 'video';
	public const OTHER = 'other';
}
