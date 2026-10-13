<?php
/**
 * "Views" column in post lists and a statistics box in the editor.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post statistics inside the WordPress admin.
 */
class Post_Stats {

	/**
	 * Views (30 days) keyed by post id for the current list screen.
	 *
	 * @var array|null
	 */
	private $views = null;

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'register_columns' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
	}

	/**
	 * Public post types shown in the admin.
	 *
	 * @return string[]
	 */
	private function post_types() {
		return array_values( get_post_types( array( 'public' => true, 'show_ui' => true ) ) );
	}

	/**
	 * Adds list columns for every public post type.
	 */
	public function register_columns() {
		if ( ! Settings::get( 'post_columns' ) || ! Helpers::can_view() ) {
			return;
		}
		foreach ( $this->post_types() as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Inserts the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['pulse_views'] = '<span class="dashicons dashicons-chart-area" aria-hidden="true"></span> ' . esc_html__( 'Views (30d)', 'pulse-analytics' );
		return $columns;
	}

	/**
	 * Renders a cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'pulse_views' !== $column ) {
			return;
		}
		if ( null === $this->views ) {
			$this->load_views();
		}
		$views = isset( $this->views[ $post_id ] ) ? $this->views[ $post_id ] : null;
		if ( ! $views ) {
			echo '<span aria-hidden="true">-</span>';
			return;
		}
		$url = admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '#/page?path=' . rawurlencode( $views['path'] ) );
		printf(
			'<a href="%1$s" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Open page statistics', 'pulse-analytics' ),
			esc_html( number_format_i18n( $views['views'] ) )
		);
	}

	/**
	 * Loads 30-day views for all posts on the current list page in one query.
	 */
	private function load_views() {
		global $wp_query, $wpdb;
		$this->views = array();
		$ids         = array();
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				$ids[] = (int) ( is_object( $post ) ? $post->ID : $post );
			}
		}
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}
		$t   = Plugin::tables();
		$key = 'postviews|' . implode( ',', $ids ) . '|' . Helpers::today();
		$this->views = Cache::remember(
			$key,
			15 * MINUTE_IN_SECONDS,
			static function () use ( $ids, $t, $wpdb ) {
				$out  = array();
				$sql  = "SELECT pg.post_id, MIN(pg.path) AS path, COUNT(p.id) AS views FROM {$t['pages']} pg INNER JOIN {$t['pageviews']} p ON p.page_id = pg.id AND p.created_at >= %s WHERE pg.post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') GROUP BY pg.post_id';
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, Helpers::today( -29 ) . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $rows as $row ) {
					$out[ (int) $row['post_id'] ] = array(
						'views' => (int) $row['views'],
						'path'  => (string) $row['path'],
					);
				}
				return $out;
			}
		);
	}

	/**
	 * Registers the editor meta box.
	 */
	public function meta_box() {
		if ( ! Settings::get( 'post_metabox' ) || ! Helpers::can_view() ) {
			return;
		}
		foreach ( $this->post_types() as $type ) {
			if ( 'attachment' !== $type ) {
				add_meta_box( 'pulse-analytics-post', __( 'Statistics', 'pulse-analytics' ), array( $this, 'render_meta_box' ), $type, 'side', 'default' );
			}
		}
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_meta_box( $post ) {
		global $wpdb;
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Statistics appear here once the post is published and visited.', 'pulse-analytics' ) . '</p>';
			return;
		}
		$t     = Plugin::tables();
		$stats = Cache::remember(
			'metabox|' . $post->ID . '|' . Helpers::today(),
			10 * MINUTE_IN_SECONDS,
			static function () use ( $post, $t, $wpdb ) {
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['pages']} WHERE post_id = %d", $post->ID ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$out = array(
					'today' => 0,
					'week'  => 0,
					'month' => 0,
					'all'   => 0,
					'time'  => 0,
					'path'  => '',
				);
				if ( ! $ids ) {
					return $out;
				}
				$list        = implode( ',', $ids );
				$out['path'] = (string) $wpdb->get_var( "SELECT path FROM {$t['pages']} WHERE id = " . (int) $ids[0] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row         = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"SELECT SUM(created_at >= %s) AS today, SUM(created_at >= %s) AS week, SUM(created_at >= %s) AS month, COUNT(*) AS all_views, AVG(CASE WHEN created_at >= %s THEN NULLIF(duration,0) END) AS avg_time FROM {$t['pageviews']} WHERE page_id IN ({$list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						Helpers::today() . ' 00:00:00',
						Helpers::today( -6 ) . ' 00:00:00',
						Helpers::today( -29 ) . ' 00:00:00',
						Helpers::today( -29 ) . ' 00:00:00'
					),
					ARRAY_A
				);
				if ( $row ) {
					$out['today'] = (int) $row['today'];
					$out['week']  = (int) $row['week'];
					$out['month'] = (int) $row['month'];
					$out['all']   = (int) $row['all_views'];
					$out['time']  = (int) round( (float) $row['avg_time'] );
				}
				return $out;
			}
		);
		$items = array(
			__( 'Today', 'pulse-analytics' )        => number_format_i18n( $stats['today'] ),
			__( 'Last 7 days', 'pulse-analytics' )  => number_format_i18n( $stats['week'] ),
			__( 'Last 30 days', 'pulse-analytics' ) => number_format_i18n( $stats['month'] ),
			__( 'All time', 'pulse-analytics' )     => number_format_i18n( $stats['all'] ),
			__( 'Avg. time on page', 'pulse-analytics' ) => $stats['time'] >= 60 ? sprintf( '%dm %02ds', intdiv( $stats['time'], 60 ), $stats['time'] % 60 ) : $stats['time'] . 's',
		);
		echo '<table class="widefat striped" style="border:0"><tbody>';
		foreach ( $items as $label => $value ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right"><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';
		if ( '' !== $stats['path'] ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '#/page?path=' . rawurlencode( $stats['path'] ) ) ) . '">' . esc_html__( 'Detailed page statistics', 'pulse-analytics' ) . '</a></p>';
		}
	}
}
