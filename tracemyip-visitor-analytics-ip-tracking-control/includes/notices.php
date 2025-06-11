<?php
/*
	MODULE Migration: 052425092643
	
	This TMIP Config replaces 052125100146 (enable if deactivating notices)
	TO DO: delete transients and options by tmip_reset_delete_plugin_data()
*/
global $tmip_notices_debug;

$tmip_notices_debug=0; // 1-show skipped notices triggers, 2-show active notice vars, 99-reset all notices state


$tmip_notices_arr=array();

// Initial setup reminder
$tmip_notices_arr['visitor_tracker_setup_reminder']=array(
	'font_color'     => '#FEFEFE',
	'font_size'      => '1.1em',
	'border_color'	 => '#FFF',		
	'background'     => '#0073aa',
	'image_url'      => $tmip_plugin_dir_url.'images/tmip-robot-carl-120x120-gray-TR.png',
	'message'        => '<h3>Your '.tmip_service_Nname.' visitor tracker is almost ready!</h3> <p>Please configure settings.</p>',
	'action_url'     => admin_url('admin.php?page=tmip_lnk_wp_settings'),
	'action_text'    => 'Configure Now',
	//'dismiss_text'   => 'Dismiss',
	'snooze_text'    => 'Remind Me Later',
	'action_popup'   => 0,
	'if_option_is'   => array(tmip_visit_tracker_opt,'option_not_set'), 
	'views_limit'    => 50,
	'expires_after'  => 40*86400,
	'days_after'     => 0,     
	'min_fe_req'  	 => 0,
	'min_trk_loads'  => 0,
	'probability'    => 100,
	'snooze_days'    => 5,
	//'start_date'     => strtotime('2025-05-01'),// Show starting Jan 1, 2023
	//'end_date'       => strtotime('2026-12-31'),// Stop showing after Dec 31, 2023
);


// Plugin review reminder
$tmip_trk_code_requests = 	(int)get_option(tmip_pag_trk_ploads_curr_opt);	// Total TMIP Visitor Tracker code insertion requests
$tmip_us_total_requests = 	(int)get_option('tmip_lc_total_logged_views'); 	// Total TMIP UnFiltered Stats logged requests

$tmip_review_insert=''; 
if ($tmip_us_total_requests or $tmip_trk_code_requests) {
	$tmip_review_insert_txt=tmip_service_Nname." <u>analyzed</u> and <u>protected</u> your site's".'&nbsp;';
	if ($tmip_trk_code_requests and $tmip_trk_code_requests>=$tmip_us_total_requests) {
		$tmip_review_insert=$tmip_review_insert_txt."<b>$tmip_trk_code_requests</b> Visitor Tracker monitored page views so far.";
	} elseif ($tmip_us_total_requests and $tmip_us_total_requests>$tmip_trk_code_requests) 	{
		$tmip_review_insert=$tmip_review_insert_txt."<b>$tmip_us_total_requests</b> page views so far.";
	}
}

if ($tmip_review_insert) {

	$tmip_notices_arr['plugin_review_prompt']=array(
		'font_color'     => '#FEFEFE',
		'font_size'      => '1.1em',
		'border_color'	 => '#00D898',		
		'background'     => '#656667',		

		'message'        => "<h2>⭕ Beep boop!</h2>
							<p>Hi there! Has ".tmip_service_Nname." helped you spy-er, analyze your traffic like a pro? ".$tmip_review_insert." Please take a moment to leave a quick review on WordPress.org. We'll keep the circuits humming and the data flowing while you do. Deal?</p>",
	/*        
		'message'        => "<h3>Hi! They say being grateful is good for the soulâ€¦ and for uptime!</h3>
							<p>Have you found TraceMyIP useful? Please take a minute to leave a short review on WordPress.org â€” and weâ€™ll keep everything running smoothly while you do. (<i>Leave a review â€“ weâ€™ll pretend weâ€™re not watching</i> ðŸ‘€)</p>
							<p>P.S. Just so you know â€” we're truly grateful to have you on board!</p>",

		'message'        => "<b>Hi! Have you found TraceMyIP Useful?</b><br>Please, take a minute to write a short review on WordPress.org and we'll keep everything working meanwhile",
	*/        
		'image_url'      => $tmip_plugin_dir_url.'images/tmip-robot-carl-120x120-gray-TR.png',
		'action_url'     => defined('tmip_wp_plugin_review') ? tmip_wp_plugin_review : 'https://wordpress.org/support/plugin/tracemyip-visitor-analytics-ip-tracking-control/reviews/#new-post',
		'action_text'    => __('Leave Review', 		'tracemyip-visitor-analytics-ip-tracking-control'),
		'dismiss_text'   => __('Already Did', 		'tracemyip-visitor-analytics-ip-tracking-control'),
		'snooze_text'    => __('Remind Later', 		'tracemyip-visitor-analytics-ip-tracking-control'),

		'action_popup'   => 1,						// Open a new tab for link
		//'if_option_is'   => array(tmip_visit_tracker_opt,'option_is_set'), // get_option() condition: constant defined as name
		'views_limit'    => 300,					// Expire after X notice views
		//'expires_after'  => 7 * DAY_IN_SECONDS,	// Expire X seconds after first view
		'days_after'     => 20,       				// *20 Show after X days of installation 
		'min_fe_req'  	 => 300,      				// *100 Require at least X UnFiltered Stats logged views to show notice
		'min_trk_loads'  => 300,      				// *200 Require at least X TMIP Visitor Tracker code insertion requests to show notice
		'probability'    => 60,     				// *60% chance to show when conditions met
		'snooze_days'    => 7,       				// Snooze for X days when "Remind Later" clicked
		//'start_date'     => strtotime('2025-05-01'),// Show starting Jan 1, 2023
		//'end_date'       => strtotime('2026-12-31'),// Stop showing after Dec 31, 2023
	);
}

/*	
$tmip_notices_arr['upgrade_prompt']=array(
	'font_color'     => '#FEFEFE',
	'font_size'      => '1.0em',
	'border_color'	 => '#F0F',		
	'background'     => '#d54e21',
	'message'        => 'Want more features? Upgrade to Pro.',
	'action_url'     => '',
	'action_text'    => 'Upgrade',
	'action_popup'   => 0,
	'if_option_is'   => array(),
	'views_limit'    => 5,
	'expires_after'  => 604800, // 1 week
	'days_after'     => 0,
	'min_fe_req'  	 => 5,
	'min_trk_loads'  => 20,
	'probability'    => 50,
	'snooze_days'    => 7, 
	'start_date'     => strtotime('2023-06-01'),
	'end_date'       => strtotime('2026-06-30'),
);
*/









return $tmip_notices_arr;
?>