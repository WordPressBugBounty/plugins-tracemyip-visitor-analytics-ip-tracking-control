<?php
defined('ABSPATH') || exit;
/*
	Plugin Name: An Official TraceMyIP Tracker with email alerts
	Plugin URI: https://www.tracemyip.org
	Description: Website visitor IP address activity tracking, IP analytics, visitor email alerts, IP changes tracker and visitor IP address blocking. UnFiltered post stats dashboard. Tag visitors IPs, count and track post views, schedule email alerts, control and manage pages, links and protect contact forms. GDPR options.
	Version: 2.71
	Author: TraceMyIP.org
	Author URI: https://www.TraceMyIP.org
	Text Domain: tracemyip-local-stats
	License: GPLv2 (or later)
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/
if (!defined('TMIP_VERSION') ) 		define('TMIP_VERSION', '2.71');

### SET CONSTANTS ############################################
// header('X-XSS-Protection:0');
$script_filename = isset($_SERVER['SCRIPT_FILENAME']) ? trim($_SERVER['SCRIPT_FILENAME']) : '';
define("tmip_plugin_path",plugin_dir_path( __FILE__ ));
require_once(tmip_plugin_path.'languages/en.php'); /* Load plugin constants */

define("tmip_plugin_dir_name", 			'tracemyip-visitor-analytics-ip-tracking-control', false);
define("tmip_enable_local_tracker_ops", 1); 	// 1-Enable [UnFiltered Stats Tracker] options 052425122331
define("tmip_enable_user_notices", 		1); 	// User notices on WP Dashboard: 1-enable
define("tmip_enable_meta_rating", 		2); 	// Show rate section. 1-post selected rating, 2-show transitional screen
define("tmip_enable_meta_rating_menu", 	1); 	// Show rate section menu link
define("tmip_codes_usage_rate_thresh",	3); 	// Number of tracker calls required to show rating panel
define("tmip_html_to_js_format_realti", 1); 	// 1- Enable real time HTML to JavaScript code conversion
define("tmip_html_to_js_format_onsubm", 1); 	// 1- Enable reformatting html code to JS code on submit of new code or if realtime
												// html>JS have occured at least once
define("tmip_codes_usage_stats_data",	2);		// 1- Enable codes loading status, 2- Enable code usage process counts

define("tmip_trk_path_str_array",		array('vLg','tracker')); // path kneedle. First listed is used for gen 083122075843
define("tmip_trk_add_async_attr",		1); 	// add async attribute for tracker script if not present

### ADD PRE REQ ############################################
require_once(tmip_plugin_path.'includes/functions.php');
tmip_static_urls();
tmip_plugins_dirpath(__FILE__);
tmip_get_url_vars();
// register_activation_hook( __FILE__, 'tmip_func_on_activation' );

### ADD OPTIONS ############################################
tmip_reset_delete_plugin_data(4); // Add default option data

### ADD PLUGIN WP MENU ACTION LINKS ############################################
add_filter('plugin_action_links_'.plugin_basename (__FILE__), 'tmip_plugin_action_links');

### ADD PLUGIN LIST PAGE ROW LINKS ############################################
if (tmip_enable_meta_rating) add_filter( 'plugin_row_meta', 'tmip_plugin_row_add_rating', 2, 2 );

// Determine how plugin is loaded
global $WP_admin_pages;
$WP_admin_pages=NULL;
if (stristr($script_filename,'admin.php')) {
	$WP_admin_pages='admin';
} elseif (stristr($script_filename,'plugins.php')) {
	$WP_admin_pages='plugins';
} elseif (stristr($script_filename,'options-general.php')) {
	$WP_admin_pages='options-general';
}

// Execute on actual user requests (not WordPress preloads)
// * Additional checks for tmip_is_front_end_page()
if (defined('DOING_AJAX') || defined('DOING_CRON') || (defined('WP_INSTALLING') && WP_INSTALLING) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
    define("tmip_user_executed", 0, false);
} else {
	define("tmip_user_executed", 1, false);
}


### ADD USER ACCESS ############################################
add_action('admin_menu', 	'tmip_access_reports');
add_action('admin_menu' , 	'add_tmip_option_page');
add_action('admin_menu', 	'tmip_admin_menu');
add_action('wp_head', 		'tmip_addToTags');


### MODULES ############################################
# 052125092856
// USER NOTICES: Reset by $tmip_notices_debug
$tmip_if_plugin_upgrate=(isset($_GET['action']) && $_GET['action'] === 'do-plugin-upgrade' && strpos($_SERVER['REQUEST_URI'], 'update-core.php') !== false);
$tmipSysNTF=tmip_plugin_path . 'includes/classes/class-tmip-system-notices.php';
if (empty($tmip_if_plugin_upgrate) and tmip_enable_user_notices==1 and file_exists($tmipSysNTF)) {
    include_once($tmipSysNTF);
    new TMIPSystemNotices();
    // Reset notification statuses
    if (tmip_user_executed) {
        if (!get_option(tmip_user_notice_install_date)) update_option(tmip_user_notice_install_date, time());
        if (!empty($tmip_notices_debug) and $tmip_notices_debug===99) { // Reset notices state
            delete_option(tmip_user_notice_install_date);
            delete_option(tmip_user_notice_views);
            delete_option(tmip_user_notice_dismissed);
            delete_transient(tmip_user_notice_snoozed); 
        }
    }
}

// UNFILTERED STATS module (TraceMyIP > UnFiltered Stats) 052425122331
$tmipSysUFT=tmip_plugin_path . 'includes/local_stats/ls-class.php';
if (defined('tmip_enable_local_tracker_ops') && tmip_enable_local_tracker_ops and file_exists($tmipSysUFT)) {
    require_once(tmip_plugin_path.'includes/local_stats/ls-class.php');
    register_activation_hook(__FILE__, function() {
        TMIP_Local_Stats::init()->maybe_create_tables();
    });
    register_activation_hook(__FILE__, array('TMIP_Local_Stats', 'plugin_activated')); // Clear cache notice for installation
    add_action('init', function() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            tmip_debug_log('AJAX request detected');
            add_action('wp_ajax_tmip_record_view', array('TMIP_Local_Stats_Tracking', 'record_view'));
            add_action('wp_ajax_nopriv_tmip_record_view', array('TMIP_Local_Stats_Tracking', 'record_view'));
        }
    });
}

// Reset plugin settings: 1-reset all except no_reset flagged vars, 2-reset all to defaults, 3-delete all options
// tmip_reset_delete_plugin_data(3);
// tmip_write_debug_info_to_file($_SERVER);

// Add Page Tracker to header
add_action('wp_head','tmip_insert_page_tracker');

// Add Visitor Tracker to header or footer
$tmip_code_body_position=get_option(tmip_position_val);
if ($tmip_code_body_position) tmip_embed_body_html(array($tmip_code_body_position=>'tmip_insert_visitor_tracker'));

?>