<?php
/**
 * WordPress dashboard widget.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact 30-day overview on the main WordPress dashboard.
 */
class Dashboard_Widget {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	/**
	 * Adds the widget.
	 */
	public function register() {
		if ( ! Settings::get( 'dashboard_widget' ) || ! Helpers::can_view() ) {
			return;
		}
		wp_add_dashboard_widget( 'pulse_analytics_widget', __( 'Website statistics (last 30 days)', 'pulse-analytics' ), array( $this, 'render' ) );
	}

	/**
	 * Renders the widget.
	 */
	public function render() {
		$query   = new Query( Helpers::today( -29 ), Helpers::today(), array(), 'previous' );
		$reports = new Reports();
		$data    = Cache::remember(
			'dashboard_widget|' . $query->cache_key(),
			5 * MINUTE_IN_SECONDS,
			static function () use ( $reports, $query ) {
				return array(
					'summary' => $reports->summary( $query ),
					'series'  => $reports->timeseries( $query, 'day' ),
					'pages'   => $reports->breakdown( $query, 'page', array( 'limit' => 5 ) )['rows'],
					'sources' => $reports->breakdown( $query, 'source', array( 'limit' => 5 ) )['rows'],
					'today'   => $reports->summary_for( new Query( Helpers::today(), Helpers::today() ) ),
				);
			}
		);
		$current  = $data['summary']['current'];
		$previous = $data['summary']['previous'];
		$link     = admin_url( 'admin.php?page=' . Admin::PAGE_SLUG );
		?>
		<style>
			.pa-dw-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:0 0 12px}
			.pa-dw-kpi{background:#f6f7f7;border-radius:6px;padding:8px 10px}
			.pa-dw-kpi span{display:block;font-size:11px;color:#52514e}
			.pa-dw-kpi strong{display:block;font-size:18px;font-weight:600;color:#0b0b0b;margin-top:2px}
			.pa-dw-kpi em{font-style:normal;font-size:11px}
			.pa-dw-up{color:#006300}.pa-dw-down{color:#d03b3b}
			.pa-dw-spark{display:block;width:100%;height:64px;margin:4px 0 12px}
			.pa-dw-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
			.pa-dw-list ul{margin:0;padding:0;list-style:none}
			.pa-dw-list li{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f1;font-size:12px}
			.pa-dw-list li span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.pa-dw-list h4{margin:0 0 4px;font-size:12px;color:#52514e;text-transform:uppercase;letter-spacing:.04em}
			.pa-dw-foot{margin-top:12px;display:flex;justify-content:space-between;align-items:center}
			@media (max-width:600px){.pa-dw-kpis{grid-template-columns:repeat(2,1fr)}.pa-dw-cols{grid-template-columns:1fr}}
		</style>
		<div class="pa-dw-kpis">
			<?php
			$tiles = array(
				array( __( 'Visitors', 'pulse-analytics' ), $current['visitors'], $previous ? $previous['visitors'] : null ),
				array( __( 'Pageviews', 'pulse-analytics' ), $current['pageviews'], $previous ? $previous['pageviews'] : null ),
				array( __( 'Visitors today', 'pulse-analytics' ), $data['today']['visitors'], null ),
				array( __( 'Bounce rate', 'pulse-analytics' ), $current['bounce_rate'], $previous ? $previous['bounce_rate'] : null ),
			);
			foreach ( $tiles as $i => $tile ) {
				list( $label, $value, $before ) = $tile;
				$shown                          = 3 === $i ? ( null === $value ? '-' : number_format_i18n( $value, 1 ) . '%' ) : number_format_i18n( (int) $value );
				echo '<div class="pa-dw-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $shown ) . '</strong>';
				if ( null !== $before && $before > 0 && null !== $value ) {
					$pct  = ( $value - $before ) / $before * 100;
					$good = 3 === $i ? $pct <= 0 : $pct >= 0;
					echo '<em class="' . ( $good ? 'pa-dw-up' : 'pa-dw-down' ) . '">' . ( $pct >= 0 ? '+' : '-' ) . esc_html( number_format_i18n( abs( $pct ), 1 ) ) . '%</em>';
				}
				echo '</div>';
			}
			?>
		</div>
		<?php echo $this->sparkline( $data['series']['current']['visitors'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from integers only. ?>
		<div class="pa-dw-cols">
			<div class="pa-dw-list">
				<h4><?php esc_html_e( 'Top pages', 'pulse-analytics' ); ?></h4>
				<ul>
				<?php foreach ( $data['pages'] as $row ) : ?>
					<li><span title="<?php echo esc_attr( $row['key'] ); ?>"><?php echo esc_html( $row['label'] ); ?></span><span><?php echo esc_html( number_format_i18n( $row['pageviews'] ) ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( ! $data['pages'] ) : ?>
					<li><span><?php esc_html_e( 'No data yet.', 'pulse-analytics' ); ?></span></li>
				<?php endif; ?>
				</ul>
			</div>
			<div class="pa-dw-list">
				<h4><?php esc_html_e( 'Top sources', 'pulse-analytics' ); ?></h4>
				<ul>
				<?php foreach ( $data['sources'] as $row ) : ?>
					<li><span><?php echo esc_html( $row['label'] ); ?></span><span><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( ! $data['sources'] ) : ?>
					<li><span><?php esc_html_e( 'No data yet.', 'pulse-analytics' ); ?></span></li>
				<?php endif; ?>
				</ul>
			</div>
		</div>
		<div class="pa-dw-foot">
			<a class="button button-primary" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View full statistics', 'pulse-analytics' ); ?></a>
			<a href="<?php echo esc_url( $link . '#/realtime' ); ?>"><?php esc_html_e( 'Real-time view', 'pulse-analytics' ); ?></a>
		</div>
		<?php
	}

	/**
	 * SVG sparkline.
	 *
	 * @param int[] $values Values.
	 * @return string
	 */
	private function sparkline( array $values ) {
		$count = count( $values );
		if ( $count < 2 ) {
			return '';
		}
		$max    = max( 1, max( $values ) );
		$width  = 300;
		$height = 60;
		$points = array();
		foreach ( array_values( $values ) as $i => $v ) {
			$x        = round( $i / ( $count - 1 ) * $width, 1 );
			$y        = round( $height - 4 - ( (int) $v / $max ) * ( $height - 8 ), 1 );
			$points[] = $x . ',' . $y;
		}
		$line = implode( ' ', $points );
		$area = '0,' . $height . ' ' . $line . ' ' . $width . ',' . $height;
		return '<svg class="pa-dw-spark" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Visitors per day', 'pulse-analytics' ) . '">'
			. '<polygon points="' . $area . '" fill="#2a78d6" fill-opacity="0.1"/>'
			. '<polyline points="' . $line . '" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>';
	}
}
