<?php

namespace RebelCode\Aggregator\Core;

abstract class Capabilities {

	public const SEE_AGGREGATOR = 'see_aggregator';

	public const ADD_SOURCES = 'add_sources';
	public const EDIT_SOURCES = 'edit_sources';
	public const DELETE_SOURCES = 'delete_sources';

	public const ADD_DISPLAYS = 'add_displays';
	public const EDIT_DISPLAYS = 'edit_displays';
	public const DELETE_DISPLAYS = 'delete_displays';

	public const EDIT_SETTINGS = 'edit_settings';
}
