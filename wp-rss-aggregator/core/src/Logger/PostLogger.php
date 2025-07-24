<?php

namespace RebelCode\Aggregator\Core\Logger;

use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Database;
use Psr\Log\LogLevel;
use Psr\Log\AbstractLogger;

class PostLogger extends AbstractLogger {

	private const CPT_SLUG = 'wpra-logger';
	private Database $db;
	private bool $enabled;

	public function __construct( Database $db, bool $enabled ) {
		$this->db = $db;
		$this->enabled = $enabled;
	}

	public function log( $level, $message, array $context = array() ) {
		if ( ! $this->enabled ) {
			return;
		}
		$post_status = $this->get_post_status_for_level( $level );

		$post_data = array(
			'post_type'    => self::CPT_SLUG,
			'post_title'   => sanitize_text_field( mb_substr( $message, 0, 20 ) ),
			'post_content' => wp_kses_post( $message ),
			'post_status'  => $post_status,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
			update_post_meta( $post_id, '_log_ctx_', json_encode( $context ) );
			update_post_meta( $post_id, '_log_level', sanitize_text_field( $level ) );
		}
	}

	private function get_all_log_statuses(): array {
		return array(
			LogLevel::EMERGENCY => 'wpra_emergency',
			LogLevel::ALERT     => 'wpra_alert',
			LogLevel::CRITICAL  => 'wpra_critical',
			LogLevel::ERROR     => 'wpra_error',
			LogLevel::WARNING   => 'wpra_warning',
			LogLevel::NOTICE    => 'wpra_notice',
			LogLevel::INFO      => 'wpra_info',
			LogLevel::DEBUG     => 'wpra_debug',
		);
	}

	private function get_post_status_for_level( string $level ): string {
		$status_map = $this->get_all_log_statuses();
		return $status_map[ strtolower( $level ) ] ?? 'wpra_info';
	}

	public function getLogs( ?int $num = null, int $page = 1 ): Result {
		try {
			$args = array(
				'post_type'      => 'wpra-logger',
				'post_status'    => $this->get_all_log_statuses(),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => $num,
				'paged'          => $page,
				'no_found_rows'  => false,
				'fields'         => 'array',
			);

			$query = new \WP_Query( $args );
			$logs  = array();

			foreach ( $query->posts as $post ) {
				$post_id = $post->ID;

				$level = get_post_meta( $post_id, '_log_level', true );

				$all_meta = get_post_meta( $post_id );

				$context = array();
				foreach ( $all_meta as $meta_key => $meta_values ) {
					if ( strpos( $meta_key, '_log_ctx_' ) === 0 ) {
						$ctx_key = substr( $meta_key, strlen( '_log_ctx_' ) );
						$value = maybe_unserialize( $meta_values[0] );

						$decoded = json_decode( $value, true );
						$context[ $ctx_key ] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
					}
				}

				$logs[] = array(
					'date'    => $post->post_date,
					'level'   => $level,
					'message' => $post->post_content,
					'context' => json_encode( $context ),
				);
			}

			wp_reset_postdata();

			return Result::Ok( $logs );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	public function getCount(): Result {
		try {
			$query = "
				SELECT COUNT(ID)
				FROM {$this->db->wpdb->posts}
				WHERE post_type = %s
			";
			$count = $this->db->getCol( $query, array( 'wpra-logger' ) );
			return Result::Ok( (int) $count[0] );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}

	public function deleteAll(): Result {
		try {
			$query = "
				DELETE FROM {$this->db->wpdb->posts}
				WHERE post_type = %s
			";
			$this->db->query( $query, array( 'wpra-logger' ) );
			return Result::Ok( true );
		} catch ( \Throwable $e ) {
			return Result::Err( $e->getMessage() );
		}
	}
}
