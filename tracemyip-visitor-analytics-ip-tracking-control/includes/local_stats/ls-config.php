<?php
/* TraceMyIP > UnFiltered Stats */

/* INTEGRATION
	- Specify Text Domain: tracemyip-local-stats
	- Setup ls-config.php
	- Upload dir local_stats
	- Initialize: 052425122331, 
	- Include externally defined vars: tmipu_uf
	- Add UnFiltered Stats Settings link 052825081530
*/

defined('ABSPATH') || exit;

define('TMIP_UF_DEBUG', false); // Enable debug states in wp-config.php: define( 'WP_DEBUG', true ); and define('WP_DEBUG_LOG', true );
// JS debug is enabled in ls-js-admin.js


// Add debug logging function
function tmip_debug_log($message, $data = null) {
    if (defined('TMIP_DEBUG') && TMIP_DEBUG) {
        $log_message = '[TMIP Debug] ' . $message;
        if ($data !== null) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }
        error_log($log_message);
    }
}

class TMIP_Local_Stats_Config {
    
    private static $instance;
	
	const MIN_PHP_VERSION = '7.4.0';
    
    // Database configuration
    const DB_PREFIX = 'tmip_lc_';
 	const DB_CHARSET = 'utf8mb4';
    const DB_COLLATE = 'utf8mb4_unicode_520_ci';
	
	const DB_VERSION = '1.2';
	const DB_VERSION_OPTION = 'tmip_lc_db_version';
	
	// Additional option IDs
	const tmip_lc_total_logged_views_const = 'tmip_lc_total_logged_views'; // Total lifetime UnFiltered Stats logged pageviews
	
    
	const MAX_USER_AGENT_LENGTH = 255;
    const PLACEHOLDER_IP = '0.0.0.0';
    const VALID_IP_FLAGS = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
    
	const TRK_NONCE_CHECK = 2; // 1-allow cache, 2-cache bypass
    
    // Settings Registration Configuration
    const SETTINGS_SECTIONS = [
		
        'general' => [
			'id' => 'tmip_local_stats_general',
			'title' => 'General Settings',
			'page' => 'tmip_local_stats_general',
			'callback' => 'general_section_callback'
		],
		'dashboard' => [
			'id' => 'tmip_local_stats_dashboard',
			'title' => 'Dashboard Widget Settings',
			'page' => 'tmip_local_stats_dashboard',
			'callback' => 'dashboard_section_callback'
		],
		'log_filtering' => [
			'id' => 'tmip_local_stats_log_filtering',
			'title' => 'Log Filtering Settings',
			'page' => 'tmip_local_stats_log_filtering',
			'callback' => 'log_filtering_section_callback'
		]
    ];

    // Settings Fields Configuration
    const SETTINGS_FIELDS = [
		
		'first_activation' => [
			'section' => 'general',
			'default' => true,
			'type' => 'boolean',
			'internal' => true // Mark as internal setting that shouldn't show in UI
		],
		
		// General Settings
		'enable_unfiltered_stats' => [
			'section' => 'general',
			'title' => 'UnFiltered Stats',
			'callback' => 'render_enable_unfiltered_stats_field',
			'default' => 1,
			'type' => 'boolean',
			'description' => 'Enable or disable unfiltered stats tracking'
		],
		
		'timezone_setting' => [
			'section' => 'general',
			'title' => 'Timezone',
			'callback' => 'render_timezone_field',
			'default' => 'wordpress',
			'type' => 'string',
			'options' => ['wordpress', 'utc', 'custom']
		],
		'custom_timezone' => [
			'section' => 'general',
			'title' => 'Custom Timezone',
			'callback' => 'render_custom_timezone_field',
			'default' => '',
			'type' => 'string'
		],
		'date_format' => [
			'section' => 'general',
			'title' => 'Date Format',
			'callback' => 'render_date_format_field',
			'default' => 'Y-m-d',
			'type' => 'string',
			'options' => [
				'Y-m-d' => '2025-12-31 (Y-M-D)',
				'Y-d-m' => '2025-31-12 (Y-D-M)',
				'm-d-Y' => '12-31-2025 (M-D-Y)',
				'd-m-Y' => '31-12-2025 (D-M-Y)',
				'Y/m/d' => '2025/12/31 (Y/M/D)',
				'Y/d/m' => '2025/31/12 (Y/D/M)',
				'm/d/Y' => '12/31/2025 (M/D/Y)',
				'd/m/Y' => '31/12/2025 (D/M/Y)'
			]
		],
		'time_format' => [
			'section' => 'general',
			'title' => 'Time Format',
			'callback' => 'render_time_format_field',
			'default' => 'H:i:s',
			'type' => 'string',
			'options' => [
				'H:i:s' => '14:30:00 (24h)',
				'h:i:s A' => '02:30:00 PM (12h)',
				'H:i' => '14:30 (24h)',
				'h:i A' => '02:30 PM (12h)'
			]
		],
		
		'ip_lookup_service' => [
			'section' => 'general',
			'title' => 'TraceMyIP Lookup Service',
			'callback' => 'render_ip_lookup_service_field',
			'default' => 'https://tools.tracemyip.org/lookup/',
			'lookup_suffix' => ':-v-:r=wp_tmip_unfiltered_stats_lookup', // tools lookup request URL suffix
			'type' => 'string',
			'description' => 'Service URL for IP address lookups'
		],

        'enable_posts_column' => [
			'section' => 'general',
			'title' => 'Enable View Count Columns',
			'callback' => 'render_enable_columns_field',
			'default' => 1,
			'type' => 'boolean'
		],
		'enable_pages_column' => [
			'section' => 'general',
			'title' => 'Pages Column',
			'default' => 1,
			'type' => 'boolean'
		],
		'column_position' => [
			'section' => 'general',
			'title' => 'Hits Column Position',
			'default' => 'first',
			'type' => 'string',
			'options' => [
				'first' => 'First',
				'2' => '2',
				'3' => '3', 
				'4' => '4',
				'5' => '5',
				'6' => '6',
				'last' => 'Last'
			],
		],
		
        'storage_method' => [
			'section' => 'general',
			'title' => 'Data Storage',
			'callback' => 'render_storage_method_field',
			'default' => 'cookies',
			'type' => 'select',
			'options' => ['cookies', 'cookieless'],
			'description' => 'Choose how to store visitor identification data. Note: Unique visits tracking requires cookies. If cookieless mode is selected, unique visits will be disabled.'
		],
		
        'count_interval' => [
			'section' => 'general',
			'title' => 'Unique Visit Count Interval',
			'callback' => 'render_count_interval_field',
			'default' => 20,
			'type' => 'integer',
			'min' => 5,
			'max' => 1440
		],
		'count_interval_unit' => [
			'section' => 'general',
			'title' => 'Interval Unit',
			'default' => 'minutes',
			'type' => 'select',
			'options' => ['minutes', 'hours']
		],
        
        'ip_data_retention' => [
			'section' => 'general',
			'title' => 'IP Data Retention',
			'callback' => 'render_ip_retention_field',
			'default' => 31,
			'type' => 'integer',
			'min' => 7,
			'max' => 32,
			'description' => 'Days to keep IP-based tracking data'
		],
		'stats_retention' => [
			'section' => 'general',
			'title' => 'Stats Retention',
			'callback' => 'render_stats_retention_field',
			'default' => 365,
			'type' => 'integer',
			'min' => 30,
			'max' => 730,
			'description' => 'Days to keep aggregated statistics'
		],
        'charts_retention' => [
            'section' => 'general',
            'title' => 'Charts Data Retention',
            'callback' => 'render_charts_retention_field',
            'default' => 30,
            'type' => 'integer',
            'min' => 1,
            'max' => 90,
            'description' => 'Days to keep aggregated statistics'
        ],
		
		 # Dashboard Widget Settings
        // Recent Views Settings
        'enable_recent_views' => [
            'section' => 'dashboard',
            'title' => 'Enable Hits Summary Panel',
            'callback' => 'render_recent_views_field',
            'default' => 1,
            'type' => 'boolean'
        ],
		'dashboard_stats_order' => [
			'section' => 'dashboard',
			'parent' => 'enable_recent_views', // Add parent relationship
			'title' => 'Display Order',
			'default' => 'requests_first',
			'type' => 'string',
			'options' => [
				'requests_first' => 'Hits count first, IPs second',
				'ips_first' => 'IPs count first, Hits second'
			]
		],
		'chart_display_mode' => [
			'section' => 'dashboard',
			'title' => 'Chart Display Mode',
			'callback' => 'render_chart_display_mode_field',
			'default' => 'separate',
			'type' => 'string',
			'options' => ['combined', 'separate']
		],
		
		'recent_views_minutes' => [
            'section' => 'dashboard',
            'default' => 60,
            'type' => 'integer',
            'min' => 1,
            'max' => 60
        ],
		
        'enable_daily_chart' => [
			'section' => 'dashboard',
			'title' => 'Daily Chart Panel',
			'callback' => 'render_enable_daily_chart_field',
			'default' => 1,
			'type' => 'boolean'
		],
		'chart_display_mode' => [
			'section' => 'dashboard',
			'title' => 'Chart Display Mode',
			'default' => 'separate',
			'type' => 'string',
			'options' => [
				'combined' => 'Combined Hits',
				'separate' => 'Separate Series'
			]
		],
		'chart_series_display' => [
			'section' => 'dashboard',
			'title' => 'Chart Series to Display',
			'default' => ['posts', 'pages', 'custom'],
			'type' => 'array',
			'options' => [
				'posts' => 'Posts',
				'pages' => 'Pages',
				'custom' => 'Custom Post Types',
				'media' => 'Media',
				'other' => 'Other',
				'bots' => 'Bot Views',
				'unique' => 'Unique Views'
			],
			'description' => 'Select which data series to display in the chart'
		],

        'enable_top_posts' => [
            'section' => 'dashboard',
            'title' => 'Top Posts Panels',
            'callback' => 'render_top_content_field',
            'default' => 1,
            'type' => 'boolean'
        ],
        'top_posts_count' => [
            'section' => 'dashboard',
            'default' => 5,
            'type' => 'integer',
            'min' => 3,
            'max' => 20
        ],
        'enable_top_pages' => [
            'section' => 'dashboard',
            'default' => 1,
            'type' => 'boolean'
        ],
        'top_pages_count' => [
            'section' => 'dashboard',
            'default' => 5,
            'type' => 'integer',
            'min' => 3,
            'max' => 20
        ],
		'enable_active_ips' => [
			'section' => 'dashboard',
			'title' => 'Most Active IPs Panel',
			'callback' => 'render_active_ips_settings_field', // Changed callback
			'default' => 1,
			'type' => 'boolean'
		],
		'active_ips_limit' => [
			'section' => 'dashboard',
			'default' => 5,
			'type' => 'integer',
			'min' => 3,
			'max' => 20
		],
		'active_ips_timeframe' => [
			'section' => 'dashboard',
			'default' => '24h',
			'type' => 'string',
			'options' => [
				'5min' => 'Last 5 Minutes',
				'15min' => 'Last 15 Minutes',
				'60min' => 'Last Hour',
				'today' => 'Today',
				'24h' => 'Last 24 Hours',
				'72h' => 'Last 72 Hours',
				'week' => 'This Week',
				'7days' => 'Last 7 Days',
				'30days' => 'Last 30 Days',
				'month' => 'This Month'
			],
			'required_days' => [
				'5min' => 1,
				'15min' => 1,
				'60min' => 1,
				'today' => 1,
				'24h' => 2,
				'72h' => 4,
				'week' => 7,
				'7days' => 7,
				'month' => 30
			]
		],     
        
        # Log Filtering Settings
        'exclude_groups' => [
            'section' => 'log_filtering',
            'title' => 'Disable Tracking For',
            'callback' => 'render_exclude_groups_field',
            'default' => [],
            'type' => 'array',
            'options' => ['crawlers', 'ai_bots', 'logged_in', 'guests']
        ],
        'exclude_ips' => [
            'section' => 'log_filtering',
            'title' => 'Exclude Traffic by IP',
            'callback' => 'render_exclude_ips_field',
            'default' => [],
            'type' => 'ip_array',
            'max_items' => 10
        ],
        
        // Maintenance Settings
        'delete_on_deactivate' => [
            'section' => 'maintenance',
            'default' => 0,
            'type' => 'boolean'
        ]
    ];
    
    // Custom Post Type Settings Template
    const CPT_SETTINGS_TEMPLATE = [
		'enable_cpt_column' => [
			'section' => 'general',
			'default' => 1,
			'type' => 'boolean',
			'title' => 'Enable Column'
		],
		'enable_cpt_dashboard' => [
			'section' => 'dashboard',
			'default' => 1,
			'type' => 'boolean',
			'title' => 'Enable Dashboard'
		],
		'top_count' => [
			'section' => 'dashboard',
			'default' => 5, // Default number to show
			'min' => 3,
			'max' => 20,
			'type' => 'integer',
			'title' => 'Number to Show'
		]
	];
    
    // Bot Detection Patterns
    const BOT_SIGNATURES = [
        'bot', 'spider', 'crawl', 'googlebot', 'bingbot', 'slurp', 
        'duckduckbot', 'baiduspider', 'yandexbot', 'facebot', 
        'ia_archiver', 'ahrefs', 'mj12bot', 'semrush', 'dotbot', 
        'megaindex', 'blexbot', 'sistrix', 'rogerbot', 'openai', 
        'chatgpt', 'anthropic', 'claude'
    ];
	
    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function get_setting($key) {
        return isset(self::SETTINGS_FIELDS[$key]) ? self::SETTINGS_FIELDS[$key] : null;
    }
    
    public static function get_default($key) {
        return isset(self::SETTINGS_FIELDS[$key]['default']) ? self::SETTINGS_FIELDS[$key]['default'] : null;
    }
    
    public static function get_section($section_key) {
        return isset(self::SETTINGS_SECTIONS[$section_key]) ? self::SETTINGS_SECTIONS[$section_key] : null;
    }
    
    public static function get_settings_for_section($section) {
        $settings = [];
        foreach (self::SETTINGS_FIELDS as $key => $setting) {
            if ($setting['section'] === $section) {
                $settings[$key] = $setting;
            }
        }
        return $settings;
    }
    
	public static function get_cpt_settings($cpt_name) {
		$settings = [];
		foreach (self::CPT_SETTINGS_TEMPLATE as $base_key => $setting) {
			$key = $base_key . '_' . $cpt_name;
			$settings[$key] = array_merge($setting, [
				'cpt' => $cpt_name
			]);
		}
		return $settings;
	}

    
    public static function validate_setting($key, $value) {
        if (!isset(self::SETTINGS_FIELDS[$key])) {
            return null;
        }

        $setting = self::SETTINGS_FIELDS[$key];

        if (!isset($setting['type'])) {
            return $value;
        }

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

            case 'array':
                if (!is_array($value)) return $setting['default'];
                return array_intersect($value, $setting['options']);

            case 'ip_array':
                if (!is_array($value)) return [];
                $valid_ips = array_filter($value, function($ip) {
                    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
                });
                return array_slice($valid_ips, 0, $setting['max_items']);

            case 'select':
                return in_array($value, $setting['options']) ? $value : $setting['default'];

            case 'string':
                return sanitize_text_field($value);

            default:
                return $value;
        }
    }
	
	public static function validate_cpt_setting($cpt, $key, $value) {
        // Get the CPT settings template
        $cpt_settings = self::get_cpt_settings($cpt);
        
        // Extract the base key (remove the CPT name)
        $base_key = str_replace($cpt, '%s', $key);
        
        if (!isset($cpt_settings[$base_key])) {
            return null;
        }

        $setting = $cpt_settings[$base_key];

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
	
}

// Initialize the configuration
TMIP_Local_Stats_Config::init();
