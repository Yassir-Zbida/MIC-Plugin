/**
 * Made in China App Sync - Admin JavaScript
 * Professional JavaScript for WordPress admin interface
 */

(function($) {
    'use strict';

    /**
     * Main Admin Object
     */
    const MICAdmin = {
        
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.initConnectionTest();
            this.initLogsPage();
            this.initAnalyticsPage();
            this.initSKUValidation();
        },

        /**
         * FIXED: Enhanced event binding with proper scope and new button types
         */
        bindEvents: function() {
            const self = this;
            
            // Basic connectivity test button
            $(document).on('click', '#basic-test-btn', function(e) { 
                e.preventDefault(); 
                self.basicConnectivityTest(e); 
            });
            
            // Connection test button
            $(document).on('click', '#test-btn', function(e) { 
                e.preventDefault(); 
                self.testConnection(e); 
            });
            
            // Clear logs button
            $(document).on('click', '#clear-logs-btn', function(e) { 
                e.preventDefault(); 
                self.showClearLogsDialog(e); 
            });
            
            // Manual sync button (legacy)
            $(document).on('click', '.woo-mic-order-btn', function(e) { 
                e.preventDefault(); 
                self.manualSync(e); 
            });
            
            // FIXED: Enhanced sync button handlers with proper differentiation
            
            // Regular sync buttons (for never-synced orders)
            $(document).on('click', '.mic-sync-btn:not(.mic-resync-btn):not(.mic-retry-btn)', function(e) { 
                e.preventDefault(); 
                self.syncOrder.call(self, e); 
            });
            
            // Resync buttons (for already synced orders) - without log ID
            $(document).on('click', '.mic-resync-btn:not([data-log-id])', function(e) { 
                e.preventDefault(); 
                self.resyncOrder.call(self, e); 
            });
            
            // Retry buttons for failed orders (in order meta boxes and orders list) - without log ID
            $(document).on('click', '.mic-retry-btn:not([data-log-id])', function(e) { 
                e.preventDefault(); 
                self.retryOrderSync.call(self, e); 
            });
            
            // Retry buttons in logs table - WITH log ID
            $(document).on('click', '.mic-retry-btn[data-log-id]', function(e) { 
                e.preventDefault(); 
                self.retryFromLog.call(self, e); 
            });
            
            // Orders list sync buttons
            $(document).on('click', '.mic-sync-now-btn', function(e) {
                e.preventDefault();
                self.syncOrder.call(self, e);
            });
            
            // FIXED: Enhanced expandable content handler for better clicking
            $(document).on('click', '.mic-expandable', function(e) { 
                e.preventDefault(); 
                e.stopPropagation();
                self.toggleExpanded.call(self, e); 
            });
            
            // FIXED: Also handle clicks on expandable icons that may not be wrapped in .mic-expandable
            $(document).on('click', '.mic-expanded, .ri-eye-line', function(e) {
                // Check if this is inside a .mic-expandable element already
                if (!$(e.target).closest('.mic-expandable').length) {
                    e.preventDefault(); 
                    e.stopPropagation();
                    self.toggleExpanded.call(self, e); 
                }
            });
        },

        /**
         * Initialize connection test functionality
         */
        initConnectionTest: function() {
            // Connection test is handled by the testConnection method
        },

        /**
         * Initialize logs page functionality
         */
        initLogsPage: function() {
            // Logs page functionality is handled by showClearLogsDialog method
        },

        /**
         * Initialize analytics page functionality
         */
        initAnalyticsPage: function() {
            this.animateNumbers();
        },

        /**
         * Initialize SKU validation functionality
         */
        initSKUValidation: function() {
            if ($('#_sku').length) {
                this.setupSKUValidation();
            }
        },

        /**
         * Basic connectivity test to Laravel app
         */
        basicConnectivityTest: function(e) {
            const button = $('#basic-test-btn');
            const resultDiv = $('#basic-test-result');
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Testing basic connectivity...');
            resultDiv.show();
            resultDiv.removeClass('mic-test-success mic-test-error');
            resultDiv.html('<i class="ri-loader-4-line ri-spin"></i> Testing basic connectivity...');
            
            $.post(ajaxurl, {
                action: 'mic_basic_test',
                nonce: micStrings.nonce
            })
            .done(function(response) {
                if (response.success) {
                    resultDiv.removeClass('mic-test-error').addClass('mic-test-success');
                    resultDiv.html('<i class="ri-check-line"></i> ' + response.data);
                } else {
                    resultDiv.removeClass('mic-test-success').addClass('mic-test-error');
                    resultDiv.html('<i class="ri-error-warning-line"></i> ' + response.data);
                }
            })
            .fail(function(xhr, status, error) {
                resultDiv.removeClass('mic-test-success').addClass('mic-test-error');
                resultDiv.html('<i class="ri-error-warning-line"></i> Basic connectivity test failed: ' + error);
            })
            .always(function() {
                button.prop('disabled', false);
                button.html('<i class="ri-link"></i> Basic Connectivity Test');
            });
        },
        
        /**
         * Test connection to Laravel app
         */
        testConnection: function(e) {
            const button = $('#test-btn');
            const resultDiv = $('#test-result');
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> ' + micStrings.testing);
            resultDiv.show();
            resultDiv.removeClass('mic-test-success mic-test-error');
            resultDiv.html('<i class="ri-loader-4-line ri-spin"></i> ' + micStrings.testingConnection);
            
            $.post(ajaxurl, {
                action: 'mic_test_connection',
                nonce: micStrings.nonce
            })
            .done(function(response) {
                if (response.success) {
                    resultDiv.removeClass('mic-test-error').addClass('mic-test-success');
                    resultDiv.html('<i class="ri-check-line"></i> ' + response.data.message);
                } else {
                    resultDiv.removeClass('mic-test-success').addClass('mic-test-error');
                    resultDiv.html('<i class="ri-error-warning-line"></i> ' + response.data);
                }
            })
            .fail(function(xhr, status, error) {
                resultDiv.removeClass('mic-test-success').addClass('mic-test-error');
                resultDiv.html('<i class="ri-error-warning-line"></i> ' + micStrings.connectionError + ' ' + error);
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(micStrings.testConnection);
            });
        },

        /**
         * Show clear logs confirmation dialog
         */
        showClearLogsDialog: function(e) {
            const days = prompt(micLogsStrings.enterDays, '30');
            if (days === null) return;
            
            const daysNum = parseInt(days);
            if (isNaN(daysNum) || daysNum < 0) {
                alert(micLogsStrings.validNumber);
                return;
            }
            
            let confirmMessage;
            if (daysNum === 0) {
                confirmMessage = micLogsStrings.clearAllConfirm;
            } else {
                confirmMessage = micLogsStrings.clearOldConfirm.replace('%d', daysNum);
            }
            
            if (confirm(confirmMessage)) {
                this.clearLogs(daysNum);
            }
        },

        /**
         * Clear logs based on days parameter
         */
        clearLogs: function(days) {
            const button = $('#clear-logs-btn');
            const originalText = button.html();
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Clearing...');
            
            $.post(ajaxurl, {
                action: 'mic_clear_logs',
                days: days,
                nonce: micLogsStrings.nonce
            })
            .done(function(response) {
                if (response.success) {
                    MICUtils.showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    MICUtils.showNotification(micLogsStrings.error + ' ' + response.data, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                MICUtils.showNotification(micLogsStrings.errorClearing + ' ' + error, 'error');
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * Manual sync from order page (legacy method)
         */
        manualSync: function(e) {
            const button = $(e.target);
            const orderId = button.data('order-id');
            
            if (!confirm(micStrings.manualSyncConfirm)) {
                return;
            }
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> ' + micStrings.willSync);
            
            // Redirect to manual sync URL
            window.location.href = micStrings.manualSyncUrl + orderId;
        },

        /**
         * FIXED: Sync order for never-synced orders
         */
        syncOrder: function(e) {
            console.log('Sync order button clicked');
            
            const button = $(e.target).closest('.mic-sync-btn');
            let orderId = button.data('order-id');
            const originalText = button.html();
            const isInOrdersList = button.closest('.mic-sync-column-info').length > 0;
            
            // Fix for complex order ID data - extract just the numeric ID
            if (typeof orderId === 'object' && orderId.id) {
                orderId = orderId.id;
            } else if (typeof orderId === 'string' && orderId.includes('{')) {
                try {
                    const orderData = JSON.parse(orderId);
                    orderId = orderData.id;
                } catch (e) {
                    console.error('Could not parse order ID:', orderId);
                    return;
                }
            }
            
            console.log('Sync order - Order ID:', orderId, 'In orders list:', isInOrdersList);
            
            // No confirmation needed - sync silently
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Syncing...');
            
            $.post(ajaxurl, {
                action: 'mic_sync_order',
                order_id: orderId,
                nonce: micStrings.syncOrderNonce
            })
            .done(function(response) {
                console.log('Sync order response:', response);
                // No notification - just reload to show updated status
                location.reload();
            })
            .fail(function(xhr, status, error) {
                console.error('Sync order AJAX failed:', {xhr, status, error});
                // No notification - just reload to show updated status
                location.reload();
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * FIXED: Resync order for already synced orders
         */
        resyncOrder: function(e) {
            console.log('Resync order button clicked');
            
            const button = $(e.target).closest('.mic-resync-btn');
            const orderId = button.data('order-id');
            const originalText = button.html();
            const isInOrdersList = button.closest('.mic-sync-column-info').length > 0;
            
            console.log('Resync order - Order ID:', orderId, 'In orders list:', isInOrdersList);
            
            // No confirmation needed - resync silently
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Resyncing...');
            
            $.post(ajaxurl, {
                action: 'mic_sync_order',
                order_id: orderId,
                nonce: micStrings.syncOrderNonce
            })
            .done(function(response) {
                console.log('Resync order response:', response);
                // No notification - just reload to show updated status
                location.reload();
            })
            .fail(function(xhr, status, error) {
                console.error('Resync order AJAX failed:', {xhr, status, error});
                // No notification - just reload to show updated status
                location.reload();
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * FIXED: NEW - Retry order sync for failed orders (from order meta box or orders list)
         */
        retryOrderSync: function(e) {
            console.log('Retry order sync button clicked');
            
            const button = $(e.target).closest('.mic-retry-btn');
            let orderId = button.data('order-id');
            const originalText = button.html();
            const isInOrdersList = button.closest('.mic-sync-column-info').length > 0;
            
            // Fix for complex order ID data - extract just the numeric ID
            if (typeof orderId === 'object' && orderId.id) {
                orderId = orderId.id;
            } else if (typeof orderId === 'string' && orderId.includes('{')) {
                try {
                    const orderData = JSON.parse(orderId);
                    orderId = orderData.id;
                } catch (e) {
                    console.error('Could not parse order ID:', orderId);
                    return;
                }
            }
            
            console.log('Retry order sync - Order ID:', orderId, 'In orders list:', isInOrdersList);
            
            // No confirmation needed - retry silently
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> ' + (micStrings.retrying || 'Retrying...'));
            
            $.post(ajaxurl, {
                action: 'mic_sync_order', // Use same action as regular sync
                order_id: orderId,
                nonce: micStrings.syncOrderNonce
            })
            .done(function(response) {
                console.log('Retry order sync response:', response);
                // No notification - just reload to show updated status
                location.reload();
            })
            .fail(function(xhr, status, error) {
                console.error('Retry order sync AJAX failed:', {xhr, status, error});
                // No notification - just reload to show updated status
                location.reload();
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * FIXED: Retry failed sync from logs table (with log ID)
         */
        retryFromLog: function(e) {
            console.log('Retry from log button clicked');
            
            const button = $(e.target).closest('.mic-retry-btn');
            const orderId = button.data('order-id');
            const logId = button.data('log-id');
            const originalText = button.html();
            
            console.log('Retry from log - Order ID:', orderId, 'Log ID:', logId);
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Retrying...');
            
            $.post(ajaxurl, {
                action: 'mic_retry_sync',
                order_id: orderId,
                log_id: logId,
                nonce: micStrings.retrySyncNonce
            })
            .done(function(response) {
                console.log('Retry from log response:', response);
                // No notification - just reload to show updated status
                location.reload();
            })
            .fail(function(xhr, status, error) {
                console.error('Retry from log AJAX failed:', {xhr, status, error});
                // No notification - just reload to show updated status
                location.reload();
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * FIXED: Enhanced toggle expandable content with better event handling
         */
        toggleExpanded: function(e) {
            console.log('Toggle expandable clicked:', e.target);
            
            // Handle clicks on the expandable element or its children
            const element = $(e.currentTarget);
            
            // FIXED: Look for content in multiple ways since it might be structured differently
            let content = element.next('.mic-expandable-content');
            
            // If not found directly next, look in the same table cell
            if (content.length === 0) {
                content = element.closest('td').find('.mic-expandable-content');
            }
            
            // If still not found, look in parent container
            if (content.length === 0) {
                content = element.parent().find('.mic-expandable-content');
            }
            
            // If still not found, look for siblings
            if (content.length === 0) {
                content = element.siblings('.mic-expandable-content');
            }
            
            console.log('Element:', element);
            console.log('Content found:', content.length);
            console.log('Content visible:', content.is(':visible'));
            
            if (content.length > 0) {
                if (content.is(':visible')) {
                    content.slideUp(200);
                    element.removeClass('mic-expanded');
                    console.log('Content hidden');
                } else {
                    // Hide other open expandable content in the same table
                    element.closest('table').find('.mic-expandable-content:visible').slideUp(200);
                    element.closest('table').find('.mic-expandable').removeClass('mic-expanded');
                    
                    // Show this content
                    content.slideDown(200);
                    element.addClass('mic-expanded');
                    console.log('Content shown');
                }
            } else {
                console.log('No expandable content found for this element');
            }
        },

        /**
         * Animate numbers on analytics page
         */
        animateNumbers: function() {
            $('.mic-stat-value').each(function() {
                const element = $(this);
                const finalValue = parseInt(element.text().replace(/[^\d]/g, ''));
                
                if (!isNaN(finalValue)) {
                    element.prop('Counter', 0).animate({
                        Counter: finalValue
                    }, {
                        duration: 1000,
                        easing: 'swing',
                        step: function(now) {
                            element.text(Math.floor(now).toLocaleString());
                        }
                    });
                }
            });
        },

        /**
         * Setup SKU validation
         */
        setupSKUValidation: function() {
            const skuField = $('#_sku');
            const productForm = $('form#post');
            
            productForm.on('submit', function(e) {
                const skuValue = skuField.val().trim();
                
                if (!skuValue) {
                    if (!confirm(micStrings.skuWarning)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        },

        /**
         * FIXED: Enhanced order sync status update for orders list
         */
        updateOrderSyncStatus: function(orderId, isSynced, syncTime) {
            // Find the order row in both legacy and HPOS tables
            let row = $(`tr[data-order-id="${orderId}"]`);
            if (row.length === 0) {
                // Try alternative selectors for HPOS
                row = $(`tr:has(a[href*="id=${orderId}"])`);
            }
            if (row.length === 0) {
                // Try finding by order ID in the first column
                row = $(`tr:contains("#${orderId}")`).first();
            }
            
            if (row.length) {
                const syncStatusCell = row.find('.column-mic_sync_status, td.mic_sync_status');
                if (syncStatusCell.length) {
                    if (isSynced) {
                        // Order is now synced - show synced status with resync button
                        syncStatusCell.html(`
                            <div class="mic-sync-column-info">
                                <div class="mic-sync-status mic-synced">
                                    <span class="mic-sync-badge mic-synced-badge">
                                        <i class="ri-check-line"></i> ${micStrings.synced}
                                    </span>
                                    <br><small class="mic-sync-time">${MICUtils.formatDate(syncTime)}</small>
                                </div>
                                <button type="button" class="mic-sync-btn mic-resync-btn" data-order-id="${orderId}" title="Resync Order">
                                    <i class="ri-refresh-line"></i>
                                </button>
                            </div>
                        `);
                    } else {
                        // Order sync failed - show not synced with retry button
                        syncStatusCell.html(`
                            <div class="mic-sync-column-info">
                                <div class="mic-sync-status mic-not-synced">
                                    <span class="mic-sync-badge mic-not-synced-badge">
                                        <i class="ri-close-line"></i> ${micStrings.notSynced}
                                    </span>
                                </div>
                                <button type="button" class="mic-sync-btn mic-retry-btn" data-order-id="${orderId}" title="Retry Sync">
                                    <i class="ri-refresh-line"></i>
                                </button>
                            </div>
                        `);
                    }
                }
            }
        }
    };

    /**
     * Utility Functions
     */
    const MICUtils = {
        
        /**
         * Format number with locale
         */
        formatNumber: function(num) {
            return num.toLocaleString();
        },

        /**
         * Format date
         */
        formatDate: function(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },

        /**
         * Show loading state
         */
        showLoading: function(element) {
            element.addClass('mic-loading');
        },

        /**
         * Hide loading state
         */
        hideLoading: function(element) {
            element.removeClass('mic-loading');
        },

        /**
         * ENHANCED: Show notification with better styling
         */
        showNotification: function(message, type) {
            type = type || 'info';
            const iconClass = type === 'success' ? 'ri-checkbox-circle-line' : 
                             type === 'error' ? 'ri-error-warning-line' : 
                             'ri-information-line';
            
            const notification = $(`
                <div class="notice notice-${type} is-dismissible" style="position: relative; z-index: 9999;">
                    <p><i class="${iconClass}"></i> ${message}</p>
                </div>
            `);
            
            // Insert at the top of the page
            $('.wrap').first().prepend(notification);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Manual dismiss button
            notification.find('.notice-dismiss').on('click', function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },

        /**
         * Debounce function for performance
         */
        debounce: function(func, wait, immediate) {
            let timeout;
            return function() {
                const context = this, args = arguments;
                const later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        },

        /**
         * Check if element is in viewport
         */
        isInViewport: function(element) {
            const rect = element.getBoundingClientRect();
            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );
        }
    };

    /**
     * FIXED: Enhanced initialization with proper scope handling
     */
    $(document).ready(function() {
        console.log('MIC Admin initializing...');
        
        // Initialize the main admin object
        MICAdmin.init();
        
        // Add global reference for debugging
        window.MICAdmin = MICAdmin;
        window.MICUtils = MICUtils;
        
        // ENHANCED: Add some quality of life improvements
        
        // Auto-focus on first input in forms
        $('.mic-card form:first input[type="text"]:first, .mic-card form:first input[type="url"]:first').focus();
        
        // Add loading states to AJAX forms only (not settings forms which submit to server)
        $('form').not('.mic-settings-form').on('submit', function() {
            const submitButton = $(this).find('button[type="submit"], input[type="submit"]');
            if (submitButton.length) {
                const originalText = submitButton.html() || submitButton.val();
                submitButton.prop('disabled', true);
                if (submitButton.is('button')) {
                    submitButton.html('<i class="ri-loader-4-line ri-spin"></i> Saving...');
                }
                
                // Re-enable after 5 seconds as fallback
                setTimeout(function() {
                    submitButton.prop('disabled', false);
                    if (submitButton.is('button')) {
                        submitButton.html(originalText);
                    }
                }, 5000);
            }
        });
        
        // Enhanced table row hover effects
        $('.mic-logs-table tbody tr').hover(
            function() {
                $(this).addClass('mic-row-hover');
            },
            function() {
                $(this).removeClass('mic-row-hover');
            }
        );
        
        // Add tooltips to buttons
        $('[title]').each(function() {
            const $this = $(this);
            if (!$this.attr('data-tooltip-added')) {
                $this.attr('data-tooltip-added', 'true');
                // You can add a tooltip library here if needed
            }
        });
        
        console.log('MIC Admin initialization complete');
    });

    /**
     * Handle page unload for unsaved changes
     */
    $(window).on('beforeunload', function() {
        // Check for any ongoing AJAX requests
        if ($.active > 0) {
            return 'There are pending operations. Are you sure you want to leave?';
        }
    });

    /**
     * Keyboard shortcuts
     */
    $(document).keydown(function(e) {
        // Ctrl+S to save forms
        if ((e.ctrlKey || e.metaKey) && e.which === 83) {
            e.preventDefault();
            const visibleForm = $('.mic-card form:visible').first();
            if (visibleForm.length) {
                visibleForm.submit();
                return false;
            }
        }
        
        // Escape to close modals/expandable content
        if (e.which === 27) {
            $('.mic-expandable-content:visible').slideUp();
            $('.mic-expandable').removeClass('mic-expanded');
        }
    });

})(jQuery);