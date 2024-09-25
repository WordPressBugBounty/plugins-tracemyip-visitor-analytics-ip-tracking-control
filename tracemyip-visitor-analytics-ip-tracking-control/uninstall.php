<?php
if (!defined('ABSPATH') or !defined('WP_UNINSTALL_PLUGIN')) exit;
require_once(__DIR__.DIRECTORY_SEPARATOR.'TraceMyIP-Wordpress-Plugin.php');
require_once(__DIR__.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'functions.php');
require_once(__DIR__.DIRECTORY_SEPARATOR.'languages'.DIRECTORY_SEPARATOR.'en.php');

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
?>
