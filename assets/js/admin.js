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
         * Bind event handlers - FIXED SCOPE ISSUE
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
            
            // Manual sync button
            $(document).on('click', '.woo-mic-order-btn', function(e) { 
                e.preventDefault(); 
                self.manualSync(e); 
            });
            
            // Order sync buttons (in order meta box)
            $(document).on('click', '.mic-sync-btn', function(e) { 
                e.preventDefault(); 
                self.syncOrder.call(self, e); 
            });
            
            $(document).on('click', '.mic-resync-btn:not([data-log-id])', function(e) { 
                e.preventDefault(); 
                self.resyncOrder.call(self, e); 
            });
            
            // Logs retry buttons - FIXED SELECTORS
            $(document).on('click', '.mic-retry-btn', function(e) { 
                e.preventDefault(); 
                self.retrySync.call(self, e); 
            });
            
            $(document).on('click', '.mic-resync-btn[data-log-id]', function(e) { 
                e.preventDefault(); 
                self.resyncFromLog.call(self, e); 
            });
            
            // Expandable content
            $(document).on('click', '.mic-expandable', function(e) { 
                e.preventDefault(); 
                self.toggleExpanded(e); 
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
         * Manual sync from order page
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
         * Sync order from order meta box
         */
        syncOrder: function(e) {
            console.log('Sync order button clicked');
            
            const button = $(e.target);
            const orderId = button.data('order-id');
            const originalText = button.html();
            
            console.log('Sync order - Order ID:', orderId);
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Syncing...');
            
            $.post(ajaxurl, {
                action: 'mic_sync_order',
                order_id: orderId,
                nonce: micStrings.syncOrderNonce
            })
            .done(function(response) {
                console.log('Sync order response:', response);
                if (response.success) {
                    MICUtils.showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    MICUtils.showNotification('Sync failed: ' + response.data, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('Sync order AJAX failed:', {xhr, status, error});
                MICUtils.showNotification('Sync failed: ' + error, 'error');
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * Resync order from order meta box
         */
        resyncOrder: function(e) {
            console.log('Resync order button clicked');
            
            const button = $(e.target);
            const orderId = button.data('order-id');
            const originalText = button.html();
            
            console.log('Resync order - Order ID:', orderId);
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Resyncing...');
            
            $.post(ajaxurl, {
                action: 'mic_sync_order',
                order_id: orderId,
                nonce: micStrings.syncOrderNonce
            })
            .done(function(response) {
                console.log('Resync order response:', response);
                if (response.success) {
                    MICUtils.showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    MICUtils.showNotification('Resync failed: ' + response.data, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('Resync order AJAX failed:', {xhr, status, error});
                MICUtils.showNotification('Resync failed: ' + error, 'error');
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * Retry failed sync from logs
         */
        retrySync: function(e) {
            console.log('Retry sync button clicked');
            
            const button = $(e.target);
            const orderId = button.data('order-id');
            const logId = button.data('log-id');
            const originalText = button.html();
            
            console.log('Retry sync - Order ID:', orderId, 'Log ID:', logId);
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Retrying...');
            
            $.post(ajaxurl, {
                action: 'mic_retry_sync',
                order_id: orderId,
                log_id: logId,
                nonce: micStrings.retrySyncNonce
            })
            .done(function(response) {
                console.log('Retry sync response:', response);
                if (response.success) {
                    MICUtils.showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    MICUtils.showNotification('Retry failed: ' + response.data, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('Retry sync AJAX failed:', {xhr, status, error});
                MICUtils.showNotification('Retry failed: ' + error, 'error');
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * Resync from logs
         */
        resyncFromLog: function(e) {
            console.log('Resync from log button clicked');
            
            const button = $(e.target);
            const orderId = button.data('order-id');
            const logId = button.data('log-id');
            const originalText = button.html();
            
            console.log('Resync from log - Order ID:', orderId, 'Log ID:', logId);
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> Resyncing...');
            
            $.post(ajaxurl, {
                action: 'mic_retry_sync',
                order_id: orderId,
                log_id: logId,
                nonce: micStrings.retrySyncNonce
            })
            .done(function(response) {
                console.log('Resync from log response:', response);
                if (response.success) {
                    MICUtils.showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    MICUtils.showNotification('Resync failed: ' + response.data, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('Resync from log AJAX failed:', {xhr, status, error});
                MICUtils.showNotification('Resync failed: ' + error, 'error');
            })
            .always(function() {
                button.prop('disabled', false);
                button.html(originalText);
            });
        },

        /**
         * Toggle expandable content
         */
        toggleExpanded: function(e) {
            console.log('Toggle expandable clicked:', e.target);
            const element = $(e.target);
            const content = element.next('.mic-expandable-content');
            
            console.log('Element:', element);
            console.log('Content found:', content.length);
            console.log('Content visible:', content.is(':visible'));
            
            if (content.is(':visible')) {
                content.slideUp();
                element.removeClass('mic-expanded');
                console.log('Content hidden');
            } else {
                content.slideDown();
                element.addClass('mic-expanded');
                console.log('Content shown');
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
            const date = new Date(dateString);
            return date.toLocaleDateString();
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
         * Show notification
         */
        showNotification: function(message, type) {
            const notification = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap').prepend(notification);
            
            setTimeout(function() {
                notification.fadeOut();
            }, 5000);
        }
    };

    /**
     * Initialize when document is ready - FIXED VERSION
     */
    $(document).ready(function() {
        console.log('MIC Admin initializing...');
        MICAdmin.init();
        

    });
    


    /**
     * Expose objects to global scope for external access
     */
    window.MICAdmin = MICAdmin;
    window.MICUtils = MICUtils;

})(jQuery);
