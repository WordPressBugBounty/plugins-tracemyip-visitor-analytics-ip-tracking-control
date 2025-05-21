<?php
/*
	MODULE Migration: 052425092643
	
	This TMIP Config replaces 052125100146 (enable if deactivating notices)
	TO DO: delete transients and options by tmip_reset_delete_plugin_data()
*/
global $tmip_notices_debug;

$tmip_notices_debug=0; // 1-show skipped notices triggers, 2-show active notice vars, 99-reset all notices state

$tmip_trk_code_inits = (int)get_option(tmip_pag_trk_ploads_curr_opt);

return [
	
	// Initial setup reminder
	'visitor_tracker_setup_reminder' => [
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
        'expires_after'  => 7*86400,
        'days_after'     => 0,     
        'min_fe_req'  	 => 0,
        'min_trk_loads'  => 0,
        'probability'    => 100,
        'snooze_days'    => 7,
        //'start_date'     => strtotime('2025-05-01'),// Show starting Jan 1, 2023
        //'end_date'       => strtotime('2026-12-31'),// Stop showing after Dec 31, 2023
    ],
	
	
	// Review prompt
    'plugin_review_prompt' => [
        'font_color'     => '#FEFEFE',
        'font_size'      => '1.1em',
        'border_color'	 => '#00D898',		
        'background'     => '#666666',		
       
		'message'        => "<h2>⭕ Beep boop!</h2>
							<p>Hi there! Has ".tmip_service_Nname." helped you spy-er, analyze your traffic like a pro? ".tmip_service_Nname." <u>analyzed</u> and <u>protected</u> your site's <b>$tmip_trk_code_inits</b> page views. Please take a moment to leave a quick review on WordPress.org. We'll keep the circuits humming and the data flowing while you do. Deal?</p>",
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
        'days_after'     => 20,       				// Show after X days of installation
        'min_fe_req'  	 => 0,      				// Require at least X site front end requests to show notice
        'min_trk_loads'  => 500,      				// Require at least X TMIP tracker code site front end requests to show notice
        'probability'    => 60,     				// 100% chance to show when conditions met
        'snooze_days'    => 7,       				// Snooze for X days when "Remind Later" clicked
        //'start_date'     => strtotime('2025-05-01'),// Show starting Jan 1, 2023
        //'end_date'       => strtotime('2026-12-31'),// Stop showing after Dec 31, 2023
    ],
   
/*	
    'upgrade_prompt' => [
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
    ],
*/	
	
];