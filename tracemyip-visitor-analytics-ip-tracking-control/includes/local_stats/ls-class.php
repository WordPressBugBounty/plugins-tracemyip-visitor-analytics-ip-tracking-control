<?php
/* TraceMyIP > UnFiltered Stats Tracker */
defined('ABSPATH') || exit;

if (!defined('tmip_enable_local_tracker_ops')) {
    define('tmip_enable_local_tracker_ops', 1);
}

// _e(__tmipLg('This is <b>bold</b> word'), 'tracemyip-local-stats');
function _tmipLg($string, $domain = 'default') {
    // First, normalize existing spaces to prevent duplicates
    $string = preg_replace('/\s+/', ' ', $string);
    
    // Match any basic HTML tag with optional attributes (UTF-8 safe)
    $pattern = '#<([a-z0-9]+)([^>]*)>(.*?)</\1>#iu';
    $args = [];
    $index = 1;

    // Translate the string using __()
    $translated = __($string, $domain);

    // Replace tags with numbered placeholders
    $result = preg_replace_callback($pattern, function ($match) use (&$args, &$index) {
        $tag    = $match[1];
        $attrs  = $match[2];
        $inner  = $match[3];

        $args[] = "<{$tag}{$attrs}>";
        
        // Add a space after the closing tag if it's not followed by punctuation
        $args[] = "</{$tag}> ";

        return "%{$index}\$s" . $inner . "%" . ($index + 1) . "\$s";
    }, $translated);

    // Clean up any potential double spaces
    $result = trim(preg_replace('/\s+/', ' ', vsprintf($result, $args)));
    
    return $result;
}


// Include configuration
require_once plugin_dir_path(__FILE__) . 'ls-config.php';


class TMIP_Local_Stats {
    private static $instance;
    private static $timezone_cache = null;
    private $db_prefix;
    private $version;
	
	private static $php_compatible = null;
    public static function is_php_compatible() {
        if (self::$php_compatible === null) {
            self::$php_compatible = version_compare(PHP_VERSION, TMIP_Local_Stats_Config::MIN_PHP_VERSION, '>=');
        }
        return self::$php_compatible;
    }
	
    public static function init() {
        if (self::$instance === null) {
            // Check PHP version before doing anything
            if (!self::is_php_compatible()) {
                self::$instance = new self();
                // Only set up minimal hooks for admin warning
                if (is_admin()) {
                    add_action('admin_init', array(self::$instance, 'disable_features'));
                }
                return self::$instance;
            }
            
            self::$instance = new self();
            // Continue with normal initialization only if PHP version is compatible
            self::$instance->initialize();
        }
        return self::$instance;
    }
	
	public function disable_features() {
        // Ensure stats are disabled
        update_option('tmip_lc_enable_unfiltered_stats', 0);
        
        // Remove any scheduled events
        wp_clear_scheduled_hook('tmip_lc_daily_cleanup');
        wp_clear_scheduled_hook('tmip_lc_hourly_aggregate');
    }	
	
	public function initialize() {
		if (!self::is_php_compatible()) {
			return;
		}

		$this->db_prefix = TMIP_Local_Stats_Config::DB_PREFIX;
		$this->version = TMIP_Local_Stats_Config::DB_VERSION;

		if (!defined('TMIP_LOCAL_STATS_VERSION')) {
			$this->define_constants();
		}

		// Add this version check and upgrade
		$current_version = get_option(TMIP_Local_Stats_Config::DB_VERSION_OPTION);
		if ($current_version !== $this->version) {
			$this->upgrade_database($current_version);
		}

		// Check for database upgrades
		$this->ensure_tables_exist();

		if (!is_admin()) {
			$this->setup_frontend_hooks();
		} else {
			$this->setup_admin_hooks();
		}

		$this->includes();
	}
    
	/**
     * Detect active cache plugins
     */
    public static function detect_cache_plugins() {
        $cache_plugins = array(
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'sg-cachepress/sg-cachepress.php' => 'SG Optimizer',
            'breeze/breeze.php' => 'Breeze',
            'swift-performance-lite/performance.php' => 'Swift Performance Lite',
            'swift-performance/performance.php' => 'Swift Performance',
            'nginx-helper/nginx-helper.php' => 'Nginx Helper',
            'wp-optimize/wp-optimize.php' => 'WP-Optimize',
            'autoptimize/autoptimize.php' => 'Autoptimize'
        );

        $active_cache_plugins = array();
        foreach ($cache_plugins as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $active_cache_plugins[] = $plugin_name;
            }
        }

        return $active_cache_plugins;
    }
	
	
	/**
	 * Handle plugin activation
	 */
	public static function plugin_activated() {
		// Set activation notice transient
		set_transient('tmip_show_activation_cache_notice', true, 5);

		// Other activation tasks
		if (self::$instance === null) {
			self::$instance = new self();
		}
		self::$instance->activate();

		// Force first activation flag to true
		update_option('tmip_lc_first_activation', true);

		// Reset widget positions immediately after activation
		require_once plugin_dir_path(__FILE__) . 'admin/ls-dashboard-widget.php';
		TMIP_Local_Stats_Dashboard::reset_widget_positions();
	}

    // Move activation tasks here
    public function activate() {
        if (!tmip_enable_local_tracker_ops) return;

        // Set first activation flag
        add_option('tmip_lc_first_activation', true);

        // Ensure tables exist
        $this->ensure_tables_exist();

        // Schedule events
        if (!wp_next_scheduled('tmip_lc_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tmip_lc_daily_cleanup');
        }
        if (!wp_next_scheduled('tmip_lc_hourly_aggregate')) {
            wp_schedule_event(time(), 'hourly', 'tmip_lc_hourly_aggregate');
        }
    }
	
    public function deactivate() {
        try {
            // Clear all scheduled events
            $this->clear_scheduled_events();

            // Log deactivation if debug is enabled
            if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                error_log('TMIP: Plugin deactivated - ' . current_time('mysql'));
            }

        } catch (Exception $e) {
            error_log('TMIP Deactivation Error: ' . $e->getMessage());
        }
    }

	
    /**
     * Display cache notice
    */
	private static $cache_notice_displayed = false;
	public static function display_cache_notice($context = 'update') {
		// If notice already displayed, return
		if (self::$cache_notice_displayed) {
			return;
		}

		$active_cache_plugins = self::detect_cache_plugins();
		if (empty($active_cache_plugins)) {
			return;
		}

		$plugin_list = implode(', ', $active_cache_plugins);

		switch ($context) {
			case 'install':
				$message = sprintf(
					__('<b>Important:</b> You are using the following caching plugin(s): <strong>%s</strong>. Please <b>clear your cache</b> after setting up TraceMyIP to ensure proper functionality.', 'tracemyip-local-stats'),
					$plugin_list
				);
				break;

			case 'update':
				$message = sprintf(
					__('<b>Important:</b> You are using the following caching plugin(s): <strong>%s</strong>. Please <b>clear your cache</b> after updating settings to ensure changes take effect.', 'tracemyip-local-stats'),
					$plugin_list
				);
				break;

			case 'upgrade':
				$message = sprintf(
					__('<b>Important:</b> You are using the following caching plugin(s): <strong>%s</strong>. Please <b>clear your cache</b> after this plugin update to ensure all new features work properly.', 'tracemyip-local-stats'),
					$plugin_list
				);
				break;
		}

		?>
		<div class="tmip-notice tmip-notice-warning tmip-cache-notice is-dismissible">
			<p>
				<span class="dashicons dashicons-warning" style="color: #dba617; margin-right: 10px;"></span>
				<?php echo $message; ?>
			</p>
			<div class="tmip-cache-instructions">
				<?php _e('To ensure optimal performance:', 'tracemyip-local-stats'); ?>
				<ol style="margin-left: 30px; list-style-type: decimal;">
					<li><?php _e('Clear your cache plugin\'s cache', 'tracemyip-local-stats'); ?></li>
					<li><?php _e('Clear your browser cache', 'tracemyip-local-stats'); ?></li>
					<li><?php _e('If using a CDN, purge its cache', 'tracemyip-local-stats'); ?></li>
				</ol>
			</div>
		</div>
		<?php

		self::$cache_notice_displayed = true;
	}
	
	
    
	private function __construct() {
    	if (!tmip_enable_local_tracker_ops) return;

		$this->db_prefix = TMIP_Local_Stats_Config::DB_PREFIX;
		$this->version = TMIP_Local_Stats_Config::DB_VERSION;

		// Define constants first
		if (!defined('TMIP_LOCAL_STATS_VERSION')) {
			$this->define_constants();
		}

		// Then set up hooks
		if (!is_admin()) {
			$this->setup_frontend_hooks();
		} else {
			$this->setup_admin_hooks();
		}

		// Include required files
		$this->includes();
		
		// $this->init_cache_compatibility(); // WP Fastest Cache - enable?

		// Cache notices
		add_action('admin_notices', array($this, 'handle_cache_notices'));
	}


	private function setup_frontend_hooks() {
		// Frontend specific hooks
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
	}

	private function setup_admin_hooks() {
		// Admin specific hooks
		
		add_action('admin_menu', array($this, 'maybe_create_tables'), 5);
		add_action('admin_init', array($this, 'maybe_create_tables'), 5);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('admin_init', array($this, 'init_timezone'), 5);
	}
	
	/**
	 * Handle cache notice after plugin upgrade
	 */
	public function upgrade_cache_notice($upgrader_object, $options) {
		if (!isset($options['action']) || $options['action'] !== 'update' || 
			!isset($options['type']) || $options['type'] !== 'plugin' ||
			!isset($options['plugins']) || !is_array($options['plugins'])) {
			return;
		}

		// Check if our plugin was updated
		$plugin_path = 'tracemyip-visitor-analytics-ip-tracking-control/TraceMyIP-Wordpress-Plugin.php';
		if (!in_array($plugin_path, $options['plugins'])) {
			return;
		}

		// Set transient to show cache notice
		set_transient('tmip_show_upgrade_cache_notice', true, 5);
	}

	
	public static function debug_timezone_info($context = '') {
		if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
			$tz_string = self::get_timezone_string();
			$current_time = self::get_current_time_sql();
			$utc_time = gmdate('Y-m-d H:i:s');

			error_log(sprintf(
				'TMIP Timezone Debug [%s]: TZ: %s, Local: %s, UTC: %s',
				$context,
				$tz_string,
				$current_time,
				$utc_time
			));
		}
	}

	
    public function init_timezone() {
        // Force timezone initialization after WordPress is ready
        self::get_timezone_string();
        
        if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
            error_log('TMIP Debug - Timezone initialized at proper hook');
        }
    }

    public static function get_timezone_string() {
		// Return cached timezone if available
		if (self::$timezone_cache !== null) {
			return self::$timezone_cache;
		}

		// Ensure WordPress is fully loaded
		if (!did_action('init') && !did_action('admin_init')) {
			return 'UTC'; // Default to UTC if called too early
		}

		try {
			$timezone_setting = get_option('tmip_lc_timezone_setting', 'wordpress');

			switch($timezone_setting) {
				case 'wordpress':
					$wp_timezone = get_option('timezone_string');
					if (!empty($wp_timezone)) {
						self::$timezone_cache = $wp_timezone;
					} else {
						$offset = get_option('gmt_offset', 0);
						self::$timezone_cache = timezone_name_from_abbr('', $offset * 3600, 0);
					}
					break;

				case 'utc':
					self::$timezone_cache = 'UTC';
					break;

				case 'custom':
					$custom_tz = get_option('tmip_lc_custom_timezone', '');
					self::$timezone_cache = !empty($custom_tz) ? $custom_tz : 'UTC';
					break;

				default:
					self::$timezone_cache = 'UTC';
			}

			return self::$timezone_cache;

		} catch (Exception $e) {
			error_log('TMIP Timezone Error: ' . $e->getMessage());
			return 'UTC';
		}
	}

	public static function tmip_parent_serv_notice($return=NULL) {
        $output=array('par_notice'=>'','par_btn_url'=>'','par_btn_trg'=>'','par_btn_name'=>'');
        if (defined('tmipu_uf_vistr_srv_notice_1') and defined('tmipu_uf_vistr_srv_notice_2')) {
            if (empty(get_option(tmip_vis_trk_ploads_curr_opt))) {
                $output['par_notice']=tmipu_uf_vistr_srv_notice_1;
                $output['par_btn_url']=tmipu_uf_vistr_srv_btnurl_1;
                $output['par_btn_trg']=''; // 'target="_blank"
                $output['par_btn_name']='SETUP'; // 'target="_blank"
            } else {
                $output['par_notice']=tmipu_uf_vistr_srv_notice_2;
                $output['par_btn_url']=tmipu_uf_vistr_srv_btnurl_2;
                $output['par_btn_trg']='"';
                $output['par_btn_name']='GO';
            }
        }
        if ($return) return $output[$return]; else return $output;
    }
	
    public static function get_current_time_sql() {
		try {
			// Get timezone string
			$tz_string = self::get_timezone_string();

			// Create DateTime object in UTC
			$utc_now = new DateTime('now', new DateTimeZone('UTC'));

			// Convert to desired timezone
			$local_now = clone $utc_now;
			$local_now->setTimezone(new DateTimeZone($tz_string));

			// Get the offset in seconds
			$offset = $local_now->getOffset();

			// Apply offset to UTC time
			$utc_now->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');

			// Return in MySQL format
			return $utc_now->format('Y-m-d H:i:s');

		} catch (Exception $e) {
			error_log('TMIP Time Error: ' . $e->getMessage());
			return gmdate('Y-m-d H:i:s'); // Fallback to UTC
		}
	}
	
	public static function convert_from_db_time($time) {
		try {
			return new DateTime($time);
		} catch (Exception $e) {
			error_log('TMIP Time Conversion Error: ' . $e->getMessage());
			return new DateTime();
		}
	}
    
	
	private function define_constants() {
		define('TMIP_LOCAL_STATS_VERSION', defined('TMIP_VERSION') ? TMIP_VERSION : LS_STATS_VERSION);
		define('TMIP_LOCAL_STATS_DB_PREFIX', $this->db_prefix);
		define('TMIP_LOCAL_STATS_PATH', plugin_dir_path(__FILE__));

		// Get the plugin's base directory name
		$plugin_basename = 'tracemyip-visitor-analytics-ip-tracking-control';

		// Calculate plugin root URL
		$plugin_root_url = plugins_url($plugin_basename);

		// Define the local stats URL
		define('TMIP_LOCAL_STATS_URL', trailingslashit($plugin_root_url) . 'includes/local_stats/');
	}
	
    private function includes() {
        require_once TMIP_LOCAL_STATS_PATH . 'admin/ls-settings.php';
        require_once TMIP_LOCAL_STATS_PATH . 'admin/ls-dashboard-widget.php';
        require_once TMIP_LOCAL_STATS_PATH . 'admin/ls-posts-columns.php';
        require_once TMIP_LOCAL_STATS_PATH . 'ajax/ls-tracking.php';
        require_once TMIP_LOCAL_STATS_PATH . 'cron/ls-cleanup.php';
    }
    
    private function setup_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register uninstall hook (this is just for documentation, actual uninstall.php handles the cleanup)
        register_uninstall_hook(__FILE__, array('TMIP_Local_Stats_Uninstaller', 'uninstall'));        
        
       // add_action('init', array($this, 'load_textdomain'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }
    
    public function verify_tables() {
		global $wpdb;

		try {
			$views_table = $wpdb->prefix . 'tmip_lc_views';
			$stats_table = $wpdb->prefix . 'tmip_lc_daily_stats';
			$post_stats_table = $wpdb->prefix . 'tmip_lc_post_stats';

			// Check if tables exist
			$tables_exist = $wpdb->get_var("SHOW TABLES LIKE '$views_table'") === $views_table &&
						   $wpdb->get_var("SHOW TABLES LIKE '$stats_table'") === $stats_table &&
						   $wpdb->get_var("SHOW TABLES LIKE '$post_stats_table'") === $post_stats_table;

			if (!$tables_exist) {
				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log('TMIP Debug: Required tables do not exist');
				}
				return false;
			}

			return true;

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log("TMIP Table check error: " . $e->getMessage());
			}
			return false;
		}
	}
    
    public function set_default_options() {
        foreach (TMIP_Local_Stats_Config::SETTINGS_FIELDS as $key => $setting) {
            add_option('tmip_lc_' . $key, $setting['default']);
        }

        // Set CPT defaults
        $cpts = get_post_types(['public' => true, '_builtin' => false]);
        foreach ($cpts as $cpt) {
            $cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
            foreach ($cpt_settings as $key => $setting) {
                add_option('tmip_lc_' . $key, $setting['default']);
            }
        }
    }

    private function tables_exist() {
		global $wpdb;
		$views_table = $wpdb->prefix . 'tmip_lc_views';
		$post_stats_table = $wpdb->prefix . 'tmip_lc_post_stats';
		$daily_stats_table = $wpdb->prefix . 'tmip_lc_daily_stats';

		try {
			$views_exists = $wpdb->get_var("SHOW TABLES LIKE '$views_table'") === $views_table;
			$post_stats_exists = $wpdb->get_var("SHOW TABLES LIKE '$post_stats_table'") === $post_stats_table;
			$daily_stats_exists = $wpdb->get_var("SHOW TABLES LIKE '$daily_stats_table'") === $daily_stats_table;

			return $views_exists && $post_stats_exists && $daily_stats_exists;

		} catch (Exception $e) {
			error_log("TMIP Table check error: " . $e->getMessage());
			return false;
		}
	}

    
	private function create_tables() {
		global $wpdb;

		try {
			if ($this->tables_exist()) {
				return true;
			}

			$charset_collate = "ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci";

			// Raw pageload data table
			$sql = "CREATE TABLE {$wpdb->prefix}{$this->db_prefix}views (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				post_id bigint(20) NOT NULL,
				post_type varchar(20) NOT NULL,
				view_type varchar(20) NOT NULL,
				user_ip varchar(128) NOT NULL,
				visitor_id varchar(128) NOT NULL,
				user_agent varchar(255) DEFAULT NULL,
				is_bot tinyint(1) DEFAULT 0,
				is_logged_in tinyint(1) DEFAULT 0,
				view_date datetime NOT NULL,
				PRIMARY KEY (id),
				KEY post_id (post_id),
				KEY post_type (post_type),
				KEY view_type (view_type),
				KEY user_ip (user_ip),
				KEY view_date (view_date)
			) $charset_collate;";

			// Post stats table (aggregated)
			$sql .= "CREATE TABLE {$wpdb->prefix}{$this->db_prefix}post_stats (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				post_id bigint(20) NOT NULL,
				post_type varchar(20) NOT NULL,
				view_type varchar(20) NOT NULL,
				stat_date date NOT NULL,
				views_count int NOT NULL DEFAULT 0,
				unique_visits_count int NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY post_date (post_id, stat_date),
				KEY post_type (post_type),
				KEY view_type (view_type),
				KEY stat_date (stat_date)
			) $charset_collate;";

			// Daily chart data table (aggregated)
			$sql .= "CREATE TABLE {$wpdb->prefix}{$this->db_prefix}daily_stats (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				stat_date date NOT NULL,
				views_count int NOT NULL DEFAULT 0,
				unique_visits_count int NOT NULL DEFAULT 0,
				bot_views_count int NOT NULL DEFAULT 0,
				posts_views_count int NOT NULL DEFAULT 0,
				pages_views_count int NOT NULL DEFAULT 0,
				custom_views_count int NOT NULL DEFAULT 0,
				media_views_count int NOT NULL DEFAULT 0,
				other_views_count int NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY stat_date (stat_date)
			) $charset_collate;";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			$result = dbDelta($sql);

			if (is_wp_error($result)) {
				error_log('TMIP Table creation failed: ' . $result->get_error_message());
				return false;
			}

			update_option(TMIP_Local_Stats_Config::DB_VERSION_OPTION, $this->version);
			return true;

		} catch (Exception $e) {
			error_log('TMIP Table creation exception: ' . $e->getMessage());
			return false;
		}
	}

    public function aggregate_daily_stats($date = null) {
        global $wpdb;

        try {
            // If no date provided, use yesterday
            if (!$date) {
                $date = date('Y-m-d', strtotime('-1 day'));
            }

            // Start and end of the day
            $start_date = $date . ' 00:00:00';
            $end_date = $date . ' 23:59:59';

            // Count views for the day
            $views_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM {$wpdb->prefix}tmip_lc_views 
                 WHERE view_date BETWEEN %s AND %s",
                $start_date,
                $end_date
            ));

            if ($views_count === null) {
                throw new Exception('Failed to count views - ' . $wpdb->last_error);
            }

            // Insert or update daily stats
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}tmip_lc_daily_stats 
                 (stat_date, views_count, created_at) 
                 VALUES (%s, %d, NOW())
                 ON DUPLICATE KEY UPDATE 
                 views_count = %d,
                 created_at = NOW()",
                $date,
                $views_count,
                $views_count
            ));

            if ($result === false) {
                throw new Exception('Failed to update daily stats - ' . $wpdb->last_error);
            }

            return true;

        } catch (Exception $e) {
            error_log('TMIP: Exception aggregating daily stats - ' . $e->getMessage());
            return false;
        }
    }

    private function schedule_events() {
        if (!wp_next_scheduled('tmip_lc_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tmip_lc_daily_cleanup');
        }
    }
    
    private function clear_scheduled_events() {
        wp_clear_scheduled_hook('tmip_lc_daily_cleanup');
    }
    
    public function load_textdomain() {
        //load_plugin_textdomain('tracemyip-local-stats', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
	public function enqueue_admin_assets($hook) {

		// Ensure URL is properly formed
		$css_url = TMIP_LOCAL_STATS_URL . 'assets/css/ls-css-admin.css';

		// Add version as query string to avoid caching issues
		$version = defined('TMIP_LOCAL_STATS_VERSION') ? TMIP_LOCAL_STATS_VERSION : '1.0';

		// Load on dashboard, post/pages lists, plugin settings pages, AND during AJAX requests
		// Do not enable on post/page edit, causes issues due to CSS injection for post-ajax data: Connection lost. Saving has been disabled until you are reconnected
		$allowed_hooks = ['index.php', 'edit.php', 'toplevel_page_tmip_local_stats']; // Add allowed hooks
		if (in_array($hook, $allowed_hooks) || strpos($hook, 'tmip_local_stats') !== false || wp_doing_ajax() || strpos($hook, 'edit-') === 0) {
			wp_enqueue_style('tmip-local-stats-admin', $css_url, array(), $version);
		}    


		// Only load JS on plugin pages
		if (strpos($hook, 'tmip_local_stats') !== false) {
			wp_enqueue_script(
				'tmip-local-stats-admin',
				TMIP_LOCAL_STATS_URL . 'assets/js/ls-js-admin.js',
				array('jquery'),
				$version,
				true
			);

			wp_localize_script('tmip-local-stats-admin', 'tmipLocalStats', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('tmip_local_stats_nonce'),
				'deleteConfirmMessage' => __('Are you sure you want to delete this?', 'tracemyip-local-stats'),
				'loaderImage' => TMIP_LOCAL_STATS_URL . 'assets/images/ajLoader_05-BG222.gif'
			));
		}
	}

	public function enqueue_frontend_assets() {
		if (is_admin()) {
			return;
		}

		if (is_singular()) {
			wp_enqueue_script(
				'tmip-local-stats',
				TMIP_LOCAL_STATS_URL . 'assets/js/ls-js-admin.js',
				array('jquery'),
				TMIP_LOCAL_STATS_VERSION,
				true
			);

			// Add cache buster to nonce
			$nonce = wp_create_nonce('tmip_local_stats_nonce');

			if (1==2 and defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Debug - Generated nonce: ' . $nonce);
				error_log('TMIP Debug - WP Config: ' . print_r([
					'NONCE_KEY' => defined('NONCE_KEY'),
					'NONCE_SALT' => defined('NONCE_SALT'),
					'AUTH_KEY' => defined('AUTH_KEY'),
					'LOGGED_IN_KEY' => defined('LOGGED_IN_KEY')
				], true));
			}

			// Keep existing localization with added cache buster
			if (!wp_script_is('tmip-local-stats', 'localized')) {
				wp_localize_script('tmip-local-stats', 'tmipLocalStats', array(
					'ajaxurl' => admin_url('admin-ajax.php'),
					'nonce' => $nonce,
					'post_id' => get_the_ID(),
					'is_admin' => is_admin(),
					'fast_ajax' => defined('FAST_AJAX') && FAST_AJAX,
					'load_plugins' => json_encode(array(
						'tracemyip-visitor-analytics-ip-tracking-control/TraceMyIP-Wordpress-Plugin.php'
					)),
					'paginationNonce' => wp_create_nonce('tmip_pagination_nonce')
				));
				wp_add_inline_script('tmip-local-stats', '', 'localized');
			}
		}
	}
	
	public function init_cache_compatibility() {
		// WP Fastest Cache compatibility
		if (defined('WPFC_WP_PLUGIN_DIR')) {
			add_filter('wpfc_exclude_urls', array($this, 'exclude_ajax_from_cache'));
		}
	}

	public function exclude_ajax_from_cache($excluded_urls) {
		$excluded_urls[] = "/wp-admin/admin-ajax.php";
		return $excluded_urls;
	}

    
	
    public function delete_all_data() {
		global $wpdb;

		try {
			// Start transaction
			$wpdb->query('START TRANSACTION');

			// Drop all plugin tables
			$tables = array(
				$wpdb->prefix . 'tmip_lc_views',
				$wpdb->prefix . 'tmip_lc_post_stats', 
				$wpdb->prefix . 'tmip_lc_daily_stats'
			);

			foreach ($tables as $table) {
				$wpdb->query("DROP TABLE IF EXISTS $table");
			}

			// Delete all plugin options except first_activation
			// $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tmip_lc_%' AND option_name != 'tmip_lc_first_activation'");

			// Reset widget positions using the helper method
			if (!TMIP_Local_Stats_Dashboard::reset_widget_positions()) {
				throw new Exception('Failed to reset widget positions');
			}

			// Recreate tables
			if (!$this->create_tables()) {
				throw new Exception('Failed to recreate tables');
			}

			$wpdb->query('COMMIT');

			// Store success message in transient
			set_transient('tmip_admin_notice', [
				'type' => 'success',
				'message' => __('All tracking data has been deleted successfully! Tables have been recreated and the dashboard widget has been moved to the top position.', 'tracemyip-local-stats'),
    			'class' => 'tmip-notice tmip-notice-success'
			], 45);

			return true;

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');

			// Store error message in transient
			set_transient('tmip_admin_notice', [
				'type' => 'error',
				'message' => __('Failed to delete data. Please try again.', 'tracemyip-local-stats'),
    			'class' => 'tmip-notice tmip-notice-success'
			], 45);

			return false;
		}
	}


	public function verify_and_create_tables() {
		return $this->ensure_tables_exist();
	}
	public function ensure_tables_exist() {
		
		if (!self::is_php_compatible()) {
            return false;
        }
		
		if (!$this->tables_exist()) {
			$this->create_tables();
			$this->set_default_options();

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP: Tables created and defaults set');
			}
			return true;
		}
		return true;
	}
										   
	/**
	 * Handles database upgrades between versions
	 */
	private function upgrade_database($from_version) {
		global $wpdb;

		try {
			$wpdb->query('START TRANSACTION');

			// Upgrade from pre-1.2 to 1.2 (renaming unique_views_count to unique_visits_count)
			if (version_compare($from_version, '1.2', '<')) {
				$tables = array(
					$wpdb->prefix . $this->db_prefix . 'post_stats',
					$wpdb->prefix . $this->db_prefix . 'daily_stats'
				);

				foreach ($tables as $table) {
					// Check if old column exists and new column doesn't
					$old_column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unique_views_count'");
					$new_column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unique_visits_count'");

					if ($old_column_exists && !$new_column_exists) {
						// Rename the column
						$wpdb->query("ALTER TABLE {$table} 
									CHANGE COLUMN unique_views_count unique_visits_count INT NOT NULL DEFAULT 0");

						if ($wpdb->last_error) {
							throw new Exception("Failed to rename column in {$table}: " . $wpdb->last_error);
						}

						if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
							error_log("TMIP: Successfully renamed unique_views_count to unique_visits_count in {$table}");
						}
					}
				}
			}

			// Update the database version
			update_option(TMIP_Local_Stats_Config::DB_VERSION_OPTION, TMIP_Local_Stats_Config::DB_VERSION);

			$wpdb->query('COMMIT');
			return true;

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Database upgrade error: ' . $e->getMessage());
			}
			return false;
		}
}

	
	
										   

	
	public function delete_old_data($days = 30) {
		global $wpdb;

		try {
			// Start transaction
			$wpdb->query('START TRANSACTION');

			$date = date('Y-m-d H:i:s', strtotime("-$days days"));
			$date_only = date('Y-m-d', strtotime("-$days days"));

			// Delete from views table
			$views_deleted = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}tmip_lc_views 
				 WHERE view_date < %s",
				$date
			));

			// Delete from post stats table
			$post_stats_deleted = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}tmip_lc_post_stats 
				 WHERE stat_date < %s",
				$date_only
			));

			// Delete from daily stats table
			$daily_stats_deleted = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}tmip_lc_daily_stats 
				 WHERE stat_date < %s",
				$date_only
			));

			// Commit transaction
			$wpdb->query('COMMIT');

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log(sprintf(
					"TMIP: Successfully deleted data older than %d days:\n" .
					"- Views records: %d\n" .
					"- Post stats records: %d\n" .
					"- Daily stats records: %d",
					$days,
					$views_deleted,
					$post_stats_deleted,
					$daily_stats_deleted
				));
			}

			// Store success message in transient
			set_transient('tmip_admin_notice', [
				'type' => 'success',
				'message' => sprintf(
					__('Successfully cleaned up data older than %d days. Removed: %d views, %d post stats, and %d daily stats records.', 'tracemyip-local-stats'),
					$days,
					$views_deleted,
					$post_stats_deleted,
					$daily_stats_deleted
				),
    			'class' => 'tmip-notice tmip-notice-success'
			], 45);

			return true;

		} catch (Exception $e) {
			// Rollback on error
			$wpdb->query('ROLLBACK');

			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Error: Failed to delete old data - ' . $e->getMessage());
			}

			// Store error message in transient
			set_transient('tmip_admin_notice', [
				'type' => 'error',
				'message' => __('Failed to clean up old data. Please try again.', 'tracemyip-local-stats'),
    			'class' => 'tmip-notice tmip-notice-success'
			], 45);

			return false;
		}
	}
    
	public static function get_post_views($post_id) {
		global $wpdb;

		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}tmip_lc_views WHERE post_id = %d",
			$post_id
		));
	}
    
    public function maybe_create_tables() {
		if (!$this->tables_exist() || 
			get_option(TMIP_Local_Stats_Config::DB_VERSION_OPTION) !== $this->version) {
			$this->ensure_tables_exist();
		}
	}
	
	/**
     * Handle various cache notices
     */
    public function handle_cache_notices() {
		// If notice already displayed, return
		if (self::$cache_notice_displayed) {
			return;
		}

		// Get active cache plugins
		$active_cache_plugins = self::detect_cache_plugins();
		if (empty($active_cache_plugins)) {
			return;
		}

		// Determine which notice to show
		$notice_type = null;

		// Check for plugin update
		if (isset($_GET['action']) && $_GET['action'] === 'upgrade-plugin') {
			$notice_type = 'upgrade';
		}
		// Check for plugin activation
		elseif (get_transient('tmip_show_activation_cache_notice')) {
			$notice_type = 'install';
			delete_transient('tmip_show_activation_cache_notice');
		}
		// Check for settings update
		elseif (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true' 
			&& isset($_GET['page']) && strpos($_GET['page'], 'tmip_local_stats') !== false) {
			$notice_type = 'update';
		}

		// Display notice if type is determined
		if ($notice_type) {
			self::display_cache_notice($notice_type);
			self::$cache_notice_displayed = true;
		}
	}

											

}

// Initialize the plugin
if (tmip_enable_local_tracker_ops) {
    try {
        TMIP_Local_Stats::init();
    } catch (Exception $e) {
        error_log('TMIP Initialization Error: ' . $e->getMessage());
    }
}
