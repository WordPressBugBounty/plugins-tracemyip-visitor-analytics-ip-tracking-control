<?php
/* TraceMyIP > UnFiltered Stats */
defined('ABSPATH') || exit;

class TMIP_Local_Stats_Settings {
    
    private static $instance;
    
    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        
        // Add admin post handler with public method
        add_action('admin_post_handle_maintenance_action', array($this, 'handle_maintenance_actions'));
		
		// AJAX handlers for getting current IP
        add_action('wp_ajax_tmip_get_current_ip', array($this, 'ajax_get_current_ip'));
        add_action('wp_ajax_nopriv_tmip_get_current_ip', array($this, 'ajax_get_current_ip'));
    }
	
	/**
     * AJAX handler for getting current IP
     */
    public function ajax_get_current_ip() {
        check_ajax_referer('tmip_local_stats_nonce', 'security');
        
        $ip = $this->get_client_ip();
        
        if ($ip) {
            wp_send_json_success(array('ip' => $ip));
        } else {
            wp_send_json_error(array('message' => 'Could not determine IP address'));
        }
    }
    /* Get client IP address with fallbacks */
    private function get_client_ip() {
        $ip = '';
        
        // Check various server variables
        $server_vars = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($server_vars as $var) {
            if (isset($_SERVER[$var])) {
                $ip = $_SERVER[$var];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1'; // Fallback to localhost if no IP found
    }
	
	
	public function show_admin_notices() {
		static $notices_shown = false;

		// Prevent multiple renderings
		if ($notices_shown) {
			return;
		}

		// Only show notices on plugin pages
		$current_screen = get_current_screen();
		$plugin_pages = array(
			'settings_page_tmip_local_stats',
		);

		// Return early if not on a plugin page
		if (!in_array($current_screen->id, $plugin_pages)) {
			return;
		}

		// Show notice when unfiltered stats are disabled
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) {
			$sp='&nbsp;';
			?>
			<div class="notice notice-warning is-dismissible" style="background-color:rgba(255,158,158,1.00);padding:25px!important;border:2px dashed #000;">
				<p>
					<strong><?php _e('TraceMyIP UnFiltered Stats are currently disabled.'.$sp, 'tracemyip-local-stats'); ?></strong>
					<?php _e('No unfiltered stats are being collected. You can enable them again in the'.$sp, 'tracemyip-local-stats'); ?>
					<a href="<?php echo admin_url('admin.php?page=tmip_local_stats&tab=general'); ?>">
						<?php _e('settings', 'tracemyip-local-stats'); ?>
					</a>.
				</p>
			</div>
			<?php
		}       

		// Show transient notices
		$notice = get_transient('tmip_admin_notice');
		if ($notice) {
			$class = ($notice['type'] === 'success') ? 'notice-success tmip-notice-success' : 'notice-error tmip-notice-error';
			?>
			<div class="notice <?php echo $class; ?> is-dismissible tmip-admin-notice">
				<p><strong><?php echo esc_html($notice['message']); ?></strong></p>
			</div>
			<?php
			delete_transient('tmip_admin_notice');
		}

		// Also show settings errors if any
		settings_errors('tmip_maintenance');

		$notices_shown = true;
	}

	

	public function render_enable_unfiltered_stats_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
		
		$disabled_style='style="width: fit-content;padding:10px;font-size:1.1em;color:black;';
		
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) {
			$disabled_style .='background-color:rgba(255,125,116,1.00);border: 4px dashed #333;';
		} else {
			$disabled_style .='background-color:rgba(229,255,238,1.00);border: 1px dashed #333;';
		}
		$disabled_style .='"'
		?>
		<div class="tmip-enable-unfiltered-stats" <?php echo $disabled_style ?>>
			<label>
				<input type="checkbox"
					   name="tmip_lc_enable_unfiltered_stats" 
					   value="1" 
					   <?php checked(get_option('tmip_lc_enable_unfiltered_stats', $settings['enable_unfiltered_stats']['default']), 1); ?>
					   <?php disabled(!$php_version_ok); ?> />
				<?php _e('Enable UnFiltered Stats', 'tracemyip-local-stats'); ?>
			</label>
			<p class="tmip-note_small">
				<?php _e('When disabled, no unfiltered stats will be collected or displayed.', 'tracemyip-local-stats'); ?>
			</p>

			<?php if (!$php_version_ok): ?>
				<div class="tmip-php-version-warning" style="margin-top: 8px;">
					<p class="tmip-note_small">
						<?php printf(
							__('Your PHP version (%s) is not compatible. TraceMyIP UnFiltered Stats requires PHP %s or higher to function properly. Settings are disabled until PHP is upgraded.', 'tracemyip-local-stats'),
							PHP_VERSION,
							TMIP_Local_Stats_Config::MIN_PHP_VERSION
						); ?>
					</p>
				</div>

				<script>
				jQuery(document).ready(function($) {
					// Disable all form inputs
					$('.tmip-settings-form input, .tmip-settings-form select, .tmip-settings-form textarea').prop('disabled', true);

					// Disable submit button
					$('.tmip-settings-form input[type="submit"]').prop('disabled', true)
						.css('opacity', '0.5')
						.attr('title', '<?php _e('Settings disabled - PHP version incompatible', 'tracemyip-local-stats'); ?>');

					// Prevent form submission
					$('.tmip-settings-form').on('submit', function(e) {
						e.preventDefault();
						return false;
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

    public function render_timezone_field() {
        $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
        $current_tz = get_option('tmip_lc_timezone_setting', $settings['timezone_setting']['default']);
        ?>
        <div class="tmip-timezone-settings">
            <select name="tmip_lc_timezone_setting" id="tmip_lc_timezone_setting" class="tmip-select-dropdown">
                <?php foreach ($settings['timezone_setting']['options'] as $option): ?>
                    <option value="<?php echo esc_attr($option); ?>" 
                            <?php selected($current_tz, $option); ?>>
                        <?php echo esc_html($this->get_timezone_label($option)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    private function get_timezone_label($option) {
        switch($option) {
            case 'wordpress':
                return __('Use WordPress Timezone', 'tracemyip-local-stats');
            case 'utc':
                return __('UTC', 'tracemyip-local-stats');
            case 'custom':
                return __('Custom Timezone', 'tracemyip-local-stats');
            default:
                return $option;
        }
    }

    public function render_custom_timezone_field() {
        $current = get_option('tmip_lc_custom_timezone', '');
        ?>
        <select name="tmip_lc_custom_timezone" id="tmip_lc_custom_timezone" class="tmip-select-dropdown">
            <?php
            $timezones = DateTimeZone::listIdentifiers();
            foreach ($timezones as $timezone) {
                echo '<option value="' . esc_attr($timezone) . '" ' . 
                     selected($current, $timezone, false) . '>' . 
                     esc_html($timezone) . '</option>';
            }
            ?>
        </select>
        <p class="tmip-note_small">
            <?php _e('Only applies if "Custom Timezone" is selected above', 'tracemyip-local-stats'); ?>
        </p>
        <?php
    }
	
	public function render_ip_lookup_service_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-ip-lookup-settings">
			<input type="text" 
				   name="tmip_lc_ip_lookup_service" 
				   class="regular-text"
				   value="<?php echo esc_attr(get_option('tmip_lc_ip_lookup_service', $settings['ip_lookup_service']['default'])); ?>" />
			<p class="tmip-note_small">
				<?php echo esc_html($settings['ip_lookup_service']['description']); ?><br>
<div><span class="tmip-settings-default-value"><?php _e('Default: '.$settings['ip_lookup_service']['default'], 'tracemyip-local-stats'); ?></span></div>
			</p>
		</div>
		<?php
	}
	
	public function add_settings_page() {
		// TraceMyIP menu: UnFiltered Stats
		add_submenu_page(
			'tmip_admpanel_menu',
			__(tmipu_uf_tmip_unf_stats, 'tracemyip-local-stats'),
			__(tmipu_uf_stats_settings, 'tracemyip-local-stats'),
			'manage_options',
			'tmip_local_stats',
			array($this, 'render_settings_page')
		);
		
		// Admin Settings menu: UnFiltered Stats 
		add_options_page(
			__($v=(tmipu_name.' '.tmipu_uf_stats), 'tracemyip-local-stats'),
			'<span style="font-weight:600;">'.tmipu_name.'</span> '.tmipu_uf_stats.'',
			//__($v, 'tracemyip-local-stats'),
			'manage_options',
			'tmip_local_stats',
			array($this, 'render_settings_page')
		);
	}


	# Render SETTINGS page
	public function render_settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
		?>
		<div class="wrap">
			<div class="tmip-loader-overlay">
				<div class="tmip-loader"></div>
			</div>

			<h1>
				<img src="<?php echo TMIP_LOCAL_STATS_URL; ?>assets/images/TraceMyIP-Logo_40x40.png" 
					 alt="TraceMyIP Logo" style="vertical-align: middle; margin-right: 10px;height:40px;width:40px;"/>
				<?php _e(tmipu_uf_stats_settings, 'tracemyip-local-stats'); ?>
			</h1>

			<?php 
			// Only show settings errors, skip duplicate notices
			if (isset($_GET['settings-updated'])) {
				// Remove default WordPress notice
				remove_action('all_admin_notices', 'settings_errors');
			}
			settings_errors('tmip_settings'); 
			?>
			

			<h2 class="nav-tab-wrapper">
				<a href="?page=tmip_local_stats&tab=general" 
				   data-tab="general"
				   class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
				   <?php _e('General', 'tracemyip-local-stats'); ?>
				</a>
				<a href="?page=tmip_local_stats&tab=dashboard" 
				   data-tab="dashboard"
				   class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">
				   <?php _e('Dashboard', 'tracemyip-local-stats'); ?>
				</a>
				<a href="?page=tmip_local_stats&tab=log-filtering" 
				   data-tab="log-filtering"
				   class="nav-tab <?php echo $active_tab == 'log-filtering' ? 'nav-tab-active' : ''; ?>">
				   <?php _e('Log Filtering', 'tracemyip-local-stats'); ?>
				</a>
				<a href="?page=tmip_local_stats&tab=maintenance" 
				   data-tab="maintenance"
				   class="nav-tab <?php echo $active_tab == 'maintenance' ? 'nav-tab-active' : ''; ?>">
				   <?php _e('Maintenance', 'tracemyip-local-stats'); ?>
				</a>
			</h2>

			<div class="tmip-tab-content active <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
				<?php
				switch ($active_tab) {
					case 'general':
						$this->render_general_settings();
						break;
					case 'dashboard':
						$this->render_dashboard_settings();
						break;
					case 'log-filtering':
						$this->render_log_filtering_settings();
						break;
					case 'maintenance':
						$this->render_maintenance_settings();
						break;
				}
				?>
			</div>

			<?php if (!$php_version_ok): ?>
			<script>
			jQuery(document).ready(function($) {
				// Disable all form inputs across all tabs
				$('.tmip-settings-form input, .tmip-settings-form select, .tmip-settings-form textarea').prop('disabled', true);

				// Disable all submit buttons
				$('.tmip-settings-form input[type="submit"]').prop('disabled', true)
					.css('opacity', '0.5')
					.attr('title', '<?php _e('Settings disabled - PHP version incompatible', 'tracemyip-local-stats'); ?>');

				// Disable maintenance buttons
				$('.tmip-maintenance-form button').prop('disabled', true)
					.css('opacity', '0.5')
					.attr('title', '<?php _e('Maintenance disabled - PHP version incompatible', 'tracemyip-local-stats'); ?>');

				// Prevent all form submissions
				$('.tmip-settings-form, .tmip-maintenance-form').on('submit', function(e) {
					e.preventDefault();
					return false;
				});

				// Add visual feedback to tabs
				$('.nav-tab-wrapper .nav-tab').css({
					'opacity': '0.7',
					'cursor': 'not-allowed'
				});
			});
			</script>
			<?php endif; ?>
		</div>
		<?php
	}


    private function render_general_settings() {
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) $op=' style="opacity:0.9;"'; else $op='';
		?>
		<form method="post" action="options.php" class="tmip-settings-form <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
			<?php
			echo '<div'.$op.'>';
			settings_fields('tmip_local_stats_general');
			do_settings_sections('tmip_local_stats_general');
			echo '</div>';
			?>
			<div class="tmip-submit-button_wrap">
				<?php submit_button(); ?>
			</div>
		</form>
		<?php
	}

    private function render_dashboard_settings() {
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) $op=' style="opacity:0.5;pointer-events:none;"'; else $op=''; 
		echo '<div'.$op.'>';
		?>
		<form method="post" action="options.php" class="tmip-settings-form <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
			<?php
			settings_fields('tmip_local_stats_dashboard');
			do_settings_sections('tmip_local_stats_dashboard');
			?>
			<div class="tmip-submit-button_wrap">
				<?php submit_button(); ?>
			</div>
		</form>
		<?php
		echo '</div>';
	}

    private function render_log_filtering_settings() {
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
		if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) $op=' style="opacity:0.5;pointer-events:none;"'; else $op=''; 
		echo '<div'.$op.'>';
		?>
		<form method="post" action="options.php" class="tmip-settings-form <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
			<?php
			settings_fields('tmip_local_stats_log_filtering');
			do_settings_sections('tmip_local_stats_log_filtering');
			?>
			<div class="tmip-submit-button_wrap">
				<?php submit_button(); ?>
			</div>
		</form>
		<?php
		echo '</div>';
	}

	private function render_maintenance_settings() {
		$php_version_ok = TMIP_Local_Stats::is_php_compatible();
        ?>
        <div class="wrap">
			<?php 
			// Show admin notices
			settings_errors(); 
			?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" 
				  class="tmip-maintenance-form <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
				<!-- Rest of the form content -->
			</form>
		</div>
        <?php		
		
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" 
			  class="tmip-maintenance-form <?php echo !$php_version_ok ? 'tmip-php-incompatible' : ''; ?>">
			<input type="hidden" name="action" value="handle_maintenance_action">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=tmip_local_stats&tab=maintenance')); ?>">
			<?php wp_nonce_field('tmip_maintenance_actions'); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Clean Old Data', 'tracemyip-local-stats'); ?></th>
					<td>
						<div class="tmip-maintenance-action">
							<div class="tmip-action-controls">
								<input type="number" 
									   name="days_to_keep" 
									   value="30" 
									   min="1" 
									   max="90" 
									   class="small-text"
									   <?php disabled(!$php_version_ok); ?> />
								<span class="tmip-days-label"><?php _e('days', 'tracemyip-local-stats'); ?></span>
								<button type="submit" 
										name="maintenance_action" 
										value="delete_old_data" 
										class="button button-secondary"
										<?php disabled(!$php_version_ok); ?>>
									<?php _e('Clean Data Older Than Specified Days', 'tracemyip-local-stats'); ?>
								</button>
							</div>
							<p class="description">
								<?php _e('Removes tracking data older than the specified number of days while keeping recent data intact.', 'tracemyip-local-stats'); ?>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Reset All Data', 'tracemyip-local-stats'); ?></th>
					<td>
						<div class="tmip-maintenance-action">
							<div class="tmip-action-controls">
								<button type="submit" 
										name="maintenance_action" 
										value="delete_all_data" 
										class="button button-secondary">
									<?php _e('Delete All Tracking Data', 'tracemyip-local-stats'); ?>
								</button>
							</div>
							<p class="description tmip-option-warning">
								<?php _e('Warning: This will permanently delete all tracking data and reset the dashboard widget position. This action cannot be undone!', 'tracemyip-local-stats'); ?>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Reset Settings', 'tracemyip-local-stats'); ?></th>
					<td>
						<div class="tmip-maintenance-action">
							<div class="tmip-action-controls">
								<button type="submit" 
										name="maintenance_action" 
										value="reset_settings" 
										class="button button-secondary">
									<?php _e('Reset All Settings to Defaults', 'tracemyip-local-stats'); ?>
								</button>
							</div>
							<p class="description tmip-option-warning">
								<?php _e('Warning: This will reset all plugin settings to their default values. This action cannot be undone!', 'tracemyip-local-stats'); ?>
							</p>
						</div>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}
													

    public function register_settings() {
		
		// Register ALL settings
		foreach (TMIP_Local_Stats_Config::SETTINGS_FIELDS as $key => $setting) {
			register_setting(
				'tmip_local_stats_' . $setting['section'],
				'tmip_lc_' . $key,
				[
					'type' => $setting['type'],
					'default' => $setting['default'],
					'sanitize_callback' => function($value) use ($key) {
						if ($key === 'column_position') {
							// Special handling for column position
							$valid_options = array_keys(TMIP_Local_Stats_Config::SETTINGS_FIELDS['column_position']['options']);
							return in_array($value, $valid_options) ? $value : 'first';
						} else if ($key === 'chart_series_display') {
							// Existing chart series handling
							if (!is_array($value)) {
								return [];
							}
							$valid_options = TMIP_Local_Stats_Config::get_setting('chart_series_display')['options'];
							return array_intersect(array_keys($valid_options), $value);
						} else {
							// General validation for other settings
							return TMIP_Local_Stats_Config::validate_setting($key, $value);
						}
					}
				]
			);
		}
		
		
		// Register chart series display setting
		register_setting(
			'tmip_local_stats_dashboard',
			'tmip_lc_chart_series_display',
			[
				'type' => 'array',
				'default' => TMIP_Local_Stats_Config::get_default('chart_series_display'), // Use default from config
				'sanitize_callback' => function($value) {
					// Sanitize the input
					if (!is_array($value)) {
						return TMIP_Local_Stats_Config::get_default('chart_series_display'); // Default from config
					}

					$valid_options = TMIP_Local_Stats_Config::get_setting('chart_series_display')['options'];
					$valid_values = array_keys($valid_options);
					$sanitized_value = array_intersect($value, $valid_values);


					// Check for reset condition AFTER sanitizing
					$storage_method = get_option('tmip_lc_storage_method', 'cookies');
					if ($storage_method === 'cookieless' && count($sanitized_value) === 1 && in_array('unique', $sanitized_value)) {
						// If invalid combination, return default and add settings error
						add_settings_error(
							'tmip_lc_chart_series_display',
							'invalid_series_combination',
							__('Chart Series [Unique Visits] cannot be the only selected series when in cookieless mode. Resetting to default.', 'tracemyip-local-stats'),
							'warning'
						);
						return TMIP_Local_Stats_Config::get_default('chart_series_display'); // Default from config
					}

					// If valid, return the sanitized value
					return !empty($sanitized_value) ? array_values($sanitized_value) : TMIP_Local_Stats_Config::get_default('chart_series_display'); // Default from config
				}
			]
		);


		// Add the settings sections and fields
		foreach (TMIP_Local_Stats_Config::SETTINGS_SECTIONS as $section_key => $section) {
			add_settings_section(
				$section['id'],
				__($section['title'], 'tracemyip-local-stats'),
				array($this, $section['callback']),
				$section['page']
			);
		}

		foreach (TMIP_Local_Stats_Config::SETTINGS_FIELDS as $key => $setting) {
			if (isset($setting['callback'])) {
				add_settings_field(
					'tmip_lc_' . $key,
					__($setting['title'], 'tracemyip-local-stats'),
					array($this, $setting['callback']),
					'tmip_local_stats_' . $setting['section'],
					'tmip_local_stats_' . $setting['section']
				);
			}
		}

		
        // Register CPT settings
        $cpts = get_post_types(['public' => true, '_builtin' => false]);
        foreach ($cpts as $cpt) {
            $cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
            foreach ($cpt_settings as $key => $setting) {
                $option_name = 'tmip_lc_' . $key;
                $section = $setting['section'];

                // Register the setting
                register_setting(
                    'tmip_local_stats_' . $section,
                    $option_name,
                    [
                        'type' => $setting['type'],
                        'default' => $setting['default'],
                        'sanitize_callback' => function($value) use ($setting) {
                            switch ($setting['type']) {
                                case 'boolean':
                                    return (bool)$value;
                                case 'integer':
                                    $value = (int)$value;
                                    if (isset($setting['min'])) {
                                        $value = max($setting['min'], $value);
                                    }
                                    if (isset($setting['max'])) {
                                        $value = min($setting['max'], $value);
                                    }
                                    return $value;
                                default:
                                    return $value;
                            }
                        }
                    ]
                );
            }
        }
		
		// Callback to reset the chart series selectors to default if cookieless mode is set
		register_setting(
			'tmip_local_stats_general',
			'tmip_lc_storage_method',
			[
				'type' => 'string',
				'default' => 'cookies',
				'sanitize_callback' => function($value) {
					$sanitized_value = in_array($value, ['cookies', 'cookieless']) ? $value : 'cookies';

					// If switching to cookieless, check and reset chart series if needed
					if ($sanitized_value === 'cookieless') {
						$chart_series = get_option('tmip_lc_chart_series_display', TMIP_Local_Stats_Config::get_default('chart_series_display'));
						if (count($chart_series) === 1 && in_array('unique', $chart_series)) {
							update_option('tmip_lc_chart_series_display', TMIP_Local_Stats_Config::get_default('chart_series_display'));
							add_settings_error(
								'tmip_lc_chart_series_display',
								'invalid_series_combination',
								__('Chart series [Unique Visits] is not available in cookieless mode. Resetting chart series to default.', 'tracemyip-local-stats'),
								'warning'
							);
						}
					}
					return $sanitized_value;
				}
			]
		);		

		// Callback when enable/disable setting changes to move widget to top of dashboard
		register_setting(
			'tmip_local_stats_general',
			'tmip_lc_enable_unfiltered_stats',
			[
				'type' => 'boolean',
				'default' => 1,
				'sanitize_callback' => function($value) {
					$old_value = get_option('tmip_lc_enable_unfiltered_stats', 1);

					// If enabling from disabled state
					if ($value && !$old_value) {
						// Reset widget positions using the Dashboard class method
						if (TMIP_Local_Stats_Dashboard::reset_widget_positions()) {
							// Don't set transient here - let WordPress handle the notice
							remove_action('all_admin_notices', 'settings_errors');
						}
					}

					return (bool)$value;
				}
			]
		);
    }
	
	
	
    public function render_stats_retention_field() {
        $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
        ?>
        <div class="tmip-retention-settings">
            <input type="number" name="tmip_lc_stats_retention" class="tmip-select-dropdown"
                   value="<?php echo esc_attr(get_option('tmip_lc_stats_retention', $settings['stats_retention']['default'])); ?>" 
                   min="<?php echo $settings['stats_retention']['min']; ?>" 
                   max="<?php echo $settings['stats_retention']['max']; ?>" />
            <?php _e('days', 'tracemyip-local-stats'); ?>
			<span class="tmip-settings-default-value"><?php _e('Default: '.$settings['stats_retention']['default'], 'tracemyip-local-stats'); ?></span>
            <p class="tmip-note_small">
                <?php echo esc_html($settings['stats_retention']['description']); ?>
            </p>
        </div>
        <?php
    }
	
	
	public function render_chart_display_mode_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current_mode = get_option('tmip_lc_chart_display_mode', $settings['chart_display_mode']['default']);
		?>
		<select name="tmip_lc_chart_display_mode" class="tmip-select-dropdown">
			<?php foreach ($settings['chart_display_mode']['options'] as $value => $label): ?>
				<option value="<?php echo esc_attr($value); ?>" 
						<?php selected($current_mode, $value); ?>>
					<?php echo esc_html($label); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="tmip-note_small">
			<?php _e('Choose whether to display combined views or separate series for different content types.', 'tracemyip-local-stats'); ?>
		</p>
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
	
	public function render_active_ips_timeframe_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current = get_option('tmip_lc_active_ips_timeframe', $settings['active_ips_timeframe']['default']);
		$retention_days = (int)get_option('tmip_lc_ip_data_retention', $settings['ip_data_retention']['default']);
		$required_days = $settings['active_ips_timeframe']['required_days'][$current];
		?>
		<div class="tmip-active-ips-timeframe-settings">
			<select name="tmip_lc_active_ips_timeframe" 
					id="tmip_lc_active_ips_timeframe" 
					class="tmip-select-dropdown">
				<?php foreach ($settings['active_ips_timeframe']['options'] as $value => $label) : 
					$days_needed = $settings['active_ips_timeframe']['required_days'][$value];
					?>
					<option value="<?php echo esc_attr($value); ?>" 
							<?php selected($current, $value); ?>
							data-required-days="<?php echo $days_needed; ?>">
						<?php echo esc_html($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<div class="tmip-retention-warning" style="display: none; margin-top: 8px;">
				<p class="tmip-note_small tmip-option-warning">
					<?php _e('Warning: Selected time range requires', 'tracemyip-local-stats'); ?> 
					<span class="required-days"></span> 
					<?php _e('days of data retention. Please increase the IP Data Retention setting.', 'tracemyip-local-stats'); ?>
				</p>
			</div>
			<p class="tmip-note_small">
				<?php echo esc_html($settings['active_ips_timeframe']['description']); ?>
			</p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			function checkRetention() {
				var select = $('#tmip_lc_active_ips_timeframe');
				var option = select.find('option:selected');
				var requiredDays = parseInt(option.data('required-days'));
				var retentionDays = parseInt($('input[name="tmip_lc_ip_data_retention"]').val());
				var warning = select.siblings('.tmip-retention-warning');

				if (retentionDays < requiredDays) {
					warning.find('.required-days').text(requiredDays);
					warning.show();
				} else {
					warning.hide();
				}
			}

			$('#tmip_lc_active_ips_timeframe').on('change', checkRetention);
			$('input[name="tmip_lc_ip_data_retention"]').on('change', checkRetention);

			// Check on page load
			checkRetention();
		});
		</script>
		<?php
	}
	
	public function render_ip_retention_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-retention-settings">
			<input type="number" 
				   name="tmip_lc_ip_data_retention" 
				   class="tmip-select-dropdown"
				   value="<?php echo esc_attr(get_option('tmip_lc_ip_data_retention', 
					   $settings['ip_data_retention']['default'])); ?>" 
				   min="<?php echo $settings['ip_data_retention']['min']; ?>" 
				   max="<?php echo $settings['ip_data_retention']['max']; ?>" />
			<?php _e('days', 'tracemyip-local-stats'); ?>
			<span class="tmip-settings-default-value"><?php _e('Default: '.$settings['ip_data_retention']['default'], 'tracemyip-local-stats'); ?></span>
			<p class="tmip-note_small">
				<?php echo esc_html($settings['ip_data_retention']['description']); ?>
			</p>
		</div>
		<?php
	}
	
    // Section Callbacks
    public function general_section_callback() {
		
      $arr = TMIP_Local_Stats::tmip_parent_serv_notice();
      $par_notice = $arr['par_notice'];
      $par_btn_name = $arr['par_btn_name'];
      $par_btn_url = $arr['par_btn_url'];
      $par_btn_trg = $arr['par_btn_trg'];

      if (1==1) {
			echo '<div class="tmip_alertNeutral_div">';
		  	$v='';
			if (!empty($par_notice)) {
				$v='<hr>'.$par_notice; 
				$v.='<div style="padding:15px;"><a href="'.$par_btn_url.'" class="tmip-console-button" '.$par_btn_trg .'>'.$par_btn_name.'<span class="dashicons dashicons-external"></span></a></div>';
			}
			echo '<div>' . __('Configure general UnFiltered Stats tracking settings.'.$v, 'tracemyip-local-stats') . '</div>';
			echo '</div>';
		}
    }

    public function dashboard_section_callback() {
        echo '<div class="tmip_alertNeutral_div">';
        echo '<div>' . __('Customize UnFiltered Stats dashboard widget display.', 'tracemyip-local-stats') . '</div>';
        echo '</div>';
    }

    public function log_filtering_section_callback() {
        echo '<div class="tmip_alertNeutral_div">';
        echo '<div>' . __('Configure which visitors and IPs should be excluded from UnFiltered Stats post view tracking.', 'tracemyip-local-stats') . '</div>';
        echo '</div>';
    }

    // Field Render Methods
	public function render_enable_columns_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-checkbox-group">
			<div class="tmip-columns-main-settings">
				<label>
					<input type="checkbox" 
						   name="tmip_lc_enable_posts_column" 
						   value="1" 
						   <?php checked(get_option('tmip_lc_enable_posts_column', 
							   $settings['enable_posts_column']['default']), 1); ?> />
					<?php _e('Posts', 'tracemyip-local-stats'); ?>
				</label><br>
				<label>
					<input type="checkbox" 
						   name="tmip_lc_enable_pages_column" 
						   value="1" 
						   <?php checked(get_option('tmip_lc_enable_pages_column', 
							   $settings['enable_pages_column']['default']), 1); ?> />
					<?php _e('Pages', 'tracemyip-local-stats'); ?>
				</label><br>
			</div>

			<?php
			// Custom Post Types
			$cpts = get_post_types(['public' => true, '_builtin' => false]);
			if (!empty($cpts)) {
				?>
				<div class="tmip-cpt-section">
					<h4><?php _e('Custom Post Types', 'tracemyip-local-stats'); ?></h4>
					<div class="tmip-cpt-checkboxes">
						<?php
						foreach ($cpts as $cpt) {
							$cpt_obj = get_post_type_object($cpt);
							$cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
							?>
							<label>
								<input type="checkbox" 
									   name="tmip_lc_enable_cpt_column_<?php echo $cpt; ?>" 
									   value="1" 
									   <?php checked(get_option('tmip_lc_enable_cpt_column_'.$cpt, 
										   $cpt_settings['enable_cpt_column_'.$cpt]['default']), 1); ?> />
								<?php echo esc_html($cpt_obj->labels->name); ?>
							</label><br>
							<?php
						}
						?>
					</div>
				</div>
				<?php
			}
			?>
			<!-- Column Position Setting -->
			<div class="tmip-setting-section-subsettings" style="margin-top: 15px;">
				<label>
					<?php _e('Column Position:', 'tracemyip-local-stats'); ?>
					<select name="tmip_lc_column_position" class="tmip-select-dropdown">
						<?php 
						$current = get_option('tmip_lc_column_position', 
							$settings['column_position']['default']);
						foreach ($settings['column_position']['options'] as $value => $label): 
						?>
							<option value="<?php echo esc_attr($value); ?>" 
									<?php selected($current, $value); ?>>
								<?php echo esc_html($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>          
			<p class="tmip-note_small">
				<?php _e('Choose where to display view count columns in your admin lists. When enabled, a view counter column will appear in the selected content type listing screens. 
				<br><b>Note:</b> the position is affected by the visibility of columns set by WordPress "Screen options" selectors located on the post list pages', 'tracemyip-local-stats'); ?>
			</p>
		</div>
		<?php
	}

	
	
	public function render_storage_method_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current = get_option('tmip_lc_storage_method', $settings['storage_method']['default']);
		?>
		<div class="tmip-storage-method-settings">
			<select name="tmip_lc_storage_method" class="tmip-select-dropdown">
				<?php foreach ($settings['storage_method']['options'] as $value): ?>
					<option value="<?php echo esc_attr($value); ?>" 
							<?php selected($current, $value); ?>>
						<?php echo esc_html($this->get_storage_method_label($value)); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="tmip-note_small">
				<?php _e('Choose how to store visitor identification data. Note: Unique visits tracking requires cookies. If cookieless mode is selected, unique visits will be disabled.', 'tracemyip-local-stats'); ?>
			</p>
			<?php if ($current === 'cookieless'): ?>
				<a class="tmip-option-warning">
					<?php _e('Warning: Cookieless mode is enabled. Unique visits tracking is currently disabled.', 'tracemyip-local-stats'); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_storage_method_label($method) {
		switch($method) {
			case 'cookies':
				return __('Use Cookies (Enables unique visits tracking)', 'tracemyip-local-stats');
			case 'cookieless':
				return __('Cookieless (Disables unique visits tracking)', 'tracemyip-local-stats');
			default:
				return ucfirst($method);
		}
	}

	
	
	
    public function render_count_interval_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-count-interval">
			<input type="number" 
				   name="tmip_lc_count_interval" 
				   class="tmip-select-dropdown"
				   value="<?php echo esc_attr(get_option('tmip_lc_count_interval', 
					   $settings['count_interval']['default'])); ?>" 
				   min="<?php echo $settings['count_interval']['min']; ?>" 
				   max="<?php echo $settings['count_interval']['max']; ?>" />
			<select name="tmip_lc_count_interval_unit" class="tmip-select-dropdown">
				<?php foreach ($settings['count_interval_unit']['options'] as $unit): ?>
					<option value="<?php echo $unit; ?>" 
							<?php selected(get_option('tmip_lc_count_interval_unit', 
								$settings['count_interval_unit']['default']), $unit); ?>>
						<?php echo ucfirst($unit); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="tmip-settings-default-value"><?php _e('Default: '.$settings['count_interval']['default'], 'tracemyip-local-stats'); ?></span>
			<p class="tmip-note_small">
				<?php _e('This setting defines the interval used to count unique visits (not visitors, as each visitor can make multiple visits to the site). For example, setting this to 15 minutes will count a visitor who returns to your site after 15 minutes of inactivity as a new unique visit. This setting is only available when cookies are enabled.', 'tracemyip-local-stats'); ?>
			</p>
		</div>
		<?php
	}

	
    public function render_charts_retention_field() {
        $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
        ?>
        <div class="tmip-retention-settings">
            <input type="number" name="tmip_lc_charts_retention" class="tmip-select-dropdown"
                   value="<?php echo esc_attr(get_option('tmip_lc_charts_retention', $settings['charts_retention']['default'])); ?>" 
                   min="<?php echo $settings['charts_retention']['min']; ?>" 
                   max="<?php echo $settings['charts_retention']['max']; ?>" />
            <?php _e('days', 'tracemyip-local-stats'); ?>
			<span class="tmip-settings-default-value"><?php _e('Default: '.$settings['charts_retention']['default'], 'tracemyip-local-stats'); ?></span>
            <p class="tmip-note_small">
                <?php echo esc_html($settings['charts_retention']['description']); ?>
            </p>
        </div>
        <?php
    }
	
	public function render_chart_series_display_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;

		// Get the SANITIZED value.  This is the key fix!
		$selected_series = get_option('tmip_lc_chart_series_display', $settings['chart_series_display']['default']);


		$chart_mode = get_option('tmip_lc_chart_display_mode', 'separate');
		?>
		<div class="tmip-chart-series-settings">
			<?php foreach ($settings['chart_series_display']['options'] as $value => $label): ?>
				<label class="tmip-series-option">
					<input type="checkbox" 
						   name="tmip_lc_chart_series_display[]" 
						   value="<?php echo esc_attr($value); ?>" 
						   <?php checked(in_array($value, $selected_series)); ?>
						   <?php disabled($chart_mode === 'combined'); ?> />
					<?php echo esc_html($label); ?>
				</label><br>
			<?php endforeach; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Function to handle chart mode changes
			function handleChartModeChange() {
				var isCombined = $('select[name="tmip_lc_chart_display_mode"]').val() === 'combined';
				$('.tmip-series-option input[type="checkbox"]').prop('disabled', isCombined);
				$('.tmip-series-option').toggleClass('tmip-disabled', isCombined);
			}

			// Listen for chart mode changes
			$('select[name="tmip_lc_chart_display_mode"]').on('change', handleChartModeChange);

			// Initial state
			handleChartModeChange();
		});
		</script>

		<style>
		.tmip-chart-series-settings {
			margin-top: 10px;
		}
		.tmip-series-option {
			margin: 5px 0;
		}
		.tmip-series-option.tmip-disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}
		</style>
		<?php
	}	

    public function render_exclude_groups_field() {
        $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
        $exclude_groups = get_option('tmip_lc_exclude_groups', $settings['exclude_groups']['default']);
        ?>
        <div class="tmip-exclude-groups">
            <?php foreach ($settings['exclude_groups']['options'] as $group): ?>
                <label>
                    <input type="checkbox" name="tmip_lc_exclude_groups[]" value="<?php echo $group; ?>" 
                           <?php checked(in_array($group, $exclude_groups)); ?> />
                    <?php 
                    switch($group) {
                        case 'crawlers':
                            _e('Crawlers', 'tracemyip-local-stats');
                            echo ' <span class="tmip-note_small">'.__('(Search engine bots, web crawlers)', 'tracemyip-local-stats').'</span>';
                            break;
                        case 'ai_bots':
                            _e('AI Bots', 'tracemyip-local-stats');
                            echo ' <span class="tmip-note_small">'.__('(ChatGPT, Claude, etc.)', 'tracemyip-local-stats').'</span>';
                            break;
                        case 'logged_in':
                            _e('Logged-in Users', 'tracemyip-local-stats');
                            break;
                        case 'guests':
                            _e('Guests', 'tracemyip-local-stats');
                            echo ' <span class="tmip-note_small">'.__('(Non-logged-in visitors)', 'tracemyip-local-stats').'</span>';
                            break;
                    }
                    ?>
                </label><br>
            <?php endforeach; ?>
        </div>
        <p class="tmip-note_small">
            <?php _e('Select which user groups should be excluded from post views count', 'tracemyip-local-stats'); ?>
        </p>
        <?php
    }

	public function render_exclude_ips_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$exclude_ips = get_option('tmip_lc_exclude_ips', array());
		?>
		<div id="tmip-exclude-ips-container">
			<?php 
			if (empty($exclude_ips)) {
				echo '<div class="tmip-ip-input"><input type="text" name="tmip_lc_exclude_ips[]" placeholder="e.g. 192.168.1.1" /><button type="button" class="button tmip-remove-ip">' . __('Remove', 'tracemyip-local-stats') . '</button></div>';
			} else {
				foreach ($exclude_ips as $ip) {
					echo '<div class="tmip-ip-input"><input type="text" name="tmip_lc_exclude_ips[]" value="' . esc_attr($ip) . '" placeholder="e.g. 192.168.1.1" /><button type="button" class="button tmip-remove-ip">' . __('Remove', 'tracemyip-local-stats') . '</button></div>';
				}
			}
			?>
		</div>
		<button type="button" id="tmip-add-ip" class="button">
			<?php _e('Add IP', 'tracemyip-local-stats'); ?>
		</button>
		<button type="button" id="tmip-add-current-ip" class="button">
			<?php _e('Add Current IP', 'tracemyip-local-stats'); ?>
		</button>
		<p class="tmip-note_small">
			<?php printf(__('Enter the IP addresses to be excluded from post views count (max %d IPs)', 'tracemyip-local-stats'), 
						$settings['exclude_ips']['max_items']); ?>
		</p>
		<?php
	}
	
	public function render_enable_daily_chart_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-chart-settings">
			<!-- Main Enable/Disable Toggle -->
			<label>
				<input type="checkbox" name="tmip_lc_enable_daily_chart" value="1" 
					   <?php checked(get_option('tmip_lc_enable_daily_chart', $settings['enable_daily_chart']['default']), 1); ?> />
				<?php _e('Enable daily statistics chart', 'tracemyip-local-stats'); ?>
			</label>

			<!-- Chart Settings Sub-section -->
			<div class="tmip-setting-section-subsettings">
				<!-- Display Mode -->
				<div class="tmip-chart-display-mode">
					<label>
						<?php _e('Chart Display Mode:', 'tracemyip-local-stats'); ?>
						<select name="tmip_lc_chart_display_mode" class="tmip-select-dropdown">
							<?php 
							$current_mode = get_option('tmip_lc_chart_display_mode', $settings['chart_display_mode']['default']);
							foreach ($settings['chart_display_mode']['options'] as $value => $label): 
							?>
								<option value="<?php echo esc_attr($value); ?>" 
										<?php selected($current_mode, $value); ?>>
									<?php echo esc_html($label); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="tmip-note_small">
						<?php _e('Choose whether to display combined views or separate series for different content types.', 'tracemyip-local-stats'); ?>
					</p>
				</div>

				<!-- Series Display Options -->
				<div class="tmip-chart-series-options">
					<label><?php _e('Chart Series to Display:', 'tracemyip-local-stats'); ?></label>
					<div class="tmip-chart-series-settings">
						<?php 
						$selected_series = get_option('tmip_lc_chart_series_display', $settings['chart_series_display']['default']);
						foreach ($settings['chart_series_display']['options'] as $value => $label): 
						?>
							<label class="tmip-series-option">
								<input type="checkbox" 
									   name="tmip_lc_chart_series_display[]" 
									   value="<?php echo esc_attr($value); ?>" 
									   <?php checked(in_array($value, $selected_series)); ?>
									   <?php disabled($current_mode === 'combined'); ?> />
								<?php echo esc_html($label); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Function to toggle subsettings visibility
			function toggleChartSettings() {
				var enabled = $('input[name="tmip_lc_enable_daily_chart"]').is(':checked');
				$('.tmip-setting-section-subsettings').toggle(enabled);
			}

			// Function to handle chart mode changes
			function handleChartModeChange() {
				var isCombined = $('select[name="tmip_lc_chart_display_mode"]').val() === 'combined';
				$('.tmip-series-option input[type="checkbox"]').prop('disabled', isCombined);
				$('.tmip-series-option').toggleClass('tmip-disabled', isCombined);
			}

			// Initial state
			toggleChartSettings();
			handleChartModeChange();

			// Event listeners
			$('input[name="tmip_lc_enable_daily_chart"]').on('change', toggleChartSettings);
			$('select[name="tmip_lc_chart_display_mode"]').on('change', handleChartModeChange);
		});
		</script>
		<?php
	}

	
	public function render_dashboard_stats_order_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current = get_option('tmip_lc_dashboard_stats_order', $settings['dashboard_stats_order']['default']);
		?>
		<div class="tmip-dashboard-stats-order">
			<select name="tmip_lc_dashboard_stats_order" class="tmip-select-dropdown">
				<?php foreach ($settings['dashboard_stats_order']['options'] as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" 
							<?php selected($current, $value); ?>>
						<?php echo esc_html($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="tmip-note_small">
				<?php _e('Choose which statistic to show first in the Hits panel', 'tracemyip-local-stats'); ?>
			</p>
		</div>
		<?php
	}
	
    public function render_top_content_field() {
        $settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
        ?>
        <div class="tmip-top-content-settings">
            <div style="display: block; margin-top: 0px;">
                <label>
                    <input type="checkbox" name="tmip_lc_enable_top_posts" value="1" 
                           <?php checked(get_option('tmip_lc_enable_top_posts', $settings['enable_top_posts']['default']), 1); ?> />
                    <?php _e('Enable top', 'tracemyip-local-stats'); echo' <b>'; _e('posts', 'tracemyip-local-stats'); echo '</b>'; ?>
                </label>
            </div>
            <div style="margin-left: 20px; margin-top: 5px;">
                <?php _e('Show', 'tracemyip-local-stats'); ?> 
                <select name="tmip_lc_top_posts_count" class="tmip-select-dropdown">
                    <?php for ($i = $settings['top_posts_count']['min']; $i <= $settings['top_posts_count']['max']; $i++) : ?>
                        <option value="<?php echo $i; ?>" 
                                <?php selected(get_option('tmip_lc_top_posts_count', $settings['top_posts_count']['default']), $i); ?>>
                            <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <?php _e('most popular posts', 'tracemyip-local-stats'); ?>
            </div>
            
            <div style="display: block; margin-top: 10px;">
                <label>
                    <input type="checkbox" name="tmip_lc_enable_top_pages" value="1" 
                           <?php checked(get_option('tmip_lc_enable_top_pages', $settings['enable_top_pages']['default']), 1); ?> />
                    <?php _e('Enable top', 'tracemyip-local-stats'); echo' <b>'; _e('pages', 'tracemyip-local-stats'); echo '</b>'; ?>
                </label>
            </div>
            <div style="margin-left: 20px; margin-top: 5px;">
                <?php _e('Show', 'tracemyip-local-stats'); ?> 
                <select name="tmip_lc_top_pages_count" class="tmip-select-dropdown">
                    <?php for ($i = $settings['top_pages_count']['min']; $i <= $settings['top_pages_count']['max']; $i++) : ?>
                        <option value="<?php echo $i; ?>" 
                                <?php selected(get_option('tmip_lc_top_pages_count', $settings['top_pages_count']['default']), $i); ?>>
                            <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <?php _e('most popular pages', 'tracemyip-local-stats'); ?>
            </div>

            <?php
            // Custom Post Types
            $cpts = get_post_types(['public' => true, '_builtin' => false]);
            if (!empty($cpts)) {
                foreach ($cpts as $cpt) {
                    $cpt_obj = get_post_type_object($cpt);
                    $cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
                    $top_count_setting = $cpt_settings['top_count_' . $cpt];

                    // Get the saved value or default
                    $current_value = get_option('tmip_lc_top_count_' . $cpt, $top_count_setting['default']);
                    ?>
                    <div style="display: block; margin-top: 10px;">
                        <label>
                            <input type="checkbox" 
                                   name="tmip_lc_enable_cpt_dashboard_<?php echo esc_attr($cpt); ?>" 
                                   value="1" 
                                   <?php checked(get_option('tmip_lc_enable_cpt_dashboard_' . $cpt, 1), 1); ?> />
                            <?php _e('Enable top', 'tracemyip-local-stats'); echo' <b>'; echo strtolower(esc_html($cpt_obj->labels->name)); echo '</b>'; ?>
                        </label>
                    </div>
                    <div style="margin-left: 20px; margin-top: 5px;">
                        <?php _e('Show', 'tracemyip-local-stats'); ?> 
                        <select name="tmip_lc_top_count_<?php echo esc_attr($cpt); ?>" class="tmip-select-dropdown">
                            <?php 
                            for ($i = $top_count_setting['min']; $i <= $top_count_setting['max']; $i++) : 
                            ?>
                                <option value="<?php echo $i; ?>" 
                                        <?php selected($current_value, $i); ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <?php _e('most popular '.strtolower(esc_html($cpt_obj->labels->name)), 'tracemyip-local-stats'); ?>
                    </div>
                    <?php
                }
            }       
            ?>
        </div>
        <?php
    }

	public function render_recent_views_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-recent-views-settings">
			<!-- Main enable/disable checkbox -->
			<label>
				<input type="checkbox" 
					   name="tmip_lc_enable_recent_views" 
					   value="1" 
					   <?php checked(get_option('tmip_lc_enable_recent_views', $settings['enable_recent_views']['default']), 1); ?> />
				<?php _e('Hits Summary Panel', 'tracemyip-local-stats'); ?>
			</label>

			<!-- Sub-settings container -->
			<div class="tmip-setting-section-subsettings">
				<!-- Time period setting -->
				<div class="tmip-recent-views-period" style="margin-bottom: 15px;">
					<label>
						<?php _e('Show recent Hits in last', 'tracemyip-local-stats'); ?>
						<input type="number" 
							   name="tmip_lc_recent_views_minutes" 
							   class="tmip-select-dropdown"
							   value="<?php echo esc_attr(get_option('tmip_lc_recent_views_minutes', $settings['recent_views_minutes']['default'])); ?>" 
							   min="<?php echo $settings['recent_views_minutes']['min']; ?>" 
							   max="<?php echo $settings['recent_views_minutes']['max']; ?>" />
						<?php _e('minutes', 'tracemyip-local-stats'); ?>
					</label>
					<span class="tmip-settings-default-value"><?php _e('Default: '.$settings['recent_views_minutes']['default'], 'tracemyip-local-stats'); ?></span>
					<p class="tmip-note_small">
						<?php _e('Shows the total number of page loads within the specified time period.', 'tracemyip-local-stats'); ?>
					</p>
				</div>

				<!-- Display order setting -->
				<div class="tmip-recent-views-order">
					<label>
						<?php _e('Display Order:', 'tracemyip-local-stats'); ?>
						<select name="tmip_lc_dashboard_stats_order" class="tmip-select-dropdown">
							<?php 
							$current = get_option('tmip_lc_dashboard_stats_order', 
								$settings['dashboard_stats_order']['default']);
							foreach ($settings['dashboard_stats_order']['options'] as $value => $label) : ?>
								<option value="<?php echo esc_attr($value); ?>" 
										<?php selected($current, $value); ?>>
									<?php echo esc_html($label); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="tmip-note_small">
						<?php _e('Choose which statistic to show first in the Hits panel', 'tracemyip-local-stats'); ?>
					</p>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Toggle sub-settings visibility based on main checkbox
			function toggleSubsettings() {
				var enabled = $('input[name="tmip_lc_enable_recent_views"]').is(':checked');
				$('.tmip-recent-views-subsettings').toggle(enabled);
			}

			// Initial state
			toggleSubsettings();

			// Handle checkbox changes
			$('input[name="tmip_lc_enable_recent_views"]').on('change', toggleSubsettings);
		});
		</script>
		<?php
	}


	
	
	
	public function render_date_format_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current = get_option('tmip_lc_date_format', 'Y-m-d');
		$options = $settings['date_format']['options'];
		?>
		<div class="tmip-datetime-format">
			<select name="tmip_lc_date_format" id="tmip_lc_date_format" class="tmip-select-dropdown">
				<?php foreach ($options as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
						<?php echo esc_html($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="tmip-note_small">
				<?php _e('Select how dates should be displayed throughout the plugin.', 'tracemyip-local-stats'); ?>
			</p>
		</div>
		<?php
	}

	public function render_time_format_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		$current = get_option('tmip_lc_time_format', 'H:i:s');
		$options = $settings['time_format']['options'];
		?>
		<div class="tmip-datetime-format">
			<select name="tmip_lc_time_format" id="tmip_lc_time_format" class="tmip-select-dropdown">
				<?php foreach ($options as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
						<?php echo esc_html($label); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="tmip-note_small">
				<?php _e('Select how times should be displayed throughout the plugin.', 'tracemyip-local-stats'); ?>
			</p>
		</div>
		<?php
	}
	
	
	public function render_active_ips_settings_field() {
		$settings = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		?>
		<div class="tmip-active-ips-settings">
			<!-- Most Active IPs Section -->
			<div style="display: block; margin-top: 0px;">
				<label>
					<input type="checkbox" 
						   name="tmip_lc_enable_active_ips" 
						   value="1" 
						   <?php checked(get_option('tmip_lc_enable_active_ips', 
							   $settings['enable_active_ips']['default']), 1); ?> />
					<?php _e('Enable Most Active IPs', 'tracemyip-local-stats'); ?>
				</label>
			</div>
			<div style="margin-left: 20px; margin-top: 5px;">
				<?php _e('Show', 'tracemyip-local-stats'); ?> 
				<input type="number" 
					   name="tmip_lc_active_ips_limit" 
					   class="tmip-select-dropdown"
					   value="<?php echo esc_attr(get_option('tmip_lc_active_ips_limit', 
						   $settings['active_ips_limit']['default'])); ?>" 
					   min="<?php echo $settings['active_ips_limit']['min']; ?>" 
					   max="<?php echo $settings['active_ips_limit']['max']; ?>" />
				<?php _e('IPs for time range:', 'tracemyip-local-stats'); ?>
				<select name="tmip_lc_active_ips_timeframe" 
						id="tmip_lc_active_ips_timeframe" 
						class="tmip-select-dropdown">
					<?php foreach ($settings['active_ips_timeframe']['options'] as $value => $label) : 
						$days_needed = $settings['active_ips_timeframe']['required_days'][$value];
						?>
						<option value="<?php echo esc_attr($value); ?>" 
								<?php selected(get_option('tmip_lc_active_ips_timeframe', 
									$settings['active_ips_timeframe']['default']), $value); ?>
								data-required-days="<?php echo $days_needed; ?>">
							<?php echo esc_html($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div id="tmip-retention-warning" style="display: none; margin-top: 8px;">
				<p class="tmip-note_small tmip-option-warning">
					<?php _e('Warning: Selected time range requires', 'tracemyip-local-stats'); ?> 
					<span class="required-days"></span> 
					<?php _e('days of data retention. Please increase the IP Data Retention setting.', 'tracemyip-local-stats'); ?>
				</p>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			function checkRetention() {
				var select = $('#tmip_lc_active_ips_timeframe');
				var option = select.find('option:selected');
				var requiredDays = parseInt(option.data('required-days'));
				var retentionDays = parseInt($('input[name="tmip_lc_ip_data_retention"]').val());
				var warning = $('#tmip-retention-warning');

				if (retentionDays < requiredDays) {
					warning.find('.required-days').text(requiredDays);
					warning.show();
				} else {
					warning.hide();
				}
			}

			$('#tmip_lc_active_ips_timeframe').on('change', checkRetention);
			$('input[name="tmip_lc_ip_data_retention"]').on('change', checkRetention);

			// Check on page load
			checkRetention();
		});
		</script>
		<?php
	}		

	
    private function reset_settings_to_defaults() {
		try {
			// Start transaction
			global $wpdb;
			$wpdb->query('START TRANSACTION');

			// Reset all settings to defaults from config
			foreach (TMIP_Local_Stats_Config::SETTINGS_FIELDS as $key => $setting) {
				update_option('tmip_lc_' . $key, $setting['default']);
			}

			// Reset CPT settings
			$cpts = get_post_types(['public' => true, '_builtin' => false]);
			foreach ($cpts as $cpt) {
				$cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
				foreach ($cpt_settings as $key => $setting) {
					update_option('tmip_lc_' . $key, $setting['default']);
				}
			}

			// Reset widget positions using the helper method
			if (!TMIP_Local_Stats_Dashboard::reset_widget_positions()) {
				throw new Exception('Failed to reset widget positions');
			}

			$wpdb->query('COMMIT');

			// Add success message that will persist through redirect
			add_settings_error(
				'tmip_maintenance',
				'settings_reset_success',
				__('Success! All settings have been reset to their defaults and the dashboard widget has been repositioned.', 'tracemyip-local-stats'),
				'updated'
			);

			// Store message in transient for display after redirect
			set_transient('tmip_admin_notice', [
				'type' => 'success',
				'message' => __('Settings reset completed successfully! The dashboard widget has been moved to the top position.', 'tracemyip-local-stats')
			], 45);

			// Redirect to refresh page and show message
			wp_redirect(add_query_arg(['settings-updated' => 'true', 'action' => 'reset'], wp_get_referer()));
			exit;

		} catch (Exception $e) {
			global $wpdb;
			$wpdb->query('ROLLBACK');

			add_settings_error(
				'tmip_maintenance',
				'settings_reset_failed',
				__('Failed to reset settings. Please try again.', 'tracemyip-local-stats'),
				'error'
			);
		}
	}



	public function handle_maintenance_actions() {
		if (!isset($_POST['maintenance_action']) || !check_admin_referer('tmip_maintenance_actions')) {
			wp_safe_redirect(add_query_arg([
				'page' => 'tmip_local_stats',
				'tab' => 'maintenance'
			], admin_url('admin.php')));
			exit;
		}

		$action = sanitize_text_field($_POST['maintenance_action']);
		$stats = TMIP_Local_Stats::init();
		$redirect_args = [
			'page' => 'tmip_local_stats',
			'tab' => 'maintenance'
		];

		try {
			switch($action) {
				case 'delete_old_data':
					$days = isset($_POST['days_to_keep']) ? 
						min(max(absint($_POST['days_to_keep']), 1), 90) : 30;

					if ($stats->delete_old_data($days)) {
						$redirect_args['status'] = 'success';
						$redirect_args['action'] = 'delete_old_data';
						$redirect_args['days'] = $days;
					} else {
						throw new Exception('Failed to delete old data');
					}
					break;

				case 'delete_all_data':
					if ($stats->delete_all_data() && $stats->ensure_tables_exist()) {
						$redirect_args['status'] = 'success';
						$redirect_args['action'] = 'delete_all_data';
					} else {
						throw new Exception('Failed to delete and recreate data');
					}
					break;

				case 'reset_settings':
					if ($this->reset_settings_to_defaults()) {
						$redirect_args['status'] = 'success';
						$redirect_args['action'] = 'reset_settings';
					} else {
						throw new Exception('Failed to reset settings');
					}
					break;

				default:
					throw new Exception('Invalid maintenance action');
			}

		} catch (Exception $e) {
			$redirect_args['status'] = 'error';
			$redirect_args['message'] = urlencode($e->getMessage());
		}

		// Perform redirect with parameters
		wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
		exit;
	}


	
	

    public function __destruct() {
        // Handle any maintenance actions when the page is loaded
        if (isset($_POST['maintenance_action'])) {
            //$this->handle_maintenance_actions();
        }
    }
}

// Initialize the settings
TMIP_Local_Stats_Settings::init();

