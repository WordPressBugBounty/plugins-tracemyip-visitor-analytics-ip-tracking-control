<?php
if (!defined('ABSPATH') or !defined('WP_UNINSTALL_PLUGIN')) exit;
require_once(__DIR__.DIRECTORY_SEPARATOR.'TraceMyIP-Wordpress-Plugin.php');
require_once(__DIR__.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'functions.php');
require_once(__DIR__.DIRECTORY_SEPARATOR.'languages'.DIRECTORY_SEPARATOR.'en.php'); /* Load plugin constants */

// TraceMyIP Plugin clean up
tmip_static_urls();
if (function_exists('is_multisite') and is_multisite()){
    global $wpdb;
    $initial_blog=$wpdb->blogid;
    $blog_ids=$wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs");
	if (is_array($blog_ids) and array_filter($blog_ids)) {
		foreach ($blog_ids as $blog_id) {
			switch_to_blog($blog_id);
			tmip_reset_delete_plugin_data(3);

		}
    	switch_to_blog($initial_blog);
	}
} else{
    tmip_reset_delete_plugin_data(3);
}


// TraceMyIP > unfiltered Stats clean up 052425122331
class TMIP_Local_Stats_Uninstaller {
    
    public static function uninstall() {
        global $wpdb;
		
        // Drop custom tables
        $tables = array(
            $wpdb->prefix . 'tmip_lc_views',
            $wpdb->prefix . 'tmip_lc_daily_stats',
            $wpdb->prefix . 'tmip_lc_post_stats'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
		// Include config file to get settings definitions
		require_once plugin_dir_path(__FILE__) . 'includes/local_stats/ls-config.php';

		// Get all settings fields
		$settings_fields = TMIP_Local_Stats_Config::SETTINGS_FIELDS;
		// Delete all plugin settings
		foreach ($settings_fields as $key => $setting) {
			delete_option('tmip_lc_' . $key);
		}
		
		// Delete custom post type settings for each public CPT
		$cpts = get_post_types(['public' => true, '_builtin' => false]);
		foreach ($cpts as $cpt) {
			$cpt_settings = TMIP_Local_Stats_Config::get_cpt_settings($cpt);
			foreach ($cpt_settings as $key => $setting) {
				delete_option('tmip_lc_' . $key);
			}
		}	
		
		# Delete additional option values
		// Last DB Version
		delete_option(TMIP_Local_Stats_Config::DB_VERSION_OPTION);
		
		// Total page view requests
		delete_option(TMIP_Local_Stats_Config::tmip_lc_total_logged_views_const);

        // Clear any scheduled hooks
        wp_clear_scheduled_hook('tmip_lc_daily_cleanup');
        
        // Optional: Delete any transients
        $wpdb->query(
            "DELETE FROM $wpdb->options 
             WHERE option_name LIKE '_transient_tmip_%' 
             OR option_name LIKE '_transient_timeout_tmip_%'"
        );
    }
}

TMIP_Local_Stats_Uninstaller::uninstall();


?>
