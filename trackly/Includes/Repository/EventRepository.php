<?php
declare(strict_types=1);

namespace Trackly\Includes\Repository;

use Trackly\Includes\Exception\TracklyException;

/**
 * EventRepository handles custom database operations for click telemetry data.
 * Built with strict types and modern PHP constructor property promotion.
 */
class EventRepository {

	/**
	 * Constructor with property promotion.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly string $table_name
	) {}

	/**
	 * Get the active database table name.
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Create custom database tables.
	 */
	public function create_tables(): void {
		$table_name = $this->table_name;

		// v1.1 to v1.2 migration column cleanup protection
		if ( $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) ) {
			$column_check_width = $this->wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'screen_width'" );
			if ( ! empty( $column_check_width ) ) {
				$this->wpdb->query( "ALTER TABLE $table_name DROP COLUMN screen_width" );
			}
			$column_check_height = $this->wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'screen_height'" );
			if ( ! empty( $column_check_height ) ) {
				$this->wpdb->query( "ALTER TABLE $table_name DROP COLUMN screen_height" );
			}
		}

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			page_url varchar(255) NOT NULL,
			element_tag varchar(50) NOT NULL,
			element_selector varchar(255) NOT NULL,
			click_x_pct float NOT NULL,
			click_y_pct float NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY page_url (page_url(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert click telemetry log.
	 */
	public function log_click( array $data ): bool {
		$result = $this->wpdb->insert(
			$this->table_name,
			array(
				'page_url'         => esc_url_raw( $data['page_url'] ),
				'element_tag'      => sanitize_text_field( $data['element_tag'] ),
				'element_selector' => sanitize_text_field( $data['element_selector'] ),
				'click_x_pct'      => floatval( $data['click_x_pct'] ),
				'click_y_pct'      => floatval( $data['click_y_pct'] ),
			),
			array( '%s', '%s', '%s', '%f', '%f' )
		);

		return (bool) $result;
	}

	/**
	 * Fetch coordinates history for a page.
	 */
	public function get_clicks_for_page( string $page_url ): array {
		$clean_url = esc_url_raw( $page_url );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT click_x_pct, click_y_pct, element_selector FROM {$this->table_name} WHERE page_url = %s ORDER BY created_at DESC LIMIT 1000",
				$clean_url
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Clean up old click logs in limited batches to prevent table-locking timeouts.
	 */
	public function daily_cleanup(): void {
		$limit = 500;
		$max_iterations = 100; // Cap execution safety to avoid infinite loops

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$deleted = $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT %d",
					$limit
				)
			);

			if ( false === $deleted || $deleted === 0 ) {
				break;
			}

			// Yield execution control back to PHP & MySQL server thread (50ms pause)
			usleep( 50000 );
		}
	}
}
