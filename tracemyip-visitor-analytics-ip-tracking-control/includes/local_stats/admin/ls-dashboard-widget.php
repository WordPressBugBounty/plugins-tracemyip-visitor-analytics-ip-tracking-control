<?php
/* TraceMyIP > UnFiltered Stats Tracker */
defined('ABSPATH') || exit;

class TMIP_Local_Stats_Dashboard {
    
    private static $instance;
    
    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
	private function __construct() {
		// Move checks to add_dashboard_widget method
		add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_assets'));
		add_action('wp_ajax_tmip_get_daily_stats', array($this, 'get_daily_stats_ajax'));
		add_action('wp_ajax_tmip_get_paginated_content', array($this, 'get_paginated_content'));

		// Check if this is first activation and handle widget positioning
		if (get_option('tmip_lc_first_activation', true)) {
			add_action('admin_init', array($this, 'initialize_widget_position'), 20);
		}
	}
	
	/**
	 * Initialize widget position on first activation
	 */
	public function initialize_widget_position() {
		if (get_option('tmip_lc_first_activation', true)) {
			$this->set_widget_position();
			update_option('tmip_lc_first_activation', false);
		}
	}  
	
	public function add_dashboard_widget() {
		
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) {
				return;
		}
		
		// Check settings here instead of constructor
		if (!get_option('tmip_lc_enable_daily_chart', 1) && 
			!get_option('tmip_lc_enable_top_posts', 1) && 
			!get_option('tmip_lc_enable_top_pages', 1) && 
			!get_option('tmip_lc_enable_recent_views', 1)) {
			return;
		}
		
		// Check settings here instead of constructor
		if (!get_option('tmip_lc_enable_daily_chart', 1) && 
			!get_option('tmip_lc_enable_top_posts', 1) && 
			!get_option('tmip_lc_enable_top_pages', 1) && 
			!get_option('tmip_lc_enable_recent_views', 1)) {
			return;
		}

		$tz = new DateTimeZone(TMIP_Local_Stats::get_timezone_string());
		$date = new DateTime('now', $tz);
		$date_format = get_option('tmip_lc_date_format', 'Y-m-d');
		$time_format = get_option('tmip_lc_time_format', 'H:i:s');

		$formatted_datetime = $date->format($date_format . ' ' . $time_format);

		// Create title with logo
		$title = sprintf(
			'<img src="%s" alt="TraceMyIP Logo" class="tmip-widget-logo" /> %s',
			esc_url(TMIP_LOCAL_STATS_URL . 'assets/images/TraceMyIP-Logo_40x40.png'),
			sprintf(
				__('TraceMyIP UnFiltered Stats <span class="tmip-datetime-display">%s</span>', 'tracemyip-local-stats'),
				$formatted_datetime
			)
		);

		wp_add_dashboard_widget(
			'tmip_local_stats_dashboard',
			$title,
			array($this, 'render_dashboard_widget')
		);

		// Check if this is first activation and handle widget positioning
		if (get_option('tmip_lc_first_activation', true)) {
			$this->set_widget_position();
			update_option('tmip_lc_first_activation', false);
		}
	}


    public function enqueue_dashboard_assets($hook) {
        if ('index.php' !== $hook) return;
        
        wp_enqueue_script('apexcharts', TMIP_LOCAL_STATS_URL . 'assets/apexcharts/dist/apexcharts.min.js', array(), TMIP_LOCAL_STATS_VERSION, true);
        wp_enqueue_style('apexcharts-local-css', TMIP_LOCAL_STATS_URL . 'assets/css/ls-apexcharts.css', array(), TMIP_LOCAL_STATS_VERSION);
        
		wp_enqueue_script('tmip-local-stats-admin', TMIP_LOCAL_STATS_URL . 'assets/js/ls-js-admin.js', array('jquery'), TMIP_LOCAL_STATS_VERSION, true);
		wp_enqueue_style('tmip-local-stats-dashboard', TMIP_LOCAL_STATS_URL . 'assets/css/ls-css-admin.css', array(), TMIP_LOCAL_STATS_VERSION);
		
		 // Add localization for JS
		wp_localize_script('tmip-local-stats-admin', 'tmipLocalStats', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('tmip_dashboard_nonce'),
			'paginationNonce' => wp_create_nonce('tmip_pagination_nonce')
		));
		
    }
    
	
	// In ls-dashboard-widget.php
	public function get_daily_stats_ajax() {
		if (!check_ajax_referer('tmip_dashboard_nonce', 'security', false)) {
			wp_send_json_error('Invalid security token');
			return;
		}

		try {
			global $wpdb;

			$date_format = get_option('tmip_lc_date_format', 'Y-m-d');
			$retention_days = get_option('tmip_lc_charts_retention', 30);

			// Get current time in user's timezone
			$current_time = TMIP_Local_Stats::get_current_time_sql();
			$tz = new DateTimeZone(TMIP_Local_Stats::get_timezone_string());
			$datetime = new DateTime($current_time, $tz);

			$end_date = $datetime->format('Y-m-d 23:59:59');
			$start_date = (clone $datetime)
				->modify("-{$retention_days} days")
				->format('Y-m-d 00:00:00');
			

			// Get daily stats from the daily_stats table
			$results = $wpdb->get_results($wpdb->prepare(
				"SELECT stat_date AS date, views_count, unique_visits_count, bot_views_count, posts_views_count, pages_views_count, custom_views_count, media_views_count, other_views_count
				 FROM {$wpdb->prefix}tmip_lc_daily_stats
				 WHERE stat_date BETWEEN %s AND %s
				 ORDER BY stat_date",
				$start_date,
				$end_date
			));

			$dates = array();
			$views = array();
			$unique_visits = array();
			$bot_views = array();
			$posts_views = array();
			$pages_views = array();
			$custom_views = array();
			$media_views = array();
			$other_views = array();

			foreach ($results as $row) {
				$dates[] = $row->date;
				$views[] = $row->views_count;
				$unique_visits[] = $row->unique_visits_count;
				$bot_views[] = $row->bot_views_count;
				$posts_views[] = $row->posts_views_count;
				$pages_views[] = $row->pages_views_count;
				$custom_views[] = $row->custom_views_count;
				$media_views[] = $row->media_views_count;
				$other_views[] = $row->other_views_count;
			}

			// Fill missing dates with zeros
			$date = strtotime($start_date);
			$endDate = strtotime($end_date);
			while ($date <= $endDate) {
				$currentDate = date('Y-m-d', $date);
				if (!in_array($currentDate, $dates)) {
					$dates[] = $currentDate;
					$views[] = 0;
					$unique_visits[] = 0;
					$bot_views[] = 0;
					$posts_views[] = 0;
					$pages_views[] = 0;
					$custom_views[] = 0;
					$media_views[] = 0;
					$other_views[] = 0;
				}
				$date = strtotime('+1 day', $date);
			}

			// Ensure data arrays are sorted by date
			array_multisort($dates, SORT_ASC, $views, $unique_visits, $bot_views, $posts_views, $pages_views, $custom_views, $media_views, $other_views);


			$chartData = array(
				'dates' => $dates,
				'views' => $views,
				'unique_visits' => $unique_visits,
				'bot_views' => $bot_views,
				'posts_views' => $posts_views,
				'pages_views' => $pages_views,
				'custom_views' => $custom_views,
				'media_views' => $media_views,
				'other_views' => $other_views,
				'retention_days' => $retention_days
			);

			wp_send_json_success($chartData);

		} catch (Exception $e) {
			wp_send_json_error('Failed to fetch stats: ' . $e->getMessage());
		}
	}

	

	

	
	
	
	/**
	 * Sets the TMIP dashboard widget position for appropriate users
	 */
	private function set_widget_position() {
		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			error_log('TMIP Debug: Setting dashboard widget position');
		}

		try {
			// Get users who should see the dashboard
			$users = $this->get_dashboard_users();

			foreach ($users as $user_id) {
				$this->set_user_widget_position($user_id);
			}

			// Store success in transient
			set_transient('tmip_admin_notice', [
				'type' => 'success',
				'message' => __('Dashboard widget has been positioned at the top for all users.', 'tracemyip-local-stats')
			], 45);

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Error: Failed to set widget position - ' . $e->getMessage());
			}
		}
	}

	/**
	 * Sets widget position for a specific user
	 */
	private function set_user_widget_position($user_id) {
		$widget_id = 'tmip_local_stats_dashboard';
		$meta_key = 'meta-box-order_dashboard';

		// Get current dashboard layout
		$user_meta = get_user_meta($user_id, $meta_key, true);

		// Initialize meta if empty
		if (empty($user_meta) || !is_array($user_meta)) {
			$user_meta = [
				'normal' => '',
				'side' => '',
				'column3' => '',
				'column4' => ''
			];
		}

		// Get existing widgets in normal context
		$normal_widgets = empty($user_meta['normal']) ? 
			array() : 
			array_filter(explode(',', $user_meta['normal']));

		// Remove widget if it exists anywhere
		foreach ($normal_widgets as $key => $widget) {
			if ($widget === $widget_id) {
				unset($normal_widgets[$key]);
			}
		}

		// Add our widget to the beginning
		array_unshift($normal_widgets, $widget_id);

		// Update the normal context
		$user_meta['normal'] = implode(',', array_filter($normal_widgets));

		// Save the updated order
		update_user_meta($user_id, $meta_key, $user_meta);

		// Handle widget collapsed state
		$this->set_widget_expanded_state($user_id, $widget_id);

		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			error_log("TMIP Debug: Widget positioned for user $user_id");
		}
	}


	/**
	 * Ensures the widget starts in expanded state
	 */
	private function set_widget_expanded_state($user_id, $widget_id) {
		$closed_meta_key = 'closedpostboxes_dashboard';
		$closed_meta = get_user_meta($user_id, $closed_meta_key, true);

		if (!is_array($closed_meta)) {
			$closed_meta = array();
		}

		// Remove widget from closed list to ensure it starts expanded
		if (in_array($widget_id, $closed_meta)) {
			$closed_meta = array_diff($closed_meta, array($widget_id));
			update_user_meta($user_id, $closed_meta_key, $closed_meta);

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log("TMIP Debug: Set widget expanded for user $user_id");
			}
		}
	}

		/**
	 * Reset widget positions for all users
	 */
	public static function reset_widget_positions() {
		try {
			$instance = new self();

			// Force first activation flag
			update_option('tmip_lc_first_activation', true);

			// Get all users who should see the dashboard
			$users = $instance->get_dashboard_users();

			foreach ($users as $user_id) {
				$instance->set_user_widget_position($user_id);
			}

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP: Widget positions reset for all users');
			}

			return true;

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Error: Failed to reset widget positions - ' . $e->getMessage());
			}
			return false;
		}
	}


	/**
	 * Gets array of user IDs who should see the dashboard widget
	 */
	private function get_dashboard_users() {
		$users = [];

		if (is_multisite()) {
			// Get all sites in the network
			$sites = get_sites([
				'fields' => 'ids',
				'number' => 0
			]);

			foreach ($sites as $site_id) {
				switch_to_blog($site_id);

				// Get users with dashboard access for this site
				$site_users = get_users([
					'role__in' => ['administrator', 'editor'],
					'fields' => 'ID'
				]);

				$users = array_merge($users, $site_users);

				restore_current_blog();
			}

			// Remove duplicates (users who have roles on multiple sites)
			$users = array_unique($users);

		} else {
			// Single site - get users with dashboard access
			$users = get_users([
				'role__in' => ['administrator', 'editor'],
				'fields' => 'ID'
			]);
		}

		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			error_log('TMIP Debug: Found ' . count($users) . ' dashboard users');
		}

		return $users;
	}

	private function render_share_inline_bar() {
		?>
		<div class="tmip-inline-share">
            <span class="tmip-share-text"><?php _e('Like this tool?', 'tracemyip-local-stats'); ?> &#10084;</span>
            <div class="tmip-share-buttons">
                <a href="https://www.facebook.com/sharer/sharer.php?u=https://wordpress.org/plugins/tracemyip-visitor-analytics-ip-tracking-control/" 
                   target="_blank" 
                   class="tmip-share-button tmip-facebook" 
                   title="Share on Facebook">
                    <span class="dashicons dashicons-facebook"></span>
                </a>
                <a href="https://twitter.com/intent/tweet?text=Check%20out%20this%20great%20WordPress%20visitor%20tracking%20plugin!&url=https://wordpress.org/plugins/tracemyip-visitor-analytics-ip-tracking-control/" 
                   target="_blank" 
                   class="tmip-share-button tmip-twitter" 
                   title="Share on X (Twitter)">
                    <span class="dashicons dashicons-twitter"></span>
                </a>
                <a href="mailto:?subject=Great%20WordPress%20Plugin&body=Check%20out%20this%20WordPress%20visitor%20tracking%20plugin:%20https://wordpress.org/plugins/tracemyip-visitor-analytics-ip-tracking-control/" 
                   class="tmip-share-button tmip-email" 
                   title="Share via Email">
                    <span class="dashicons dashicons-email"></span>
                </a>
                <button class="tmip-share-button tmip-copy tmip-copy-share-link" 
						title="<?php esc_attr_e('Copy Plugin Link', 'tracemyip-local-stats'); ?>">
					<span class="dashicons dashicons-admin-links"></span>
				</button>
            </div>
        </div>
	<?php
	}

	
    
    public function render_dashboard_widget() {
		
		$show_recent = get_option('tmip_lc_enable_recent_views', 1);
		$recent_minutes = get_option('tmip_lc_recent_views_minutes', 15); // Get user configured minutes
		$ajax_nonce = wp_create_nonce('tmip_local_stats_nonce');
		
		wp_localize_script('tmip-dashboard-stats', 'tmipLocalStats', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('tmip_dashboard_nonce'),
			'paginationNonce' => wp_create_nonce('tmip_pagination_nonce') // Add pagination nonce
		));

        ?>
		
		<div class="tmip-dashboard-widget">
			<div class="tmip-requests-notice-unfiltered">
				<?php 
		
					$arr = TMIP_Local_Stats::tmip_parent_serv_notice();
					$par_notice = $arr['par_notice'];
					$par_btn_name = $arr['par_btn_name'];
					$par_btn_url = $arr['par_btn_url'];
					$par_btn_trg = $arr['par_btn_trg'];

					if ($par_notice) : ?>
						<span class="tmip-notice-text">
							<?php echo $par_notice; ?>
						</span>
						<a href="<?php echo $par_btn_url; ?>" class="tmip-console-button" <?php echo $par_btn_trg; ?>>
							<?php echo $par_btn_name; ?>
							<span class="dashicons dashicons-external"></span>
						</a>
					<?php endif; ?>
				</div>
			<?php if ($show_recent): ?>
				<div class="tmip-stats-overview-section">
					<div class="tmip-section-header">
						<h3>
							<span class="tmip-header-title"><?php _e('Hits Summary', 'tracemyip-local-stats'); ?></span>
							<?php $this->render_share_inline_bar(); ?>
						</h3>
					</div>
					<div class="tmip-stats-grid">
						<div class="tmip-stats-row">
							<div class="tmip-stats-block">
								<h4><?php printf(__('Last %d Minutes', 'tracemyip-local-stats'), $recent_minutes); ?></h4>
								<?php echo $this->get_period_stats('recent', $recent_minutes); ?>
							</div>
							<div class="tmip-stats-block">
								<h4><?php _e('Today', 'tracemyip-local-stats'); ?></h4>
								<?php echo $this->get_period_stats('today'); ?>
							</div>
						</div>
						<div class="tmip-stats-row">
							<div class="tmip-stats-block">
								<h4><?php _e('Yesterday', 'tracemyip-local-stats'); ?></h4>
								<?php echo $this->get_period_stats('yesterday'); ?>
							</div>
							<div class="tmip-stats-block">
								<h4><?php _e('Last 7 Days', 'tracemyip-local-stats'); ?></h4>
								<?php echo $this->get_period_stats('7days'); ?>
							</div>
							<div class="tmip-stats-block">
								<h4><?php _e('This Month', 'tracemyip-local-stats'); ?></h4>
								<?php echo $this->get_period_stats('month'); ?>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
			
			
            <?php 
			if ( get_option( 'tmip_lc_enable_daily_chart' ) ) :
				$retention_days = get_option( 'tmip_lc_charts_retention', 30 );
				$chart_display_mode = get_option( 'tmip_lc_chart_display_mode', 'combined' ); // New setting
			?>
				<div class="tmip-chart-section">
					<h3><?php _e( 'Daily Page Hits (Last ' . $retention_days . ' Days)', 'tracemyip-local-stats' ); ?></h3>
					<div id="tmip-daily-chart"></div>
				</div>

				<script>
				jQuery(document).ready(function($) {
					$.post(ajaxurl, {
						action: 'tmip_get_daily_stats',
						security: '<?php echo wp_create_nonce("tmip_dashboard_nonce"); ?>',
					}, function(response) {
						if (response.success) {
							var chartMode = '<?php echo get_option('tmip_lc_chart_display_mode', 'separate'); ?>';

							// Define series with their specific colors
					var selectedSeries = <?php echo json_encode(get_option('tmip_lc_chart_series_display', ['posts', 'pages', 'custom'])); ?>;

					// Ensure selectedSeries is always an array
					if (!Array.isArray(selectedSeries)) {
						selectedSeries = ['posts', 'pages', 'custom'];
					}

					var seriesConfig = chartMode === 'combined' ? [
						{
							name: 'Total Hits',
							data: response.data.views,
							color: '#2E93fA'
						}
					] : [
						{
							name: 'Posts',
							data: response.data.posts_views,
							color: '#008FFB',
							enabled: selectedSeries.includes('posts')
						},
						{
							name: 'Pages',
							data: response.data.pages_views,
							color: '#00E396',
							enabled: selectedSeries.includes('pages')
						},
						{
							name: 'Custom Posts',
							data: response.data.custom_views,
							color: '#FEB019',
							enabled: selectedSeries.includes('custom')
						},
						{
							name: 'Media',
							data: response.data.media_views,
							color: '#FF4560',
							enabled: selectedSeries.includes('media')
						},
						{
							name: 'Other',
							data: response.data.other_views,
							color: '#775DD0',
							enabled: selectedSeries.includes('other')
						},
						{
							name: 'Bot Hits',
							data: response.data.bot_views,
							color: '#546E7A',
							enabled: selectedSeries.includes('bots')
						},
						{
							name: 'Unique Visits',
							data: response.data.unique_visits,
							color: '#26a69a',
							enabled: selectedSeries.includes('unique')
						}
					].filter(series => {
						// Ensure series is enabled and has valid data
						return series.enabled !== false && 
							   Array.isArray(series.data) && 
							   series.data.some(value => value !== null && value !== undefined);
					});


					var storageMethod = '<?php echo get_option('tmip_lc_storage_method', 'cookies'); ?>';
					if (storageMethod === 'cookieless') {
						// Filter out unique visits series
						seriesConfig = seriesConfig.filter(series => series.name !== 'Unique Visits');
					}

					var options = {
						chart: {
							type: 'area',
							height: 250,
							animations: {
								enabled: true,
								easing: 'easeinout',
								speed: 800
							},
							toolbar: {
								show: true
							}
						},
						series: seriesConfig.map(s => ({
							name: s.name,
							data: s.data
						})),
						colors: seriesConfig.map(s => s.color),
						stroke: {
							curve: 'smooth',
							width: 3,
							colors: seriesConfig.map(s => s.color)
						},
						fill: {
							type: 'solid',
							opacity: 0.15
						},
						markers: {
							size: 5,
							radius: 5,
							shape: 'circle',
							colors: seriesConfig.map(s => s.color),
							strokeColors: '#fff',
							strokeWidth: 2,
							hover: {
								size: 7,
								sizeOffset: 3
							},
							discrete: []
						},
						dataLabels: {
							enabled: false
						},
						xaxis: {
							categories: response.data.dates,
							type: 'datetime',
							labels: {
								rotate: -45,
								rotateAlways: false,
								format: 'dd MMM',
								style: {
									fontSize: '12px'
								}
							},
							tickAmount: Math.min(response.data.retention_days, 15)
						},
						yaxis: {
							labels: {
								formatter: function(val) {
									return Math.floor(val);
								}
							}
						},
						tooltip: {
							shared: true,
							intersect: false,
							theme: 'light',
							x: {
								format: 'dd MMM'
							},
							y: {
								formatter: function(val) {
									return Math.floor(val) + ' hits';
								}
							},
							marker: {
								show: true
							},
							style: {
								fontSize: '12px'
							},
							custom: function({ series, seriesIndex, dataPointIndex, w }) {
								const colors = seriesConfig.map(s => s.color);
								return '<div class="tmip-custom-tooltip">' +
									w.globals.seriesNames.map((name, i) => {
										if (series[i][dataPointIndex] !== undefined) {
											return `<div class="tooltip-series" style="color: ${colors[i]}">
												<span class="tooltip-marker" style="background: ${colors[i]}"></span>
												<span class="series-name">${name}:</span>
												<span class="series-value">${Math.floor(series[i][dataPointIndex])} hits</span>
											</div>`;
										}
										return '';
									}).join('') +
									'</div>';
							}
						},
						legend: {
							position: 'bottom',
							horizontalAlign: 'center',
							onItemClick: {
								toggleDataSeries: true
							},
							onItemHover: {
								highlightDataSeries: true
							},
							markers: {
								width: 8,
								height: 8,
								strokeWidth: 0,
								strokeColor: '#fff',
								radius: 12,
								offsetX: 0,
								offsetY: 0,
								shape: 'circle',
								fillColors: seriesConfig.map(s => s.color)
							},
							showForSingleSeries: true
						},
						grid: {
							borderColor: '#f1f1f1',
							padding: {
								bottom: 15
							}
						}
					};

					// Update the custom CSS section:
					$('<style>')
						.text(`
							${seriesConfig.map((s, i) => `
								.apexcharts-series[rel="${i}"] {
									stroke: ${s.color} !important;
								}
								.apexcharts-series[rel="${i}"] path.apexcharts-line {
									stroke: ${s.color} !important;
								}
								.apexcharts-series[rel="${i}"] path.apexcharts-area {
									fill: ${s.color} !important;
								}
								.apexcharts-legend-series[data\\:collapsedIndex="${i}"] .apexcharts-legend-marker {
									background-color: ${s.color} !important;
									border-color: ${s.color} !important;
								}
								.apexcharts-series[rel="${i}"] .apexcharts-marker {
									fill: ${s.color} !important;
								}
							`).join('\n')}

							.apexcharts-area {
								opacity: 0.15 !important;
							}
							.apexcharts-series.apexcharts-active .apexcharts-area {
								opacity: 0.3 !important;
							}

							/* Custom tooltip styling */
							.tmip-custom-tooltip {
								padding: 8px;
								background: #fff;
								border-radius: 4px;
								box-shadow: 0 2px 5px rgba(0,0,0,0.1);
							}
							.tmip-custom-tooltip .tooltip-series {
								display: flex;
								align-items: center;
								margin: 4px 0;
								font-size: 12px;
							}
							.tmip-custom-tooltip .tooltip-marker {
								width: 8px;
								height: 8px;
								border-radius: 50%;
								margin-right: 6px;
								display: inline-block;
							}
							.tmip-custom-tooltip .series-name {
								margin-right: 6px;
							}
							.tmip-custom-tooltip .series-value {
								font-weight: 600;
							}

							/* Ensure markers are circular */
							.apexcharts-marker {
								border-radius: 50% !important;
							}
						`)
						.appendTo('head');



						var chart = new ApexCharts(document.querySelector("#tmip-daily-chart"), options);
						chart.render();
					}
				});
			});
			
					
					
					
				</script>
			<?php endif; ?>

            <?php 
			// Posts section
			if (get_option('tmip_lc_enable_top_posts')) :
				$post_limit = get_option('tmip_lc_top_posts_count', TMIP_Local_Stats_Config::get_default('top_posts_count'));
				$post_results = $this->render_top_content('post', $post_limit, 1); // Pass page=1
				if ($post_results && strpos($post_results, 'No views recorded yet') === false) : ?>
					<div class="tmip-top-posts-section">
						<h3><?php printf(__('Top %d Posts', 'tracemyip-local-stats'), $post_limit); ?></h3>
						<?php echo $post_results; ?>
					</div>
				<?php endif;
			endif;

			// Pages section 
			if (get_option('tmip_lc_enable_top_pages')) :
				$page_limit = get_option('tmip_lc_top_pages_count',  TMIP_Local_Stats_Config::get_default('top_pages_count'));
				$page_results = $this->render_top_content('page', $page_limit, 1); // Pass page=1
				if ($page_results && strpos($page_results, 'No views recorded yet') === false) : ?>
					<div class="tmip-top-pages-section">
						<h3><?php printf(__('Top %d Pages', 'tracemyip-local-stats'), $page_limit); ?></h3>
						<?php echo $page_results; ?>
					</div>
				<?php endif;
			endif;

			// Custom post types
			$cpts = get_post_types(['public' => true, '_builtin' => false]);
			foreach ($cpts as $cpt) {
				if (get_option('tmip_lc_enable_cpt_dashboard_'.$cpt)) {
					// Get CPT specific settings template
					$cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);

					// Get the default value from CPT settings
					$default_limit = isset($cpt_settings['top_count_' . $cpt]['default']) 
						? $cpt_settings['top_count_' . $cpt]['default'] 
						: 5; // Fallback default

					// Get configured limit with proper default
					$limit = get_option('tmip_lc_top_count_'.$cpt, $default_limit);

					$cpt_obj = get_post_type_object($cpt);
					$results = $this->render_top_content($cpt, $limit, 1);

					if ($results && strpos($results, 'No views recorded yet') === false) {
						echo '<div class="tmip-top-custom-post-types-section">';
						echo '<h3>'.sprintf(__('Top %d %s', 'tracemyip-local-stats'), 
							$limit, $cpt_obj->labels->name).'</h3>';
						echo $results;
						echo '</div>';
					}
				}
			}
		
		
			// Most Active IPs
			if (get_option('tmip_lc_enable_active_ips', 1)) {
				?>
				<div class="tmip-active-ips-section">
					<?php echo $this->render_active_ips(1); // Pass initial page number ?>
				</div>
				<?php 
			}
		
			// Settings links pane
			?>
			<div class="tmip-widget-footer">
				<div class="tmip-settings-links">
					<a href="<?php echo esc_url(admin_url('admin.php?page=tmip_local_stats')); ?>" class="tmip-settings-link">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php _e(_tmipLg('<b>UnFiltered Stats</b> Settings'), 'tracemyip-local-stats'); ?>
					</a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=tmip_lnk_wp_settings')); ?>" class="tmip-settings-link tmip-settings-link-right">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php _e(_tmipLg('<b>Visitor Tracker</b> Settings'), 'tracemyip-local-stats'); ?>
					</a>
				</div>
			</div>
        </div>

		<!-- Floating copy tooltip -->
		<div id="tmip-copy-tooltip" class="tmip-copy-tooltip">
			<?php _e('Copied!', 'tracemyip-local-stats'); ?>
		</div>
        <?php
    }
	

	public function render_active_ips_limit_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-active-ips-limit-settings">
			<input type="number" 
				   name="tmip_lc_active_ips_limit" 
				   class="tmip-select-dropdown"
				   value="<?php echo esc_attr(get_option('tmip_lc_active_ips_limit', 
					   $settings['active_ips_limit']['default'])); ?>" 
				   min="<?php echo $settings['active_ips_limit']['min']; ?>" 
				   max="<?php echo $settings['active_ips_limit']['max']; ?>" />
			<?php _e('IPs', 'tracemyip-local-stats'); ?>
			<p class="tmip-note_small">
				<?php echo esc_html($settings['active_ips_limit']['description']); ?>
			</p>
		</div>
		<?php
	}

	private function render_active_ips($page = 1) {
		global $wpdb;

		 $ip_lookup_service = get_option(
            'tmip_lc_ip_lookup_service', 
            TMIP_Local_Stats_Config::get_default('ip_lookup_service')
        );

        $limit = (int)get_option('tmip_lc_active_ips_limit', 
            TMIP_Local_Stats_Config::get_default('active_ips_limit'));

        $timeframe = get_option('tmip_lc_active_ips_timeframe', 'today');
        $current_time = TMIP_Local_Stats::get_current_time_sql();

        // Calculate date range based on timeframe
        switch($timeframe) {
			case '5min':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -5 minutes'));
				$title = sprintf(
					__('%d Most Active IPs (Last 5 Minutes)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case '15min':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -15 minutes'));
				$title = sprintf(
					__('%d Most Active IPs (Last 15 Minutes)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case '60min':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -60 minutes'));
				$title = sprintf(
					__('%d Most Active IPs (Last Hour)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case 'today':
				$start_date = date('Y-m-d 00:00:00', strtotime($current_time));
				$title = sprintf(
					__('%d Most Active IPs Today', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case '24h':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -24 hours'));
				$title = sprintf(
					__('%d Most Active IPs (Last 24 Hours)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case '72h':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -72 hours'));
				$title = sprintf(
					__('%d Most Active IPs (Last 72 Hours)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case 'week':
				// Changed to include last 7 days of data
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -7 days'));
				$title = sprintf(
					__('%d Most Active IPs This Week', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case '7days':
				$start_date = date('Y-m-d H:i:s', strtotime($current_time . ' -7 days'));
				$title = sprintf(
					__('%d Most Active IPs (Last 7 Days)', 'tracemyip-local-stats'),
					$limit
				);
				break;
			case 'month':
				$start_date = date('Y-m-01 00:00:00', strtotime($current_time));
				$title = sprintf(
					__('%d Most Active IPs This Month', 'tracemyip-local-stats'),
					$limit
				);
				break;
			default:
				$start_date = date('Y-m-d 00:00:00', strtotime($current_time));
				$title = sprintf(
					__('%d Most Active IPs Today', 'tracemyip-local-stats'),
					$limit
				);
		}
		

        // Get total count first
        $total_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_ip) 
             FROM {$wpdb->prefix}tmip_lc_views 
             WHERE view_date BETWEEN %s AND %s",
            $start_date,
            $current_time
        ));

        // Calculate offset for pagination
        $offset = ($page - 1) * $limit;

        // Modified query to correctly count hits within timeframe
        $results = $wpdb->get_results($wpdb->prepare(
			"SELECT 
				user_ip,
				COUNT(*) as requests,
				MAX(view_date) as last_activity,
				MAX(is_bot) as is_bot  /* Changed from BOOL_OR to MAX */
			 FROM {$wpdb->prefix}tmip_lc_views 
			 WHERE view_date BETWEEN %s AND %s
			 GROUP BY user_ip 
			 ORDER BY requests DESC, last_activity DESC 
			 LIMIT %d OFFSET %d",
			$start_date,
			$current_time,
			$limit,
			$offset
		));
		
		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
            error_log("TMIP Debug - Active IPs Query: " . $wpdb->last_query);
            error_log("TMIP Debug - Timeframe: {$timeframe}, Start: {$start_date}, End: {$current_time}");
            error_log("TMIP Debug - Results: " . print_r($results, true));
        }
		
		
		$output = '<div class="tmip-top-content-wrapper" data-content-type="active-ips" data-limit="' . esc_attr($limit) . '" data-page="' . esc_attr($page) . '">';
    
		// Title inside the wrapper
		$output .= '<h3>' . esc_html($title) . '</h3>';
		
		// Column names line
		$output .= '<div class="tmip-column-names">' . 
			__('Lookup | Copy | IP Address | Hits', 'tracemyip-local-stats') .
			'<span class="tmip-column-last-activity">' . __('Last Activity Date/Time', 'tracemyip-local-stats') . '</span>' .
		'</div>';

		if (empty($results)) {
			$output .= '<p>' . __('No activity recorded.', 'tracemyip-local-stats') . '</p>';
			$output .= '</div>';
			return $output;
		}

		$output .= '<ul class="tmip-active-ips-list">';

		foreach ($results as $row) {
    $ip_lookup_service = get_option(
        'tmip_lc_ip_lookup_service', 
        TMIP_Local_Stats_Config::get_default('ip_lookup_service')
    );
    
    $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
    $ip_lookup_suffix = $settings['ip_lookup_service']['lookup_suffix'];
    
    $ip_link = $ip_lookup_service ? esc_url($ip_lookup_service . $row->user_ip).$ip_lookup_suffix : '';
    $time_ago = $this->format_time_ago(new DateTime($row->last_activity));

    $output .= sprintf(
        '<li%s>
            <div class="tmip-ip-actions">
                %s
                <button class="tmip-ip-action tmip-copy-ip" 
                        data-ip="%s" 
                        title="%s">
                    <span class="dashicons dashicons-admin-page"></span>
                </button>
            </div>
            <div class="tmip-ip-container">
                <span class="tmip-ip-value" data-ip="%s" title="%s">%s</span>
                %s
            </div>
            <span class="tmip-ip-requests"><b>%s</b> %s</span>
            <span class="tmip-ip-last-seen">
                <span class="tmip-time-ago">%s</span>
                <span class="tmip-exact-time">%s</span>
            </span>
        </li>',
        $row->is_bot ? ' class="is-bot"' : '',
        $ip_lookup_service ? sprintf(
            '<a href="%s" class="tmip-ip-action tmip-lookup-ip" target="_blank" title="%s">
                <span class="dashicons dashicons-search"></span>
            </a>',
            $ip_link,
            esc_attr__('Lookup IP', 'tracemyip-local-stats')
        ) : '',
        esc_attr($row->user_ip),
        esc_attr__('Copy IP', 'tracemyip-local-stats'),
        esc_attr($row->user_ip),
        esc_attr($row->user_ip),
        esc_html($row->user_ip),
        $row->is_bot ? ' <span class="tmip-bot-indicator" title="' . esc_attr__('Bot/Crawler', 'tracemyip-local-stats') . '">
            <span class="dashicons dashicons-admin-site"></span> (bot)
        </span>' : '',
        number_format($row->requests),
        __('hits', 'tracemyip-local-stats'),
        $time_ago,
        date('Y-m-d H:i:s', strtotime($row->last_activity))
    );
}
		

		$output .= '</ul>';

		// Add pagination if there are more items
		if ($total_count > $limit) {
			$total_pages = ceil($total_count / $limit);
			$output .= '<div class="tmip-pagination">';

			// Add page counter
			$output .= '<div class="tmip-page-counter">';
			$output .= sprintf(
				__('Page %1$d of %2$d', 'tracemyip-local-stats'),
				$page,
				$total_pages
			);
			$output .= '</div>';

			// Previous link
			if ($page > 1) {
				$output .= '<a href="#" class="tmip-page-link prev" data-page="' . ($page - 1) . '">' 
						. __('← Previous', 'tracemyip-local-stats') . '</a>';
			} else {
				$output .= '<span class="tmip-page-link disabled prev">' 
						. __('← Previous', 'tracemyip-local-stats') . '</span>';
			}

			// Next link
			if ($page < $total_pages) {
				$output .= '<a href="#" class="tmip-page-link next" data-page="' . ($page + 1) . '">' 
						. __('Next →', 'tracemyip-local-stats') . '</a>';
			} else {
				$output .= '<span class="tmip-page-link disabled next">' 
						. __('Next →', 'tracemyip-local-stats') . '</span>';
			}

			$output .= '</div>';
		}

		$output .= '</div>';
		return $output;
}
	
	

	private function format_time_ago($datetime) {
		// Get current time in same timezone as stored data
		$now = new DateTime(TMIP_Local_Stats::get_current_time_sql());

		// Calculate difference - no timezone conversion needed
		$interval = $now->diff($datetime);

		if ($interval->y > 0) {
			return sprintf(_n('%d year ago', '%d years ago', $interval->y, 'tracemyip-local-stats'), $interval->y);
		}

		if ($interval->m > 0) {
			return sprintf(_n('%d month ago', '%d months ago', $interval->m, 'tracemyip-local-stats'), $interval->m);
		}

		if ($interval->d > 0) {
			$hours = $interval->h;
			if ($hours > 0) {
				return sprintf(
					__('%d day %d hours ago', 'tracemyip-local-stats'), 
					$interval->d, 
					$hours
				);
			}
			return sprintf(_n('%d day ago', '%d days ago', $interval->d, 'tracemyip-local-stats'), $interval->d);
		}

		if ($interval->h > 0) {
			$minutes = $interval->i;
			if ($minutes > 0) {
				return sprintf(
					__('%d hours %d minutes ago', 'tracemyip-local-stats'), 
					$interval->h, 
					$minutes
				);
			}
			return sprintf(_n('%d hour ago', '%d hours ago', $interval->h, 'tracemyip-local-stats'), $interval->h);
		}

		if ($interval->i > 0) {
			$seconds = $interval->s;
			if ($seconds > 0) {
				return sprintf(
					__('%d minutes %d seconds ago', 'tracemyip-local-stats'), 
					$interval->i, 
					$seconds
				);
			}
			return sprintf(_n('%d minute ago', '%d minutes ago', $interval->i, 'tracemyip-local-stats'), $interval->i);
		}

		return sprintf(_n('%d second ago', '%d seconds ago', $interval->s, 'tracemyip-local-stats'), $interval->s);
	}
	
	


	private function get_period_stats($period, $minutes = null) {
		global $wpdb;

		try {
			// Get current time in user timezone
			$current_time = TMIP_Local_Stats::get_current_time_sql();

			switch($period) {
				case 'recent':
					// Calculate start time directly from current time
					$start_time = date('Y-m-d H:i:s', strtotime($current_time . " -{$minutes} minutes"));
					$end_time = $current_time;
					break;

				case 'today':
					// Get today's date from current time
					$today = date('Y-m-d', strtotime($current_time));
					$start_time = $today . ' 00:00:00';
					$end_time = $current_time;
					break;

				case 'yesterday':
					// Get yesterday's date from current time
					$yesterday = date('Y-m-d', strtotime($current_time . ' -1 day'));
					$start_time = $yesterday . ' 00:00:00';
					$end_time = $yesterday . ' 23:59:59';
					break;

				case '7days':
					// Calculate 7 days ago from current time
					$start_time = date('Y-m-d H:i:s', strtotime($current_time . ' -7 days'));
					$end_time = $current_time;
					break;

				case 'month':
					// Get first day of current month from current time
					$start_time = date('Y-m-01 00:00:00', strtotime($current_time));
					$end_time = $current_time;
					break;

				default:
					throw new Exception('Invalid period specified');
			}

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log(sprintf(
					'TMIP Debug - Period: %s, Query range: %s to %s',
					$period,
					$start_time,
					$end_time
				));
			}

			$results = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(*) as requests, 
						COUNT(DISTINCT user_ip) as unique_ips 
				 FROM {$wpdb->prefix}tmip_lc_views 
				 WHERE view_date BETWEEN %s AND %s",
				$start_time,
				$end_time
			));

			if ($wpdb->last_error) {
				throw new Exception('Database query failed: ' . $wpdb->last_error);
			}

			$is_recent = ($period === 'recent' && $results->requests > 0);

			$stats_order = get_option('tmip_lc_dashboard_stats_order', 'requests_first');
			if ($stats_order === 'requests_first') {
				return sprintf(
					'<div class="tmip-stats-count">
						<div class="tmip-stats-total%s">
							<strong>%s</strong>
							<span class="tmip-stat-label">%s</span>
						</div>
						<div class="tmip-stats-ip-count">
							<strong>%s</strong>
							<span class="tmip-stat-label">%s</span>
						</div>
					</div>',
					$is_recent ? ' tmip-stats-active' : '',
					number_format($results->requests),
					__('hits', 'tracemyip-local-stats'),
					number_format($results->unique_ips),
					_n('IP', 'IPs', $results->unique_ips, 'tracemyip-local-stats')
				);
			} else {
				return sprintf(
					'<div class="tmip-stats-count">
						<div class="tmip-stats-total%s">
							<strong>%s</strong>
							<span class="tmip-stat-label">%s</span>
						</div>
						<div class="tmip-stats-ip-count">
							<strong>%s</strong>
							<span class="tmip-stat-label">%s</span>
						</div>
					</div>',
					$is_recent ? ' tmip-stats-active' : '',
					number_format($results->unique_ips),
					_n('IP', 'IPs', $results->unique_ips, 'tracemyip-local-stats'),
					number_format($results->requests),
					__('hits', 'tracemyip-local-stats')
				);
			}

		} catch (Exception $e) {
			error_log('TMIP Stats Error: ' . $e->getMessage());
        
			// Error case
			if (get_option('tmip_lc_dashboard_stats_order', 'requests_first') === 'requests_first') {
				return sprintf(
					'<div class="tmip-stats-count">
						<div class="tmip-stats-total">0</div>
						<div class="tmip-stats-ip-count"><strong>0</strong> %s</div>
					</div>',
					__('IPs', 'tracemyip-local-stats')
				);
			} else {
				return sprintf(
					'<div class="tmip-stats-count">
						<div class="tmip-stats-total"><strong>0</strong> %s</div>
						<div class="tmip-stats-ip-count">0</div>
					</div>',
					__('IPs', 'tracemyip-local-stats')
				);
			}
		}
	}

	
	public function get_paginated_content() {
		// Verify nonce
		if (!check_ajax_referer('tmip_pagination_nonce', 'security', false)) {
			wp_send_json_error(array(
				'message' => 'Invalid security token'
			));
			return;
		}

		if (!current_user_can('read')) {
			wp_send_json_error(array(
				'message' => 'Insufficient permissions'
			));
			return;
		}

		try {
			$content_type = sanitize_text_field($_POST['content_type']);
			$page = absint($_POST['page']);
			$limit = absint($_POST['limit']);

			if ($content_type === 'active-ips') {
				$content = $this->render_active_ips($page);
			} else {
				$post_type = sanitize_text_field($_POST['post_type']);
				$content = $this->render_top_content($post_type, $limit, $page);
			}

			wp_send_json_success(array(
				'content' => $content
			));

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Error: ' . $e->getMessage());
			}
			wp_send_json_error(array(
				'message' => 'Failed to fetch paginated content'
			));
		}
	}


	
	
	private function render_top_content($post_type, $limit = 10, $page = 1) {
		global $wpdb;

		if (!post_type_exists($post_type)) {
			error_log("TMIP: Invalid post type requested [0524250944]: " . $post_type);
			return '<p>' . __('Invalid content type specified.', 'tracemyip-local-stats') . '</p>';
		}

		// Get total count BEFORE pagination
		$total_count = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) 
			 FROM {$wpdb->posts} p 
			 INNER JOIN {$wpdb->prefix}tmip_lc_post_stats ps ON p.ID = ps.post_id 
			 WHERE p.post_type = %s 
			 AND p.post_status = 'publish' 
			 AND ps.views_count > 0",
			$post_type
		));

		// Debug log the counts
		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			error_log("TMIP Debug - Post type: {$post_type}, Total count: {$total_count}, Limit: {$limit}, Page: {$page}");
		}

		// Calculate offset
		$offset = ($page - 1) * $limit;

		// Get paginated results
		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT p.ID, p.post_title, SUM(ps.views_count) as view_count 
			 FROM {$wpdb->posts} p 
			 INNER JOIN {$wpdb->prefix}tmip_lc_post_stats ps ON p.ID = ps.post_id 
			 WHERE p.post_type = %s 
			 AND p.post_status = 'publish' 
			 GROUP BY p.ID, p.post_title 
			 HAVING view_count > 0 
			 ORDER BY view_count DESC 
			 LIMIT %d OFFSET %d",
			$post_type,
			$limit,
			$offset
		));

		if (empty($results)) {
			return '<p>' . __('No views recorded yet.', 'tracemyip-local-stats') . '</p>';
		}

		$output = '<div class="tmip-top-content-wrapper" data-post-type="' . esc_attr($post_type) . '" data-limit="' . esc_attr($limit) . '" data-page="' . esc_attr($page) . '">';
		$output .= '<ul class="tmip-top-content-list">';

		foreach ($results as $row) {
			$edit_link = get_edit_post_link($row->ID);
			$view_link = get_permalink($row->ID);

			$output .= '<li>';
			$output .= '<span class="tmip-view-count">' . number_format($row->view_count) . '</span>';
			$output .= '<a href="' . esc_url($view_link) . '" target="_blank" title="' . esc_attr($row->post_title) . '">' 
					. esc_html($row->post_title) . '</a>';

			if ($edit_link) {
				$output .= '<a href="' . esc_url($edit_link) . '" class="tmip-edit-link" title="' 
						. esc_attr__('Edit', 'tracemyip-local-stats') . '" target="_blank">';
				$output .= '<span class="dashicons dashicons-edit"></span>';
				$output .= '</a>';
			}

			$output .= '</li>';
		}

		$output .= '</ul>';

		// Debug log pagination info
		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			error_log("TMIP Debug - Total: {$total_count}, Limit: {$limit}, Pages: " . ceil($total_count / $limit));
		}

		// Add pagination if there are more items
		if ($total_count > $limit) {
			$total_pages = ceil($total_count / $limit);
			$output .= '<div class="tmip-pagination">';

			// Add page counter
			$output .= '<div class="tmip-page-counter">';
			$output .= sprintf(
				__('Page %1$d of %2$d', 'tracemyip-local-stats'),
				$page,
				$total_pages
			);
			$output .= '</div>';

			// Previous link
			if ($page > 1) {
				$output .= '<a href="#" class="tmip-page-link prev" data-page="' . ($page - 1) . '">' 
						. __('← Previous', 'tracemyip-local-stats') . '</a>';
			} else {
				$output .= '<span class="tmip-page-link disabled prev">' 
						. __('← Previous', 'tracemyip-local-stats') . '</span>';
			}

			// Next link
			if ($page < $total_pages) {
				$output .= '<a href="#" class="tmip-page-link next" data-page="' . ($page + 1) . '">' 
						. __('Next →', 'tracemyip-local-stats') . '</a>';
			} else {
				$output .= '<span class="tmip-page-link disabled next">' 
						. __('Next →', 'tracemyip-local-stats') . '</span>';
			}

			$output .= '</div>';
		}

		$output .= '</div>';
		return $output;
	}



	
/*  Deprecated method reading top pages from IP pool
    private function render_top_content_use_views_table($post_type, $limit = 10) {
		global $wpdb;

		if (!post_type_exists($post_type)) {
			error_log("TMIP: Invalid post type requested [0524250944]: " . $post_type);
			return '<p>' . __('Invalid content type specified.', 'tracemyip-local-stats') . '</p>';
		}

		$query = $wpdb->prepare(
			"SELECT p.ID, p.post_title, COUNT(v.id) as view_count 
			 FROM {$wpdb->posts} p 
			 LEFT JOIN {$wpdb->prefix}tmip_lc_views v ON p.ID = v.post_id 
			 WHERE p.post_type = %s 
			 AND p.post_status = 'publish' 
			 GROUP BY p.ID, p.post_title 
			 HAVING view_count > 0 
			 ORDER BY view_count DESC 
			 LIMIT %d",
			$post_type,
			$limit
		);

		$results = $wpdb->get_results($query);

		if (empty($results)) {
			return '<p>' . __('No views recorded yet.', 'tracemyip-local-stats') . '</p>';
		}

		$output = '<ul class="tmip-top-content-list">';

		foreach ($results as $row) {
			$edit_link = get_edit_post_link($row->ID);
			$view_link = get_permalink($row->ID);

			$output .= '<li>';
			$output .= '<span class="tmip-view-count">' . number_format($row->view_count) . '</span>';
			$output .= '<a href="' . esc_url($view_link) . '" target="_blank" title="' . esc_attr($row->post_title) . '">' 
					. esc_html($row->post_title) . '</a>';

			if ($edit_link) {
				$output .= '<a href="' . esc_url($edit_link) . '" class="tmip-edit-link" title="' 
						. esc_attr__('Edit', 'tracemyip-local-stats') . '">';
				$output .= '<span class="dashicons dashicons-edit"></span>';
				$output .= '</a>';
			}

			$output .= '</li>';
		}

		$output .= '</ul>';

		return $output;
	}*/

	


}

TMIP_Local_Stats_Dashboard::init();
// add_action('init', array('TMIP_Local_Stats_Dashboard', 'init'));
