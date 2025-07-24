<?php

namespace RebelCode\Aggregator\Core;

use DateTime;
use RebelCode\Aggregator\Core\AdminUi\Tutorials;
use RebelCode\Aggregator\Core\Rpc\Handlers\RpcRelsHandler;
use RebelCode\Aggregator\Core\Rpc\Handlers\RpcSettingsHandler;
use RebelCode\Aggregator\Core\Rpc\Handlers\RpcWpHandler;
use RebelCode\Aggregator\Core\Rpc\RpcClassHandler;
use RebelCode\Aggregator\Core\Rpc\RpcFilterHandler;
use RebelCode\Aggregator\Core\Rpc\RpcServer;
use RebelCode\Aggregator\Core\RssReader\RssFeedInfo;
use RebelCode\Aggregator\Core\Source\Schedule\DailySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\HourlySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\WeeklySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\MonthlySchedule;
use RebelCode\Aggregator\Core\Source\Schedule\MinuteSchedule;
use RebelCode\Aggregator\Core\Utils\Progress;

wpra()->addModule(
	'rpc',
	array( 'settings', 'importer', 'renderer', 'licensing' ),
	function ( Settings $settings, Importer $importer, Renderer $renderer, Licensing $licensing ) {
		$hydrators = apply_filters(
			'wpra.rpc.hydrators',
			array(
				Source::class => fn ( array $array ) => Source::fromArray( $array ),
				Display::class => fn ( array $array ) => Display::fromArray( $array ),
				RejectedItem::class => fn ( array $array ) => RejectedItem::fromArray( $array ),
			)
		);

		$handlers = apply_filters(
			'wpra.rpc.handlers',
			array(
				'importer' => new RpcClassHandler( $importer, $hydrators ),
				'renderer' => new RpcClassHandler( $renderer, $hydrators ),
				'sources' => new RpcClassHandler( $importer->sources, $hydrators ),
				'wpPosts' => new RpcClassHandler( $importer->wpPosts, $hydrators ),
				'rejectList' => new RpcClassHandler( $importer->rejectList, $hydrators ),
				'displays' => new RpcClassHandler( $renderer->displays, $hydrators ),
				'settings' => new RpcClassHandler( new RpcSettingsHandler( $settings ) ),
				'tutorials' => new RpcClassHandler( new Tutorials() ),
				'progress' => new RpcClassHandler( $importer->progress ),
				'rels' => new RpcClassHandler( new RpcRelsHandler( $importer, $renderer ), $hydrators ),
				'wp' => new RpcClassHandler( new RpcWpHandler() ),
				'license' => new RpcClassHandler( $licensing ),
				'v4' => new RpcFilterHandler( 'wpra.rpc.v4.%s' ),
			)
		);

		$transforms = apply_filters(
			'wpra.rpc.transforms',
			array(
				Source::class => fn ( Source $s ) => $s->toArray(),
				RejectedItem::class => fn ( RejectedItem $i ) => $i->toArray(),
				IrPost::class => fn ( IrPost $p ) => $p->toArray(),
				Progress::class => fn ( Progress $p ) => $p->toArray(),
				RssFeedInfo::class => fn ( RssFeedInfo $r ) => $r->toArray(),
				DateTime::class => fn ( DateTime $d ) => $d->format( DATE_ATOM ),
				MinuteSchedule::class => fn ( MinuteSchedule $s ) => $s->toArray(),
				HourlySchedule::class => fn ( HourlySchedule $s ) => $s->toArray(),
				DailySchedule::class => fn ( DailySchedule $s ) => $s->toArray(),
				WeeklySchedule::class => fn ( WeeklySchedule $s ) => $s->toArray(),
				MonthlySchedule::class => fn ( MonthlySchedule $s ) => $s->toArray(),
			)
		);

		$server = new RpcServer( $handlers, $transforms );

		add_action(
			'wp_ajax_wpra_rpc',
			function () use ( $server ) {
				$server->serve();
			}
		);

		return $server;
	}
);
