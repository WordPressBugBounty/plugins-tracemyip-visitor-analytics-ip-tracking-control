/* TraceMyIP > UnFiltered Stats */

// Debug flag
const DEBUG = false;

// ============================================================================ //
function debugLog(message, data = null) {
    if (DEBUG) {
        if (data !== null) {
            console.log('TMIP Debug:', message, data);
            if (typeof data === 'object' && data !== null) {
                console.log('TMIP Debug Details:', JSON.stringify(data, null, 2));
            }
        } else {
            console.log('TMIP Debug:', message);
        }
    }
}

// ============================================================================ //
// Global loader function
function tmipToggleLoader(show) {
    jQuery('.tmip-loader-overlay').css('display', show ? 'flex' : 'none');
    debugLog('Loader: ' + (show ? 'shown' : 'hidden'));
}

// Timezone
jQuery(document).ready(function($) {
    function toggleCustomTimezone() {
        var selected = $('#tmip_lc_timezone_setting').val();
        $('#tmip_lc_custom_timezone').closest('tr').toggle(selected === 'custom');
    }
    $('#tmip_lc_timezone_setting').on('change', toggleCustomTimezone);
    toggleCustomTimezone();
});

// Notice helper 
function tmipShowNotice($notice) {
    if (!$notice.length) return;

    // Ensure notice is visible but start transparent
    $notice
        .css({
            'display': 'block',
            'opacity': '0'
        })
        .hide();

    // Fade in the notice
    $notice.fadeIn(500, function() {
        $(this).addClass('is-visible');
    });

    // Setup dismiss button behavior
    $notice.find('.notice-dismiss').on('click', function() {
        $notice.fadeOut(300, function() {
            $notice.remove();
        });
    });
}



// ============================================================================ //
// Consolidated jQuery ready handler
jQuery(document).ready(function($) {
    
    // Notice handler
    let noticeShown = false;

	function handleNotices() {
		const urlParams = new URLSearchParams(window.location.search);
		const status = urlParams.get('status');
		const action = urlParams.get('action');

		// Hide loader immediately when handling notices
		tmipToggleLoader(false);

		// Check for WordPress's default "settings saved" notice
		const hasSettingsSaved = $('#setting-error-settings_updated').length > 0;

		// Only handle notice if we haven't shown one yet, have status, and no settings saved notice exists
		if (!noticeShown && status && !hasSettingsSaved) {
			let message = '';
			let type = status === 'success' ? 'updated' : 'error';

			// Generate message based on action and status
			switch (action) {
				case 'delete_old_data':
					const days = urlParams.get('days') || 30;
					message = `${days} days data purge is complete`;
					break;
				case 'delete_all_data':
					message = 'All data has been deleted and tables have been recreated.';
					break;
				case 'reset_settings':
					message = 'Settings have been reset to defaults.';
					break;
				default:
					// Don't create a duplicate "Settings saved" message
					if (hasSettingsSaved) {
						return;
					}
					message = status === 'success' ? 
						'Operation completed successfully.' : 
						'Operation failed. Please try again.';
			}

			// Create and insert notice
			const $notice = $(`
				<div class="notice notice-${type} is-dismissible">
					<p><strong>${message}</strong></p>
					<button type="button" class="notice-dismiss">
						<span class="screen-reader-text">Dismiss this notice.</span>
					</button>
				</div>
			`);

			$('.wrap > h1').after($notice);
			noticeShown = true;

			// Remove status and action from URL without refreshing
			const newUrl = removeUrlParameters(['status', 'action', 'days']);
			window.history.replaceState({}, '', newUrl);
		}
	}

    // Helper function to remove specific parameters from URL
    function removeUrlParameters(paramsToRemove) {
        const url = new URL(window.location.href);
        paramsToRemove.forEach(param => url.searchParams.delete(param));
        return url.toString();
    }

    // Handle notices on page load
    handleNotices();

    // Hide loader when page is fully loaded
    $(window).on('load', function() {
        debugLog('Window loaded');
        tmipToggleLoader(false);
    });

    // Monitor loader state changes
    $('.tmip-loader-overlay').on('show hide', function(e) {
        debugLog('Loader ' + e.type);
    });

    // AJAX error handler
    $(document).ajaxError(function(event, jqxhr, settings, error) {
        debugLog('AJAX Error: ' + error);
        tmipToggleLoader(false);
    });
});


// ============================================================================ //
jQuery(document).ready(function($) {
    
    // Plugin deletion confirmation
    $(document).on('click', '[data-plugin="tracemyip-local-stats/tracemyip-local-stats.php"] .delete a', function(e) {
        if (!confirm('Are you sure you want to delete this plugin?')) {
            e.preventDefault();
        }
    });

    // Tab navigation with loader
    $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        debugLog('Tab clicked');

        // Show loader immediately
        tmipToggleLoader(true);

        // Get the target tab and navigate
        const href = $(this).attr('href');
        debugLog('Navigating to: ' + href);
        window.location.href = href;
    });

    // Form submissions
    $('form').on('submit', function(e) {
        debugLog('Form submitted');
        tmipToggleLoader(true);
    });


	// Maintenance form handling
	$('.tmip-maintenance-form').on('submit', function(e) {
		// Show loader
		tmipToggleLoader(true);

		// Clear any existing notices
		$('.tmip-admin-notice, .settings-error').remove();

		// Don't prevent form submission
		return true;
	});


    // Update the confirmation messages
    $('.tmip-maintenance-form button[type="submit"]').on('click', function(e) {
        const action = $(this).val();
        let confirmMessage = '';

        switch(action) {
            case 'delete_all_data':
                confirmMessage = 'Are you sure you want to delete all tracking data? This will:\n\n' +
                   '• Delete all visitor tracking data\n' +
                   '• Reset the dashboard widget position\n' +
                   '• Recreate empty tracking tables\n\n' +
                   'Your plugin settings will be preserved.\n\n' +
                   'This action cannot be undone!\n\n' +
                   'Click OK to proceed and wait for confirmation message.';
				break;

            case 'reset_settings':
                confirmMessage = 'Are you sure you want to reset all settings to their defaults? This will:\n\n' +
                               '• Reset all settings to default values\n' +
                               '• Reset the dashboard widget position\n\n' +
                               'This action cannot be undone!\n\n' +
                               'Click OK to proceed and wait for confirmation message.';
                break;

            case 'delete_old_data':
                const days = $(this).closest('form').find('input[name="days_to_keep"]').val();
				// Validate days value
				if (isNaN(days) || days < 1) {
					e.preventDefault();
					alert('Please enter a valid number of days (minimum 1)');
					return false;
				}				
                confirmMessage = `Are you sure you want to delete all data older than ${days} days?\n\n` +
                               'This will permanently remove old tracking data while keeping recent records.\n\n' +
                               'Click OK to proceed and wait for confirmation message.';
                break;
        }

        if (confirmMessage && !confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }

        tmipToggleLoader(true);
    });
    
    
    $('.tmip-maintenance-form input[type="submit"]').on('click', function(e) {
        const action = $(this).val();
        debugLog('Maintenance action: ' + action);

        if (action === 'delete_all_data') {
            if (!confirm('Are you sure you want to delete all data and tables? The tables will be recreated empty. This cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        } else if (action === 'reset_settings') {
            if (!confirm('Are you sure you want to reset all settings to defaults?')) {
                e.preventDefault();
                return false;
            }
        }
        
        tmipToggleLoader(true);
    });
	
    // Storage handler for visitor identification
    const tmip_lc_storage = {
		generateVisitorId: function() {
			const newId = 'tmip_lc_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
			debugLog('TMIP Debug - Generated new visitor ID:', newId);
			return newId;
		},

		getVisitorId: function() {
			const storageMethod = tmipLocalStats.storageMethod || 'cookies';
			const key = 'tmip_lc_visitor_id';
			let visitorId;

			if (storageMethod === 'cookies') {
				visitorId = this.getCookie(key);
				debugLog('TMIP Debug - Cookie visitor ID:', visitorId);
				if (!visitorId) {
					visitorId = this.generateVisitorId();
					this.setCookie(key, visitorId, 730); // 2 year expiry
					debugLog('TMIP Debug - Set new cookie visitor ID:', visitorId);
				}
			} else {
				try {
					visitorId = localStorage.getItem(key);
					debugLog('TMIP Debug - LocalStorage visitor ID:', visitorId);
					if (!visitorId) {
						visitorId = this.generateVisitorId();
						localStorage.setItem(key, visitorId);
						debugLog('TMIP Debug - Set new localStorage visitor ID:', visitorId);
					}
				} catch (e) {
					debugLog('TMIP Debug - Set new cookie visitor ID:', e);
					visitorId = this.generateVisitorId();
				}
			}
			return visitorId;
		},

		setCookie: function(name, value, days) {
			const d = new Date();
			d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
			const expires = "expires=" + d.toUTCString();
			const domain = window.location.hostname;
			document.cookie = `${name}=${value};${expires};path=/;domain=${domain};SameSite=Lax`;
			debugLog('TMIP Debug - Set cookie:', { name, value, expires, domain });
		},

		getCookie: function(name) {
			const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
			const value = match ? match[2] : null;
			debugLog('TMIP Debug - Get cookie:', { name, value });
			return value;
		}
};

    // View tracking with retry
    if (typeof tmipLocalStats !== 'undefined' && tmipLocalStats.post_id > 0) {
        const maxRetries = 2;
        let retryCount = 0;
		debugLog('tmipLocalStats: '+tmipLocalStats);

		// Modified tracking function
		function tmip_lc_trackView() {
			// Skip if in admin
			if (document.body.classList.contains('wp-admin')) {
				if (DEBUG) { debugLog("TMIP Debug: In wp-admin, skipping trackView"); }
				return;
			}

			const postId = tmipLocalStats.post_id;
			const globalStorageKey = 'tmip_lc_global_last_visit';
			const visitorId = tmip_lc_storage.getVisitorId();

			debugLog('TMIP Debug - Track View Init:', {
				postId: postId,
				globalStorageKey: globalStorageKey,
				visitorId: visitorId
			});

			if (!visitorId) {
				visitorId = tmip_lc_storage.generateVisitorId();
				debugLog('TMIP Debug - No visitor ID found, generating new one:', visitorId);
			}

			const storageMethod = tmipLocalStats.storageMethod || 'cookies';
			debugLog('TMIP storageMethod:', storageMethod);

			// Get global last visit time
			let lastVisitTime = null;

			if (storageMethod === 'cookies') {
				const globalLastVisit = tmip_lc_storage.getCookie(globalStorageKey);

				debugLog('TMIP Debug - Raw cookie value:', {
					key: globalStorageKey,
					value: globalLastVisit
				});

				if (globalLastVisit) {
					lastVisitTime = decodeURIComponent(globalLastVisit);
					debugLog('TMIP Debug - Decoded global last visit time:', lastVisitTime);
				} else {
					debugLog('TMIP Debug - No previous visit time found');
				}
			}

			const currentTime = new Date().toUTCString();
			const timezone_offset = new Date().getTimezoneOffset();

			debugLog('TMIP Debug - Time values:', {
				currentTime: currentTime,
				timezone_offset: timezone_offset,
				lastVisitTime: lastVisitTime,
				decoded: lastVisitTime ? decodeURIComponent(lastVisitTime) : null
			});

			// Add cache buster to URL
			const cacheBuster = new Date().getTime();
			const ajaxUrl = `${tmipLocalStats.ajaxurl}?nocache=${cacheBuster}`;

			// Debug log the full request
			debugLog('TMIP Debug - Preparing AJAX request:', {
				url: ajaxUrl,
				postId: postId,
				visitorId: visitorId,
				lastVisitTime: lastVisitTime,
				timezone_offset: timezone_offset,
				security: tmipLocalStats.nonce
			});

			// Track the view
			jQuery.ajax({
				type: "POST",
				url: ajaxUrl,
				data: {
					action: 'tmip_record_view',
					post_id: postId,
					security: tmipLocalStats.nonce,
					visitor_id: visitorId,
					last_visit_time: lastVisitTime,
					timezone_offset: timezone_offset
				},
				headers: {
					'Cache-Control': 'no-cache, no-store, must-revalidate',
					'Pragma': 'no-cache',
					'Expires': '0'
				},
				success: function(response) {
					debugLog('TMIP Debug - AJAX Response:', response);

					if (response.success) {
						// Update global cookie only
						if (storageMethod === 'cookies') {
							tmip_lc_storage.setCookie(globalStorageKey, currentTime, 1);

							debugLog('TMIP Debug - Updated global cookie:', {
								key: globalStorageKey,
								value: currentTime,
								raw: document.cookie
							});

							// Verify cookie was set
							const verifyValue = tmip_lc_storage.getCookie(globalStorageKey);
							debugLog('TMIP Debug - Cookie verification:', {
								set: currentTime,
								got: verifyValue,
								decoded: verifyValue ? decodeURIComponent(verifyValue) : null
							});
						}

						debugLog('TMIP Debug - View tracked successfully:', {
							response: response,
							currentTime: currentTime
						});
					} else {
						debugLog('TMIP Debug - View tracking failed:', {
							response: response,
							error: response.data?.message || 'Unknown error'
						});
					}
				},
				error: function(xhr, status, error) {
					debugLog('TMIP Debug - AJAX Error:', {
						status: status,
						error: error,
						xhr: xhr
					});

					if (xhr.status === 0) {
						debugLog('TMIP Debug - Network Error:', 'Possible adblocker or connection issue');
						return;
					}

					// Detailed error logging
					if (xhr.responseJSON && xhr.responseJSON.data) {
						debugLog('TMIP Debug - Server Error (JSON):', xhr.responseJSON.data);
					} else if (xhr.responseText) {
						debugLog('TMIP Debug - Server Error (Text):', xhr.responseText);
						try {
							const parsedError = JSON.parse(xhr.responseText);
							debugLog('TMIP Debug - Parsed Error:', parsedError);
						} catch (e) {
							debugLog('TMIP Debug - Raw Error Text:', xhr.responseText);
						}
					}
				},
				complete: function(xhr, status) {
					debugLog('TMIP Debug - Request completed:', {
						status: status,
						responseHeaders: xhr.getAllResponseHeaders(),
						cookies: document.cookie
					});
				}
			});

			// Add retry mechanism
			let retryCount = 0;
			const maxRetries = 2;
			const retryDelay = 1000; // 1 second

			function retryRequest() {
				if (retryCount < maxRetries) {
					retryCount++;
					debugLog(`TMIP Debug - Retrying request (attempt ${retryCount})`);
					setTimeout(tmip_lc_trackView, retryDelay * retryCount);
				} else {
					debugLog('TMIP Debug - Max retry attempts reached:', retryCount);
				}
			}

			// Monitor network status
			window.addEventListener('online', function() {
				debugLog('TMIP Debug - Network connection restored');
				if (retryCount > 0 && retryCount < maxRetries) {
					retryRequest();
				}
			});

			window.addEventListener('offline', function() {
				debugLog('TMIP Debug - Network connection lost');
			});
		}

		// Cookie management helper
		const tmip_lc_storage = {
			generateVisitorId: function() {
				const newId = 'tmip_lc_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
				debugLog('TMIP Debug - Generated new visitor ID:', newId);
				return newId;
			},

			setCookie: function(name, value, days) {
				const d = new Date();
				d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
				const expires = "expires=" + d.toUTCString();
				const domain = window.location.hostname; // Ensure cookies are set for the correct domain
				document.cookie = `${name}=${value};${expires};path=/;domain=${domain};SameSite=Lax`;
				debugLog('TMIP Debug - Set cookie:', { name, value, expires, domain });
			},

			getCookie: function(name) {
				const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
				const value = match ? match[2] : null;
				debugLog('TMIP Debug - Get cookie:', { name, value });
				return value;
			},

			getVisitorId: function() {
				const storageMethod = 'cookies'; // Force cookie method for now
				const key = 'tmip_lc_visitor_id';
				let visitorId = this.getCookie(key);
				debugLog('TMIP Debug - Cookie visitor ID:', visitorId);
				if (!visitorId) {
					visitorId = this.generateVisitorId();
					this.setCookie(key, visitorId, 730); // 2 year expiry
					debugLog('TMIP Debug - Set new cookie visitor ID:', visitorId);
				}
				return visitorId;
			}
		};

		// Initialize tracking when document is ready
		jQuery(document).ready(function($) {
			if (typeof tmipLocalStats !== 'undefined' && tmipLocalStats.post_id > 0) {
				debugLog('TMIP Debug - Initializing view tracking');
				tmip_lc_trackView();
			} else {
				debugLog('TMIP Debug - Tracking not initialized:', {
					tmipLocalStats: typeof tmipLocalStats,
					postId: tmipLocalStats?.post_id
				});
			}
		});

		
    }

});
          


// ============================================================================ //
// Top content pagination
jQuery(document).ready(function($) {
    $(document).on('click', '.tmip-page-link:not(.disabled)', function(e) {
        e.preventDefault();
        
        const $link = $(this);
        const $wrapper = $link.closest('.tmip-top-content-wrapper');
        const contentType = $wrapper.data('content-type');
        const postType = $wrapper.data('post-type');
        const page = $link.data('page');
        const limit = $wrapper.data('limit');

        debugLog('Pagination clicked: ' + JSON.stringify({
            contentType: contentType,
            postType: postType,
            page: page,
            limit: limit
        }));

        // Add loading state
        $wrapper.addClass('loading');

        $.ajax({
            url: tmipLocalStats.ajaxurl,
            type: 'POST',
            data: {
                action: 'tmip_get_paginated_content',
                security: tmipLocalStats.paginationNonce,
                content_type: contentType,
                post_type: postType,
                page: page,
                limit: limit
            },
            success: function(response) {
                debugLog('Pagination response: ' + JSON.stringify(response));
                if (response.success) {
                    // Replace content
                    $wrapper.replaceWith(response.data.content);
                } else {
                    debugLog('Pagination failed - ' + response.data?.message);
                }
            },
            error: function(xhr, status, error) {
                debugLog('Pagination error - ' + error);
                debugLog('Status: ' + status);
                debugLog('Response: ' + xhr.responseText);
            },
            complete: function() {
                $wrapper.removeClass('loading');
            }
        });
    });
});


// ============================================================================ //
// Text Copy functionality
(function($) {
    // Only initialize on admin pages
    if (!document.body.classList.contains('wp-admin')) {
        return; // Exit if not on admin page
    }

    // Create tooltip element
    const $tooltip = $('<div id="tmip-copy-tooltip" class="tmip-copy-tooltip">Copied!</div>').appendTo('body');

    // Remove title attribute from .tmip-ip-value elements
    $(document).find('.tmip-ip-value').removeAttr('title');

    // Handle IP hover tooltip
    $(document).on('mouseenter', '.tmip-ip-value[data-ip]', function() {
        const fullIp = $(this).data('ip');
        const $this = $(this);

        $tooltip.text(fullIp); // Set tooltip text to full IP
        const rect = $this[0].getBoundingClientRect(); // Use $this[0] to get the DOM element
        $tooltip.css({
            top: (rect.top - 43) + 'px', // Position tooltip above IP
            left: (rect.left + (rect.width / 2)) + 'px',
            opacity: 0,
            display: 'block'
        }).animate({ opacity: 1 }, 50); // Show tooltip
    }).on('mouseleave', '.tmip-ip-value[data-ip]', function() {
        $tooltip.animate({ opacity: 0 }, 0, function() {
            $(this).hide(); // Hide tooltip on mouseleave
        });
    });

    // Handle all copy actions
    $(document).on('click', '.tmip-copy-share-link, .tmip-ip-value, .tmip-ip-action.tmip-copy-ip', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $this = $(this);
        let textToCopy;

        // Get the text to copy
        if ($this.hasClass('tmip-copy-share-link')) {
            textToCopy = 'https://wordpress.org/plugins/tracemyip-visitor-analytics-ip-tracking-control/';
        } else if ($this.hasClass('tmip-copy-ip')) {
            // Get IP from data attribute of closest IP value
            textToCopy = $this.closest('li').find('.tmip-ip-value').data('ip');
        } else if ($this.hasClass('tmip-ip-value')) {
            // Get IP from data attribute
            textToCopy = $this.data('ip');
        }

        // Only proceed if we have text to copy
        if (textToCopy) {
            // Copy text
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Hide any existing tooltip
                $tooltip.animate({ opacity: 0 }, 0, function() {
                    $(this).hide();
                });

                // Set tooltip text to "Copied"
                $tooltip.text('Copied');

                // Add orange background and black text styles
                $tooltip.css({
                    'background-color': 'orange',
                    'color': 'black',
                    'font-weight': '500',
                    'font-size': '1.2em',
                    'padding': '5px',
                    'border': '2px dashed #333'
                });

                // Position tooltip
                const rect = this.getBoundingClientRect();

                $tooltip.css({
                    top: (rect.top - 43) + 'px',
                    left: (rect.left + (rect.width / 2)) + 'px',
                    opacity: 0,
                    display: 'block'
                });

                // Show tooltip with animation
                $tooltip.animate({
                    opacity: 1
                }, 50);

                // Hide tooltip after delay
                setTimeout(() => {
                    $tooltip.animate({
                        opacity: 0
                    }, 50, function() {
                        $(this).hide();
                        // Remove orange background and black text styles
                        $(this).css({
                            'background-color': '',
                            'color': ''
                        });
                    });
                }, 1000);

                // Flash effect separately
                $this.addClass('tmip-copy-flash');
                setTimeout(() => {
                    $this.removeClass('tmip-copy-flash');
                }, 500);
            });
        }
    });
})(jQuery);



// ============================================================================ //
// IP Filtering functionality
jQuery(document).ready(function($) {
    // Add IP button handler
    $('#tmip-add-ip').on('click', function() {
        const $container = $('#tmip-exclude-ips-container');
        const $newInput = $('<div class="tmip-ip-input">' +
            '<input type="text" name="tmip_lc_exclude_ips[]" placeholder="e.g. 192.168.1.1" />' +
            '<button type="button" class="button tmip-remove-ip">Remove</button>' +
            '</div>');
        $container.append($newInput);
    });

    // Add Current IP button handler
    $('#tmip-add-current-ip').on('click', function() {
        $.ajax({
            url: tmipLocalStats.ajaxurl,
            type: 'POST',
            data: {
                action: 'tmip_get_current_ip',
                security: tmipLocalStats.nonce
            },
            success: function(response) {
                if (response.success && response.data.ip) {
                    const $container = $('#tmip-exclude-ips-container');
                    const $newInput = $('<div class="tmip-ip-input">' +
                        '<input type="text" name="tmip_lc_exclude_ips[]" value="' + response.data.ip + '" />' +
                        '<button type="button" class="button tmip-remove-ip">Remove</button>' +
                        '</div>');
                    $container.append($newInput);
                }
            }
        });
    });

    // Remove IP button handler
    $(document).on('click', '.tmip-remove-ip', function() {
        $(this).closest('.tmip-ip-input').remove();
    });
});

