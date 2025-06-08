<?php
/* TraceMyIP > UnFiltered Stats */
defined('ABSPATH') || exit;

class TMIP_Local_Stats_Posts_Columns {
    
    private static $instance;
    
    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
		$default_enabled = TMIP_Local_Stats_Config::get_default('enable_unfiltered_stats');
		if (!get_option('tmip_lc_enable_unfiltered_stats', $default_enabled)) {
			return; // Exit if UnFiltered Stats is disabled
		}
       
		// Built-in post types
        $builtin_post_types = ['post', 'page'];
        foreach ($builtin_post_types as $post_type) {
            // Get default from config if setting doesn't exist
            $default_enabled = TMIP_Local_Stats_Config::get_default("enable_{$post_type}s_column");
            if (get_option("tmip_lc_enable_{$post_type}s_column", $default_enabled)) {
                // Add debug filter first
                add_filter("manage_{$post_type}_posts_columns", array($this, 'debug_columns'), 1);
                add_filter("manage_{$post_type}_posts_columns", array($this, 'add_view_count_column'), 20);
                add_action("manage_{$post_type}_posts_custom_column", array($this, 'render_view_count_column'), 10, 2);
                add_filter("manage_edit-{$post_type}_sortable_columns", array($this, 'make_view_count_sortable'));
            }
        }

        // For custom post types
        add_action('registered_post_type', array($this, 'register_cpt_columns'));

        // Add sorting functionality
        add_action('pre_get_posts', array($this, 'handle_view_count_sorting'));
    }
    
    // Debug method to check columns
    public function debug_columns($columns) {
        if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
            error_log('TMIP Debug - Initial Columns: ' . print_r($columns, true));
        }
        return $columns;
    }

    public function register_cpt_columns($post_type) {
        if (post_type_exists($post_type) && !in_array($post_type, ['post', 'page'])) {
            // Get CPT specific settings template
            $cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($post_type);
            
            // Get default from CPT settings template
            $default_enabled = isset($cpt_settings["enable_cpt_column_{$post_type}"]['default']) 
                ? $cpt_settings["enable_cpt_column_{$post_type}"]['default'] 
                : true; // Default to true if not specified

            // Check if column is enabled for this CPT
            if (get_option("tmip_lc_enable_cpt_column_{$post_type}", $default_enabled)) {
                if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                    //error_log("TMIP Debug - Adding columns for CPT: {$post_type}");
                }
                
                add_filter("manage_{$post_type}_posts_columns", array($this, 'debug_columns'), 1);
                add_filter("manage_{$post_type}_posts_columns", array($this, 'add_view_count_column'), 20);
                add_action("manage_{$post_type}_posts_custom_column", array($this, 'render_view_count_column'), 10, 2);
                add_filter("manage_edit-{$post_type}_sortable_columns", array($this, 'make_view_count_sortable'));
            }
        }
    }

    public function add_view_count_column($columns) {
        // Get default position from config
        $position = get_option('tmip_lc_column_position', TMIP_Local_Stats_Config::get_default('column_position'));

        // Create the column header with logo
        $new_column = array(
            'tmip_view_count' => '<span class="tmip-view-count-header"><img src="'.TMIP_LOCAL_STATS_URL.'assets/images/TraceMyIP-Logo_28x28.png" alt="TraceMyIP Logo" style="vertical-align: middle;height:16px;width:16px;"/> Hits<span></span></span>'
        );

        // Remove our column if it exists anywhere
        if (isset($columns['tmip_view_count'])) {
            unset($columns['tmip_view_count']);
        }

        if ($position === 'last') {
            return array_merge($columns, $new_column);
        }
        else if ($position === 'first') {
            return $new_column + $columns;
        }
        else {
            // Convert to array format that can be spliced
            $keys = array_keys($columns);
            $values = array_values($columns);
            $combined = array_combine($keys, $values);
            
            // Convert to indexed array for splicing
            $columns_array = array();
            foreach ($combined as $key => $value) {
                $columns_array[] = array($key => $value);
            }
            
            // Calculate insert position (subtract 1 for zero-based array)
            $insert_position = (int)$position - 1;
            
            // Insert our column at the specified position
            array_splice($columns_array, $insert_position, 0, array($new_column));
            
            // Convert back to associative array
            $result = array();
            foreach ($columns_array as $item) {
                if (is_array($item)) {
                    $result += $item;
                }
            }
            
            if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                error_log('TMIP Debug - Position: ' . $position);
                error_log('TMIP Debug - Insert Position: ' . $insert_position);
                error_log('TMIP Debug - Final Result: ' . print_r($result, true));
            }
            
            return $result;
        }
    }

    public function render_view_count_column($column, $post_id) {
        if ($column === 'tmip_view_count') {
            $count = TMIP_Local_Stats::get_post_views($post_id);
            $class = $count > 0 ? 'has-views' : 'no-views';
            echo '<span class="tmip-view-count-badge ' . $class . '">' . number_format($count) . '</span>';
        }
    }
    
    public function make_view_count_sortable($columns) {
        $columns['tmip_view_count'] = 'tmip_view_count';
        return $columns;
    }
    
    public function handle_view_count_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        if ('tmip_view_count' === $orderby) {
            global $wpdb;

            // Add the JOIN and WHERE clauses
            add_filter('posts_join', function($join) use ($wpdb) {
                return $join . " LEFT JOIN (
                    SELECT post_id, COUNT(*) as view_count 
                    FROM {$wpdb->prefix}tmip_lc_views 
                    GROUP BY post_id
                ) AS view_counts ON {$wpdb->posts}.ID = view_counts.post_id";
            });

            // Add the ORDER BY clause
            add_filter('posts_orderby', function($orderby) use ($query) {
                $order = strtoupper($query->get('order'));
                $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
                return "COALESCE(view_count, 0) {$order}";
            });
        }
    }
}

// Initialize the class
TMIP_Local_Stats_Posts_Columns::init();
