<?php
/* TraceMyIP > UnFiltered Stats */
defined('ABSPATH') || exit;

class TMIP_Local_Stats_Cleanup {
    
    private static $instance;
    private $stats;
    
    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
    $this->stats = TMIP_Local_Stats::init();
    $this->db_prefix = TMIP_Local_Stats_Config::DB_PREFIX;
    add_action('tmip_lc_daily_cleanup', array($this, 'daily_cleanup'));
    add_action('tmip_lc_hourly_aggregate', array($this, 'hourly_aggregate'));
}
    
    /**
     * Performs daily cleanup and aggregation of statistical data
     */
	public function daily_cleanup() {
		try {
			// Verify tables exist
			if (!$this->stats->verify_tables()) {
				throw new Exception('Required database tables missing');
			}

			// Aggregate yesterday's stats
			$tz = new DateTimeZone(TMIP_Local_Stats::get_timezone_string());
			$datetime = new DateTime('now', $tz);
			$datetime->modify('-1 day');
			$yesterday = $datetime->format('Y-m-d');

			// Single aggregation attempt with proper logging
			if (!$this->aggregate_daily_stats($yesterday)) {
				if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
					error_log("TMIP: Failed to aggregate stats for $yesterday");
				}
			} elseif (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log("TMIP: Successfully aggregated stats for $yesterday");
			}

			// Get retention settings from config
			$ip_retention = get_option(
				'tmip_lc_ip_data_retention', 
				TMIP_Local_Stats_Config::get_default('ip_data_retention')
			);

			$stats_retention = get_option(
				'tmip_lc_stats_retention', 
				TMIP_Local_Stats_Config::get_default('stats_retention')
			);

			// Clean up IP-based view data
			if ($ip_retention > 0) {
				$this->cleanup_old_views($ip_retention);
			}

			// Clean up aggregated stats data
			if ($stats_retention > 0) {
				$this->cleanup_old_post_stats($stats_retention);
				$this->cleanup_old_daily_stats($stats_retention);
			}

			// Clean up orphaned records
			$this->cleanup_orphaned_records();

			// Optimize tables periodically
			$this->optimize_tables();

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP Cleanup Error: ' . $e->getMessage());
			}
		}
}
	

    /**
     * Aggregates hourly stats for real-time tracking
     */
    public function hourly_aggregate() {
        try {
            $today = date('Y-m-d');
            $this->aggregate_daily_stats($today, true); // true for partial day
        } catch (Exception $e) {
            error_log('TMIP Hourly Aggregate Error: ' . $e->getMessage());
        }
    }

    /**
     * Aggregates stats for a specific date
     */
    private function aggregate_daily_stats($date, $partial_day = false) {
		global $wpdb;

		try {
			// Convert date to user's timezone
			$tz = new DateTimeZone(TMIP_Local_Stats::get_timezone_string());
			$datetime = new DateTime($date, $tz);

			// Get start and end times in user's timezone
			$start_date = $datetime->format('Y-m-d 00:00:00');

			if ($partial_day) {
				$end_date = TMIP_Local_Stats::get_current_time_sql();
			} else {
				$end_date = $datetime->format('Y-m-d 23:59:59');
			}

            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Aggregate post stats
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}tmip_lc_post_stats 
                 (post_id, post_type, stat_date, views_count, unique_visits_count, created_at)
                 SELECT 
                    post_id,
                    (SELECT post_type FROM {$wpdb->posts} WHERE ID = post_id) as post_type,
                    DATE(view_date) as stat_date,
                    COUNT(*) as views_count,
                    COUNT(DISTINCT user_ip) as unique_visits_count,
                    NOW() as created_at
                 FROM {$wpdb->prefix}tmip_lc_views
                 WHERE view_date BETWEEN %s AND %s
                 GROUP BY post_id, DATE(view_date)
                 ON DUPLICATE KEY UPDATE
                 views_count = VALUES(views_count),
                 unique_visits_count = VALUES(unique_visits_count),
                 created_at = NOW()",
                $start_date,
                $end_date
            ));

            if ($result === false) {
                throw new Exception('Failed to aggregate post stats: ' . $wpdb->last_error);
            }

            // Aggregate daily stats
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}tmip_lc_daily_stats 
                 (stat_date, views_count, unique_visits_count, bot_views_count, created_at)
                 SELECT 
                    DATE(view_date) as stat_date,
                    COUNT(*) as views_count,
                    COUNT(DISTINCT user_ip) as unique_visits_count,
                    SUM(is_bot) as bot_views_count,
                    NOW() as created_at
                 FROM {$wpdb->prefix}tmip_lc_views
                 WHERE view_date BETWEEN %s AND %s
                 GROUP BY DATE(view_date)
                 ON DUPLICATE KEY UPDATE
                 views_count = VALUES(views_count),
                 unique_visits_count = VALUES(unique_visits_count),
                 bot_views_count = VALUES(bot_views_count),
                 created_at = NOW()",
                $start_date,
                $end_date
            ));

            if ($result === false) {
                throw new Exception('Failed to aggregate daily stats: ' . $wpdb->last_error);
            }

            $wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('TMIP: Exception aggregating daily stats - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cleans up old view records
     */
	 private function cleanup_old_views($days) {
		global $wpdb;

		try {
			$tz = new DateTimeZone(TMIP_Local_Stats::get_timezone_string());
			$datetime = new DateTime('now', $tz);
			$datetime->modify("-$days days");
			$cutoff_date = $datetime->format('Y-m-d H:i:s');

			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}{$this->db_prefix}views 
				 WHERE view_date < %s",
				$cutoff_date
			));

			if ($result !== false && defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log("TMIP: Cleaned up $result old view records");
			}
			return ($result !== false);

		} catch (Exception $e) {
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log('TMIP: Exception cleaning up old views - ' . $e->getMessage());
			}
			return false;
		}
	}

    /**
     * Cleans up old post stats records
     */
    private function cleanup_old_post_stats($days) {
        global $wpdb;
        
        try {
            $date = date('Y-m-d', strtotime("-$days days"));
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tmip_lc_post_stats 
                 WHERE stat_date < %s",
                $date
            ));

            if ($result !== false) {
                error_log("TMIP: Cleaned up $result old post stat records");
                return true;
            }
            return false;

        } catch (Exception $e) {
            error_log('TMIP: Exception cleaning up old post stats - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cleans up old daily stats records
     */
    private function cleanup_old_daily_stats($days) {
        global $wpdb;
        
        try {
            $date = date('Y-m-d', strtotime("-$days days"));
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tmip_lc_daily_stats 
                 WHERE stat_date < %s",
                $date
            ));

            if ($result !== false) {
                error_log("TMIP: Cleaned up $result old daily stat records");
                return true;
            }
            return false;

        } catch (Exception $e) {
            error_log('TMIP: Exception cleaning up old daily stats - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cleans up orphaned records
     */
    private function cleanup_orphaned_records() {
        global $wpdb;
        
        try {
            // Delete views for non-existent posts
            $deleted = $wpdb->query("
                DELETE v 
                FROM {$wpdb->prefix}tmip_lc_views v 
                LEFT JOIN {$wpdb->posts} p ON v.post_id = p.ID 
                WHERE p.ID IS NULL
            ");

            if ($deleted !== false) {
                error_log("TMIP: Cleaned up $deleted orphaned view records");
            }

            // Delete post stats for non-existent posts
            $deleted = $wpdb->query("
                DELETE s 
                FROM {$wpdb->prefix}tmip_lc_post_stats s 
                LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID 
                WHERE p.ID IS NULL
            ");

            if ($deleted !== false) {
                error_log("TMIP: Cleaned up $deleted orphaned post stat records");
            }

            return true;

        } catch (Exception $e) {
            error_log('TMIP: Exception cleaning up orphaned records - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Optimizes database tables
     */
    private function optimize_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . $this->db_prefix . 'views',
			$wpdb->prefix . $this->db_prefix . 'post_stats',
			$wpdb->prefix . $this->db_prefix . 'daily_stats'
		);

		foreach ($tables as $table) {
			$wpdb->query("OPTIMIZE TABLE $table");
			if (defined('TMIP_UF_DEBUG') && TMIP_UF_DEBUG) {
				error_log("TMIP: Optimized table $table");
			}
		}
	}

}

// Initialize the cleanup class
TMIP_Local_Stats_Cleanup::init();
