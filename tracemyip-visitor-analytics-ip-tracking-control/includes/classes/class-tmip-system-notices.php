<?php
/*
	MODULE Migration: 052425092643
	- get_option() keys
	- update_option() keys
	- get_transient() keys
	- integration: 052125092856
	
	- custom notice conditions to be revised/removed:
 	- 	'image_url'     => $tmip_plugin_dir_url
     	'min_fe_req'  	=> 0,	// Require at least X site front end requests to show notice
      	'min_trk_loads' => 500,	// Require at least X TMIP tracker code site front end requests to show notice
*/
class TMIPSystemNotices {
    private $notices;
    private $current_notice_key;
    private $settings;
    private static $view_count_incremented = false; // Add this static property
	
    public function __construct() {
		global $tmip_plugin_dir_url;

		$this->settings = get_option(tmip_user_notice_views, [
			'days_after' => 0,
			'min_fe_req' => 0,
			'min_trk_loads' => 0,
			'probability' => 0,
			'snooze_days' => 0
		]);

		// Load notices from notices.php
		$file_notices = include(tmip_plugin_path . 'includes/notices.php');

		// Get saved notices with view counts
		$saved_notices = get_option(tmip_user_notice_views, []);
		if (empty($saved_notices)) $saved_notices=array();

		// Merge file notices with saved data, preserving view counts and first_view
		$this->notices = $file_notices;
		foreach ($saved_notices as $key => $saved_notice) {
			if (isset($this->notices[$key])) {
				if (isset($saved_notice['views'])) {
					$this->notices[$key]['views'] = $saved_notice['views'];
				}
				if (isset($saved_notice['first_view'])) {
					$this->notices[$key]['first_view'] = $saved_notice['first_view'];
				}
			}
		}
		
		$this->debug_usr_notices = ($tmip_notices_debug ?? 0);

		add_action('admin_init', [$this, 'maybe_show_notice']);
		add_action('wp_ajax_tmip_dismiss_notice', [$this, 'handle_dismissal']);
	}

	public function maybe_show_notice() {
        // Don't show if user is not admin
        if (!tmip_user_executed or !current_user_can('manage_options')) {
            return;
        }
        
        // Only count views on actual user requests (not WordPress preloads)
        if (defined('DOING_AJAX') || defined('DOING_CRON') || 
            (defined('WP_INSTALLING') && WP_INSTALLING) || 
            (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }

        // Check if we should show a notice
        $notice_to_show = $this->get_notice_to_show();
        if (!$notice_to_show) {
            return;
        }

        $this->current_notice_key = $notice_to_show['key'];
        
        // Now safely increment
        $this->notices[$this->current_notice_key]['views'] = $this->get_notice_views($this->current_notice_key) + 1;
        
        // Set first view time if not set
        if (!isset($this->notices[$this->current_notice_key]['first_view'])) {
            $this->notices[$this->current_notice_key]['first_view'] = time();
        }
        update_option(tmip_user_notice_views, $this->notices);
        add_action('admin_notices', [$this, 'display_notice']);
    }
	
	
	
	private function get_notice_date_format($unix) {
		if ($unix) return date("m-d-Y H:i:s",$unix);
	}

    private function get_notice_to_show() {
        $now = time();
        $dismissed_notices = get_option(tmip_user_notice_dismissed, []);
        $snoozed_notices = get_transient(tmip_user_notice_snoozed) ?: [];
		
        $notice_arr = (!empty($this->notices)) ? $this->notices : [];
		
        foreach ( $notice_arr as $notice_key_name => $notice) {
			
			$tr_action=array();
			
            // Check if option is set, not or equals
            if (isset($notice['if_option_is'])) {
				$if_option_is=(isset($notice[$a='if_option_is'])) ? $notice[$a] : array();
				
				$_option_name=	(isset($if_option_is[$a=0])) ? $if_option_is[$a] : NULL;
				$_if_value=		(isset($if_option_is[$a=1])) ? $if_option_is[$a] : NULL;
				$_option_value=	(!empty(get_option($a=$_option_name))) ? get_option($a) : NULL;

				if ($_if_value=='option_is_set' and $_option_value!==NULL) {
				} elseif ($_if_value=='option_not_set' and empty($_option_value)) {
				} elseif ($_option_value==$_if_value) {
				} else {
                	$tr_action[]='No match for [if_option_is='.$_if_value.'] > get_option('.$_option_name.')';
				}
            }
			
            // Skip if permanently dismissed
            if (isset($dismissed_notices[$notice_key_name])) {
                $tr_action[]='Skip if permanently dismissed: ';
            }

            // Skip if snoozed
            if (!empty($notice['snooze_days']) and isset($snoozed_notices[$notice_key_name]) && $snoozed_notices[$notice_key_name] > $now) {
                $tr_action[]='Skip if snoozed: '.$this->get_notice_date_format($snoozed_notices[$notice_key_name]);
            }

            // Check views limit
            $views = $this->get_notice_views($notice_key_name);
            if (isset($notice['views_limit']) && $views >= $notice['views_limit']) {
                $tr_action[]='Check views limit: '.$views;
            }

            // Check if expired
            $first_view = $this->get_notice_first_view($notice_key_name);
            if ($first_view && isset($notice['expires_after']) && ($first_view + $notice['expires_after']) < $now) {
                $tr_action[]='Check if expired: '.($notice['expires_after']) .' seconds after first view: ' . $this->get_notice_date_format($first_view);
            }

            // Check start date
            if (isset($notice['start_date']) && $notice['start_date'] > $now) {
                $tr_action[]='Starting on: '.$this->get_notice_date_format($notice['start_date']);
            }

            // Check end date
            if (isset($notice['end_date']) && $notice['end_date'] < $now) {
                $tr_action[]='Expired per end date: '.$this->get_notice_date_format($notice['end_date']);
            }

            // Check probability
            if (isset($notice['probability']) && ($pRhalt=rand(1, 100)) > $notice['probability']) {
                $tr_action[]='Probability halt: '.$pRhalt.'>'.$notice['probability'].'';
            }

            // Check installation date
            $days_after = isset($notice['days_after']) ? $notice['days_after'] : $this->settings['days_after'];
			$install_date = get_option('tmip_install_date'); // If not present, plugin was installed before user notices implementation
            if ($days_after > 0) {
                $required_date = strtotime('-'.$days_after.' days');
                if (!empty($install_date) and $install_date > $required_date) {
                    $tr_action[]='Show '.$days_after.' day(s) after installation date: '.$this->get_notice_date_format($install_date);
                }
            }

            // Check minimum total site front end requests
            $min_fe_req = isset($notice['min_fe_req']) ? $notice['min_fe_req'] : $this->settings['min_fe_req'];
            if ($min_fe_req > 0 && !$this->has_min_front_end_req($min_fe_req)) {
                $tr_action[]='Check minimum site front-end requests: '.$this->has_min_front_end_req(0,1).' > '.$min_fe_req;
            }
			
            // Check minimum TMIP tracker code requests
            $min_trk_loads = isset($notice['min_trk_loads']) ? $notice['min_trk_loads'] : $this->settings['min_trk_loads'];
            if ($min_trk_loads > 0 && !$this->has_min_tracker_loads($min_trk_loads)) {
                $tr_action[]='Check minimum TMIP tracker code requests';
            }
			
			// Debug action preview
			if (!empty($this->debug_usr_notices)) {
				$debugArr=array();
				if (!empty($tr_action)) {
					if ($this->debug_usr_notices==1) $debugArr=$tr_action;
				} elseif (!empty($notice)) {
					if ($this->debug_usr_notices==2) $debugArr=$notice;
				}
				$debug_print=array_merge(array('notice_key_name'=>$notice_key_name),$debugArr);
				$debug_print=array_merge($debug_print,array(
					'curr_notice_install_date'=>$this->get_notice_date_format($install_date),
					'curr_front_end_requests'=>$this->has_min_front_end_req(0,1),
					'curr_tracker_code_loads'=>$this->has_min_tracker_loads(0,1),
					'curr_notice_vdate_time'=>$this->get_notice_date_format(time()),
					'curr_notice_first_view'=>$this->get_notice_date_format($first_view),
					'curr_notice_views'=>$views
				));
				if (!empty($debugArr)) 	tmip_show_array_notice($debug_print);
			}
			if (!empty($tr_action)) continue;
				
            return ['key' => $notice_key_name, 'notice' => $notice];
        }

        return false;
    }

    private function get_notice_views($key) {
        return isset($this->notices[$key]['views']) ? $this->notices[$key]['views'] : 0;
    }

    private function get_notice_first_view($key) {
        return isset($this->notices[$key]['first_view']) ? $this->notices[$key]['first_view'] : 0;
    }
	
	// Front end site requests
    private function has_min_front_end_req($min_count,$output_count=0) {
        $count_now = (int)get_option(tmip_vis_page_requests_opt); 
		if ($output_count) return $count_now;
        return $count_now >= $min_count;
    }
	// Visitor tracker code requests
    private function has_min_tracker_loads($min_count,$output_count=0) {
        $count_now = (int)get_option(tmip_pag_trk_ploads_curr_opt);
 		if ($output_count) return $count_now;
		return $count_now >= $min_count;
    }

	public function display_notice() {
        if (!isset($this->current_notice_key)) {
            return;
        }

        $notice = $this->notices[$this->current_notice_key];
		
        // Prepare styles
        $font_color = isset($notice['font_color']) ? $notice['font_color'] : '#FEFEFE';
        $font_size = isset($notice['font_size']) ? $notice['font_size'] : '1em';
        $border_color = isset($notice['border_color']) ? $notice['border_color'] : '#00D898';
        $background = isset($notice['background']) ? $notice['background'] : '#666666';
        ?>
        <style>
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> {
            color: <?php echo esc_attr($font_color); ?> !important;
            position: relative !important;
            border: 2px dashed <?php echo esc_attr($border_color); ?> !important;
			border-radius: 15px!important;
            background: <?php echo esc_attr($background); ?> !important;
            box-shadow: 4px 4px 10px rgba(0, 0, 0, 0.6) !important;
			padding: 6px 10px!important;
			max-width: 820px!important;
			margin: 0 auto!important;
        }
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> h1,
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> h2,
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> h3 {
            color: <?php echo esc_attr($font_color); ?> !important;
			padding: 0px!important;
			margin-top: 10px!important;
			margin-bottom: 6px!important;
        }
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> {
            font-size: <?php echo esc_attr($font_size); ?> !important;
            line-height: 1.6 !important;
            letter-spacing: 1.2px!important;
        }
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> p {
            font-size: <?php echo esc_attr($font_size); ?> !important;
            line-height: 1.6 !important;
            letter-spacing: 1.2px!important;
        }
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> a.button {
            font-size: <?php echo esc_attr($font_size); ?> !important;
            border: 2px solid #FEFEFE !important;
            margin-right: 10px;
        }    
        .tmip-notice-<?php echo esc_attr($this->current_notice_key); ?> .tmip-notice-dismiss {
            font-size: <?php echo esc_attr($font_size); ?> !important;
            border: 2px solid #333 !important;
        }
        .tmip-notice-robot-img {
            float: left;
            width: 120px;
            height: 120px;
            padding-right: 20px;
        }
        </style>

        <div class="notice updated promotion notice-info tmip-notice-<?php echo esc_attr($this->current_notice_key); ?>">
            <?php if (isset($notice['image_url'])) : ?>
                <img class="tmip-notice-robot-img" src="<?php echo esc_url($notice['image_url']); ?>">
            <?php endif; ?>
            
            <input type="hidden" id="tmip_notice_nonce" value="<?php echo wp_create_nonce('tmip-notice-nonce'); ?>">
            <input type="hidden" id="tmip_notice_key" value="<?php echo esc_attr($this->current_notice_key); ?>">
            
            <div>
                <?php echo wp_kses_post($notice['message']); 
				if (!empty($notice['action_popup'])) $action_popup='_blank'; else $action_popup='_self';
				?>
            </div>
            <div style="padding:10px 10px 10px 30px;">
                <?php if (isset($notice['action_url']) && isset($notice['action_text'])) : ?>
                    <a href="<?php echo esc_url($notice['action_url']); ?>" target="<?php echo trim($action_popup); ?>" class="button button-primary">
                        <?php echo esc_html($notice['action_text']); ?>
                    </a>
                <?php endif; ?>
                
                <?php if (isset($notice['dismiss_text'])) : ?>
                    <button type="button" class="button button-secondary tmip-notice-dismiss" data-dismiss="forever">
                        <?php echo esc_html($notice['dismiss_text']); ?>
                    </button>
                <?php endif; ?>
                
                <?php if (isset($notice['snooze_text'])) : ?>
                    <button type="button" class="button button-secondary tmip-notice-dismiss" data-dismiss="snooze">
                        <?php echo esc_html($notice['snooze_text']); ?>
                    </button>
                <?php endif; ?>
            </div>
			<div style="clear: both;"></div>
        </div>

        <script>
        (function($) {
            $(document).on('click', '.tmip-notice-dismiss', function(e) {
                e.preventDefault();
                var $notice = $(this).closest('.tmip-notice-<?php echo esc_attr($this->current_notice_key); ?>');
                var dismissAction = $(this).data('dismiss');
                var nonce = $('#tmip_notice_nonce').val();
                var noticeKey = $('#tmip_notice_key').val();

                $notice.find('.button').prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'tmip_dismiss_notice',
                    dismiss: dismissAction,
                    notice_key: noticeKey,
                    _wpnonce: nonce
                })
                .done(function() {
                    $notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                })
                .fail(function(xhr) {
                    alert('Error: ' + xhr.responseText);
                    $notice.find('.button').prop('disabled', false);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function handle_dismissal() {
        check_ajax_referer('tmip-notice-nonce', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'tracemyip-visitor-analytics-ip-tracking-control'), 403);
        }

        $action = isset($_POST['dismiss']) ? sanitize_text_field($_POST['dismiss']) : '';
        $notice_key = isset($_POST['notice_key']) ? sanitize_text_field($_POST['notice_key']) : '';

        if (empty($notice_key) || !isset($this->notices[$notice_key])) {
            wp_send_json_error(__('Invalid notice', 'tracemyip-visitor-analytics-ip-tracking-control'), 400);
        }

        $snooze_days = isset($this->notices[$notice_key]['snooze_days']) ? 
                      $this->notices[$notice_key]['snooze_days'] : 
                      $this->settings['snooze_days'];

        switch ($action) {
            case 'forever':
                $dismissed = get_option(tmip_user_notice_dismissed) ?: [];
                $dismissed[$notice_key] = time();
                update_option(tmip_user_notice_dismissed, $dismissed);
                wp_send_json_success();
                break;
                
            case 'snooze':
                $days = absint($snooze_days);
                $snoozed = get_transient(tmip_user_notice_snoozed) ?: [];
                $snoozed[$notice_key] = time() + ($days * DAY_IN_SECONDS);
                set_transient(tmip_user_notice_snoozed, $snoozed, $days * DAY_IN_SECONDS);
                wp_send_json_success();
                break;
                
            default:
                wp_send_json_error(__('Invalid action', 'tracemyip-visitor-analytics-ip-tracking-control'), 400);
        }
    }
}