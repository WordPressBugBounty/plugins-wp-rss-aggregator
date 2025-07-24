<?php

namespace RebelCode\Aggregator\Core\Display;

use RebelCode\Aggregator\Core\Utils\Arrays;
use RebelCode\Aggregator\Core\Utils\ArraySerializable;

class DisplayInstance implements ArraySerializable {

	public int $id;
	public string $title;
	public string $type;
	public string $url;

	public function __construct( int $id, string $title, string $type, string $url ) {
		$this->id = $id;
		$this->title = $title;
		$this->type = $type;
		$this->url = $url;
	}

	public function toArray(): array {
		return array(
			'id' => $this->id,
			'title' => $this->title,
			'type' => $this->type,
			'url' => $this->url,
		);
	}

	/** @return list<DisplayInstance> */
	public static function findShortcodes( int $displayId ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$postsTable = $wpdb->prefix . 'posts';

		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_type
                    FROM $postsTable
                    WHERE (post_type = 'post' OR post_type = 'page') AND post_status != 'trash' AND
                          post_content REGEXP '\\\\[wp-rss-aggregator[[:blank:]]+id=[\\'\"]%d[\\'\"]'",
			$displayId,
		);

		$results = $wpdb->get_results( $query ) ?? array();

		return Arrays::map( $results, fn ( $row ) => self::fromPostRow( $row ) );
	}

	/** @return list<DisplayInstance> */
	public static function findBlocks( int $displayId ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$postsTable = $wpdb->prefix . 'posts';

		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_type
                    FROM $postsTable
                    WHERE (post_type = 'post' OR post_type = 'page') AND post_status != 'trash' AND
                          post_content REGEXP '<!-- wp:wpra-shortcode/wpra-shortcode \\\\{\"id\":\"?%d\"?'",
			$displayId
		);

		$results = $wpdb->get_results( $query );

		return Arrays::map( $results, fn ( $row ) => self::fromPostRow( $row ) );
	}

	private static function fromPostRow( object $row ): self {
		$id = (int) $row->ID;
		$title = $row->post_title;
		$type = get_post_type_object( $row->post_type )->labels->singular_name;
		$url = get_permalink( $row->ID );

		return new self( $id, $title, $type, $url );
	}
}
