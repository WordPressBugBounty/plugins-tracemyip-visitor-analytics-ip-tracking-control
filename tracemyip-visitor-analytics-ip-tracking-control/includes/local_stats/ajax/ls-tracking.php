<?php
/* TraceMyIP > UnFiltered Stats */
defined('ABSPATH') || exit;

class TMIP_Local_Stats_Tracking {
    private static $instance;
    private $stats;

    public static function init() {
        if (!TMIP_Local_Stats::is_php_compatible()) {
            return null;
        }
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->stats = TMIP_Local_Stats::init();
        add_action('init', array($this, 'check_tables'), 5);
        add_action('wp_ajax_tmip_record_view', array($this, 'record_view'));
        add_action('wp_ajax_nopriv_tmip_record_view', array($this, 'record_view'));
    }

    private function should_track_view($post_id) {
        $exclude_groups = get_option('tmip_lc_exclude_groups', array());

        if (is_user_logged_in() && in_array('logged_in', $exclude_groups)) {
            return false;
        }

        if (!is_user_logged_in() && in_array('guests', $exclude_groups)) {
            return false;
        }

        if (in_array('crawlers', $exclude_groups) || in_array('ai_bots', $exclude_groups)) {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            if ($user_agent) {
                $bot_patterns = TMIP_Local_Stats_Config::BOT_SIGNATURES;
                foreach ($bot_patterns as $pattern) {
                    if (stripos($user_agent, $pattern) !== false) {
                        return false;
                    }
                }
            }
        }

        $exclude_ips = get_option('tmip_lc_exclude_ips', array());
        if (in_array($_SERVER['REMOTE_ADDR'], $exclude_ips)) {
            return false;
        }

        return true;
    }

    public function check_tables() {
        if ($this->stats instanceof TMIP_Local_Stats) {
            return $this->stats->verify_and_create_tables();
        }
        return false;
    }

    public function record_view() {
        // Cache exclusion headers
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        $nonce_tracking = TMIP_Local_Stats_Config::TRK_NONCE_CHECK;

        try {
            if (!TMIP_Local_Stats::is_php_compatible()) {
                wp_send_json_error([
                    'error' => 'PHP version incompatible',
                    'code' => 'php_incompatible'
                ]);
                return;
            }

            $received_nonce = isset($_POST['security']) ? $_POST['security'] : '';
            $clean_nonce = preg_replace('/_\d+$/', '', $received_nonce);

            if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                error_log('TMIP Debug - Original received nonce: ' . $received_nonce);
                error_log('TMIP Debug - Cleaned nonce: ' . $clean_nonce);
                error_log('TMIP Debug - Expected nonce: ' . wp_create_nonce('tmip_local_stats_nonce'));
                if ($nonce_tracking == 2) error_log('TMIP Debug - Allowed tracking by TRK_NONCE_CHECK=' . $nonce_tracking);
            }

            if (!get_option('tmip_lc_enable_unfiltered_stats', 1)) {
                if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                    error_log('TMIP Debug: Unfiltered stats tracking is disabled');
                }
                wp_send_json_success(['message' => 'UnFiltered Stats tracking is disabled']);
                return;
            }

            if ($nonce_tracking != 2 && !wp_verify_nonce($clean_nonce, 'tmip_local_stats_nonce')) {
                error_log('TMIP Debug: Invalid security token');
                wp_send_json_error([
                    'error' => 'Invalid security token',
                    'code' => 'nonce_invalid'
                ]);
                return;
            }

            if (!$this->stats->verify_tables()) {
                throw new Exception('Required database tables missing');
            }

            if (!isset($_POST['post_id'])) {
                throw new Exception('Missing post ID parameter');
            }

            $post_id = absint($_POST['post_id']);
            if (!$post_id || !get_post($post_id)) {
                throw new Exception('Invalid post ID');
            }

            if (!$this->should_track_view($post_id)) {
                wp_send_json_success(['message' => 'View not tracked (filtered)']);
                return;
            }

            $result = $this->save_view($post_id);
            if (!$result) {
                throw new Exception('Failed to save view data');
            }

            wp_send_json_success(['message' => 'View recorded successfully']);

        } catch (Exception $e) {
            if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
                error_log('TMIP Tracking Error: ' . $e->getMessage());
            }
            wp_send_json_error([
                'error' => $e->getMessage(),
                'code' => 'tracking_failed'
            ]);
        }
    }

    private function validate_ip($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, TMIP_Local_Stats_Config::VALID_IP_FLAGS)) {
            return $ip;
        }

        if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
            error_log('TMIP: Invalid IP detected and sanitized: ' . sanitize_text_field($ip));
        }

        return TMIP_Local_Stats_Config::PLACEHOLDER_IP;
    }

    private function sanitize_user_agent($user_agent) {
        if (!empty($user_agent)) {
            $user_agent = preg_replace('/[^\x20-\x7E]/', '', $user_agent);
            return substr($user_agent, 0, TMIP_Local_Stats_Config::MAX_USER_AGENT_LENGTH);
        }
        return '';
    }

    private function is_bot($user_agent) {
		if (isset($_SERVER['HTTP_FROM']) && stripos($_SERVER['HTTP_FROM'], 'bot(at)') !== false) {
			return 1;
		}
        if (!empty($user_agent)) {
            foreach (TMIP_Local_Stats_Config::BOT_SIGNATURES as $pattern) {
                $pattern = preg_quote($pattern, '/');
                if (preg_match("/$pattern/i", $user_agent)) {
                    return 1;
                }
            }
        }
        return 0;
    }

	private function save_view($post_id) {
		global $wpdb;

		try {
			// Get post and validate
			$post = get_post($post_id);
			if (!$post) {
				throw new Exception('Invalid post ID or post not found');
			}

			// Get current time and date in WordPress site timezone
			$tz_string = TMIP_Local_Stats::get_timezone_string();
			$current_time_tz = new DateTime('now', new DateTimeZone($tz_string));
			$current_time = $current_time_tz->format('Y-m-d H:i:s');
			$current_timestamp = $current_time_tz->getTimestamp();
			$today = date('Y-m-d', $current_timestamp);

			// Categorize the view type
			$view_type = 'views_others'; // Default
			switch ($post->post_type) {
				case 'post':
					$view_type = 'views_posts';
					break;
				case 'page':
					$view_type = 'views_pages';
					break;
				case 'attachment':
					$view_type = 'views_media';
					break;
				default:
					if (!in_array($post->post_type, ['post', 'page', 'attachment']) &&
						post_type_exists($post->post_type)) {
						$view_type = 'views_custom';
					}
			}

			// Get and sanitize user data
			$raw_ip = $_SERVER['REMOTE_ADDR'];
			$user_ip = $this->validate_ip($raw_ip);

			$visitor_id = isset($_POST['visitor_id']) ? sanitize_text_field($_POST['visitor_id']) : '';
			if (!preg_match('/^tmip_lc_[a-z0-9]{9}_\d+$/', $visitor_id)) {
				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log('TMIP Debug - Invalid visitor ID format: ' . $visitor_id);
				}
				$visitor_id = ''; // Clear if invalid format
			}

			$raw_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
			$user_agent = $this->sanitize_user_agent($raw_user_agent);
			$is_bot = $this->is_bot($user_agent);

			// Get Count Interval settings
			$storage_method = get_option('tmip_lc_storage_method', 'cookies');
			if ($storage_method === 'cookies') {
				$count_interval = (int)get_option('tmip_lc_count_interval', TMIP_Local_Stats_Config::get_default('count_interval'));
				$count_interval_unit = get_option('tmip_lc_count_interval_unit', 'minutes');
				$count_interval *= ($count_interval_unit === 'hours') ? 60 : 1;

				// Initialize unique visit check
				$is_unique_visit = false;
				$time_diff_minutes = 0;

				// Get and decode last visit time
				$last_visit_time = isset($_POST['last_visit_time']) ? urldecode($_POST['last_visit_time']) : null;

				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log('TMIP Debug - Last visit time: ' . ($last_visit_time ?? 'null'));
				}

				if ($count_interval == 0) {
					$is_unique_visit = true;
				} else if (!$last_visit_time) {
					$is_unique_visit = true;
				} else {
					try {
						// Parse the GMT time string
						$last_visit_dt = new DateTime($last_visit_time, new DateTimeZone('GMT'));
						$current_dt = new DateTime('now', new DateTimeZone('GMT'));

						// Calculate time difference in minutes
						$time_diff_minutes = ($current_dt->getTimestamp() - $last_visit_dt->getTimestamp()) / 60;
						$is_unique_visit = $time_diff_minutes >= $count_interval;

						if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
							error_log(sprintf(
								'TMIP Debug - Time Comparison: Last Visit: %s, Current: %s, Diff: %.2f minutes, Interval: %d, Is Unique: %s',
								$last_visit_dt->format('Y-m-d H:i:s T'),
								$current_dt->format('Y-m-d H:i:s T'),
								$time_diff_minutes,
								$count_interval,
								$is_unique_visit ? 'Yes' : 'No'
							));
						}
					} catch (Exception $e) {
						error_log('TMIP Debug - DateTime Error: ' . $e->getMessage());
						$is_unique_visit = true;
					}
				}
			}

			// Database Operations (wrapped in transaction)
			$wpdb->query('START TRANSACTION');

			try {
				// 1. Insert view record
				$view_data = array(
					'post_id' => $post_id,
					'post_type' => $post->post_type,
					'view_type' => $view_type,
					'user_ip' => $user_ip,
					'visitor_id' => $visitor_id,
					'user_agent' => $user_agent,
					'is_bot' => $is_bot,
					'is_logged_in' => is_user_logged_in() ? 1 : 0,
					'view_date' => $current_time
				);

				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log('TMIP Debug - Inserting view record: ' . print_r($view_data, true));
				}

				$result = $wpdb->insert(
					$wpdb->prefix . 'tmip_lc_views',
					$view_data,
					array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
				);

				if ($result === false) {
					throw new Exception('Failed to INSERT tmip_lc_views record: ' . $wpdb->last_error);
				} else {
					error_log('TMIP Debug - INSERTED [tmip_lc_views] record');
				}

				// 2. Update post stats
				$storage_key = "tmip_lc_last_visit_{$post_id}";

				// Get existing stats for today
				$existing_stats = $wpdb->get_row($wpdb->prepare(
					"SELECT views_count, unique_visits_count 
					 FROM {$wpdb->prefix}tmip_lc_post_stats 
					 WHERE post_id = %d 
					 AND stat_date = %s",
					$post_id, $today
				));

				// Initialize counts
				$views_count = ($existing_stats) ? (int)$existing_stats->views_count + 1 : 1;
				$unique_visits_count = ($existing_stats) ? (int)$existing_stats->unique_visits_count : 0;

				// Increment unique visits if this is a unique visit
				if ($is_unique_visit) {
					$unique_visits_count++;

					if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
						error_log(sprintf(
							'TMIP Debug - Incrementing Unique Visits: Post ID: %d, Previous Count: %d, New Count: %d',
							$post_id,
							($existing_stats ? $existing_stats->unique_visits_count : 0),
							$unique_visits_count
						));
					}
				}

				// Update post stats
				$result = $wpdb->query($wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}tmip_lc_post_stats
					 (post_id, post_type, view_type, stat_date, views_count, unique_visits_count, created_at)
					 VALUES (%d, %s, %s, %s, %d, %d, %s)
					 ON DUPLICATE KEY UPDATE 
					 views_count = %d, 
					 unique_visits_count = %d, 
					 created_at = %s",
					$post_id, $post->post_type, $view_type, $today, $views_count, $unique_visits_count, $current_time,
					$views_count, $unique_visits_count, $current_time
				));

				if ($result === false) {
					throw new Exception('Failed to update post stats: ' . $wpdb->last_error);
				}

				// 3. Update daily stats
				$daily_views_increment = 1;  // Always increment views
				$daily_unique_increment = $is_unique_visit ? 1 : 0;  // Increment unique visits only if it's unique

				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log(sprintf(
						'TMIP Debug - Daily Stats Update: Date: %s, Views: %d, Unique: %d, Is Unique Visit: %s, Time Diff: %.2f minutes',
						$today,
						$daily_views_increment,
						$daily_unique_increment,
						$is_unique_visit ? 'Yes' : 'No',
						$time_diff_minutes
					));
				}

				$result = $wpdb->query($wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}tmip_lc_daily_stats
					 (stat_date, views_count, unique_visits_count, bot_views_count, posts_views_count, pages_views_count, custom_views_count, media_views_count, other_views_count, created_at)
					 VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %d, %s)
					 ON DUPLICATE KEY UPDATE 
					 views_count = views_count + %d,
					 unique_visits_count = unique_visits_count + %d,
					 bot_views_count = bot_views_count + %d,
					 posts_views_count = posts_views_count + %d,
					 pages_views_count = pages_views_count + %d,
					 custom_views_count = custom_views_count + %d,
					 media_views_count = media_views_count + %d,
					 other_views_count = other_views_count + %d,
					 created_at = %s",
					// INSERT values
					$today,
					$daily_views_increment,
					$daily_unique_increment,
					$is_bot,
					($view_type === 'views_posts' ? 1 : 0),
					($view_type === 'views_pages' ? 1 : 0),
					($view_type === 'views_custom' ? 1 : 0),
					($view_type === 'views_media' ? 1 : 0),
					($view_type === 'views_others' ? 1 : 0),
					$current_time,
					// UPDATE values (same as INSERT)
					$daily_views_increment,
					$daily_unique_increment,
					$is_bot,
					($view_type === 'views_posts' ? 1 : 0),
					($view_type === 'views_pages' ? 1 : 0),
					($view_type === 'views_custom' ? 1 : 0),
					($view_type === 'views_media' ? 1 : 0),
					($view_type === 'views_others' ? 1 : 0),
					$current_time
				));

				if ($result === false) {
					throw new Exception('Failed to update daily stats: ' . $wpdb->last_error);
				}

				$wpdb->query('COMMIT');

				// Update last visit time IF it was a unique visit
				if ($is_unique_visit) {
					$storage_method = get_option('tmip_lc_storage_method', 'cookies');
					if ($storage_method === 'cookies') {
						$current_time_gmt = gmdate('D, d M Y H:i:s') . ' GMT';
						$expire = time() + (24 * 60 * 60); // 24 hours
						$domain = parse_url(get_site_url(), PHP_URL_HOST);

						// Set only the global cookie
						setcookie(
							'tmip_lc_global_last_visit',
							$current_time_gmt,
							[
								'expires' => $expire,
								'path' => '/',
								'domain' => $domain,
								'secure' => is_ssl(),
								'httponly' => false,
								'samesite' => 'Lax'
							]
						);

						if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
							error_log("TMIP Debug - Set global cookie to {$current_time_gmt}");
						}
					}
				}
				
				// Increment total UnFiltered Stats views captured
				$tmip_lc_total_logged_views_const=TMIP_Local_Stats_Config::tmip_lc_total_logged_views_const;
				update_option($tmip_lc_total_logged_views_const,(int)get_option($tmip_lc_total_logged_views_const)+1);
				
				// Log TMIP Visitor Tracker total code echo requests [0611251243]
				$v=(defined('tmip_enable_local_tracker_ops') and tmip_enable_local_tracker_ops==1) ? 1 : 0;
				if ($v==1 and function_exists('tmip_log_stat_data')) {
					tmip_insert_visitor_tracker($log_activity_only=1);
				}

				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log(sprintf(
						'TMIP View [tmip_lc_daily_stats Recorded] - Post ID: %d, Type: %s, View Type: %s, Time: %s, Visitor ID: %s',
						$post_id,
						$post->post_type,
						$view_type,
						$current_time,
						$visitor_id ?: 'none'
					));
				}

				return true;

			} catch (Exception $e) {
				$wpdb->query('ROLLBACK');

				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log('TMIP View Recording Error: ' . $e->getMessage());
					error_log('TMIP Debug: SQL Query - ' . $wpdb->last_query);
					error_log('TMIP_UF_DEBUG: SQL Error - ' . $wpdb->last_error);
				}
				return false;
			}

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP View Recording Error: ' . $e->getMessage());
			}
			return false;
		}
	}
	
}

TMIP_Local_Stats_Tracking::init();
