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
         * Bind event handlers
         */
        bindEvents: function() {
            // Connection test button
            $(document).on('click', '#test-btn', this.testConnection);
            
            // Clear logs button
            $(document).on('click', '#clear-logs-btn', this.showClearLogsDialog);
            
            // Manual sync button
            $(document).on('click', '[data-order-id]', this.manualSync);
            
            // Expandable content
            $(document).on('click', '.mic-expandable', this.toggleExpanded);
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
         * Test connection to Laravel app
         */
        testConnection: function(e) {
            e.preventDefault();
            
            const button = $('#test-btn');
            const resultDiv = $('#test-result');
            
            button.prop('disabled', true);
            button.html('<i class="ri-loader-4-line ri-spin"></i> ' + micStrings.testing);
            resultDiv.show();
            resultDiv.removeClass('mic-test-success mic-test-error');
            resultDiv.html('<i class="ri-loader-4-line ri-spin"></i> ' + micStrings.testingConnection);
            
            $.post(ajaxurl, {
                action: 'mic_test_connection'
            })
            .done(function(response) {
                if (response.success) {
                    resultDiv.addClass('mic-test-success');
                    resultDiv.html('<i class="ri-check-circle-line"></i> ' + micStrings.connectionSuccessful);
                } else {
                    resultDiv.addClass('mic-test-error');
                    resultDiv.html('<i class="ri-error-warning-line"></i> ' + micStrings.connectionFailed + ' ' + (response.data || micStrings.unknownError));
                }
            })
            .fail(function(xhr, status, error) {
                resultDiv.addClass('mic-test-error');
                resultDiv.html('<i class="ri-error-warning-line"></i> ' + micStrings.connectionError + ' ' + error);
            })
            .always(function() {
                button.prop('disabled', false);
                button.html('<i class="ri-wifi-line"></i> ' + micStrings.testConnection);
            });
        },

        /**
         * Show clear logs dialog
         */
        showClearLogsDialog: function(e) {
            e.preventDefault();
            
            const days = prompt(micLogsStrings.enterDays, '30');
            if (days === null) return;
            
            const dayNum = parseInt(days);
            if (isNaN(dayNum) || dayNum < 0) {
                alert(micLogsStrings.validNumber);
                return;
            }
            
            const message = dayNum === 0 ? 
                micLogsStrings.clearAllConfirm :
                micLogsStrings.clearOldConfirm.replace('%d', dayNum);
                
            if (!confirm(message)) return;
            
            const formData = new FormData();
            formData.append('action', 'mic_clear_logs');
            formData.append('days', dayNum);
            formData.append('nonce', micLogsStrings.nonce);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false
            })
            .done(function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert(micLogsStrings.error + ' ' + response.data);
                }
            })
            .fail(function(xhr, status, error) {
                alert(micLogsStrings.errorClearing + ' ' + error);
            });
        },

        /**
         * Manual sync functionality
         */
        manualSync: function(e) {
            e.preventDefault();
            
            const orderId = $(this).data('order-id');
            
            if (confirm(micStrings.manualSyncConfirm)) {
                const url = micStrings.manualSyncUrl + orderId;
                window.location.href = url;
            }
        },

        /**
         * Toggle expanded content
         */
        toggleExpanded: function(e) {
            e.preventDefault();
            
            const element = $(this);
            const expandedData = element.next('.mic-expanded-data');
            
            if (expandedData.is(':visible')) {
                expandedData.hide();
                element.html(element.html().replace('ri-eye-off-line', 'ri-eye-line'));
            } else {
                expandedData.show();
                element.html(element.html().replace('ri-eye-line', 'ri-eye-off-line'));
            }
        },

        /**
         * Setup SKU validation
         */
        setupSKUValidation: function() {
            const skuField = $('#_sku');
            const indicator = $('<span id="mic-sku-indicator" style="margin-left: 8px;"></span>');
            
            skuField.after(indicator);
            
            function updateSkuIndicator() {
                const sku = skuField.val();
                
                if (sku && sku.trim() !== '') {
                    indicator.html('<i class="ri-check-circle-line" style="color: #0d7049;"></i> ' + micStrings.willSync);
                } else {
                    indicator.html('<i class="ri-error-warning-line" style="color: #b7791f;"></i> ' + micStrings.wontSync);
                }
            }
            
            skuField.on('input blur', updateSkuIndicator);
            updateSkuIndicator();
            
            $('form#post').on('submit', function(e) {
                const sku = skuField.val();
                if (!sku || sku.trim() === '') {
                    if (confirm(micStrings.skuWarning)) {
                        return true;
                    } else {
                        e.preventDefault();
                        skuField.focus();
                        return false;
                    }
                }
            });
        },

        /**
         * Animate numbers on analytics page
         */
        animateNumbers: function() {
            $('.mic-stat-number').each(function() {
                const element = $(this);
                const text = element.text();
                const finalValue = parseInt(text.replace(/[,\s%]/g, ''));
                
                if (finalValue > 0 && finalValue < 1000) {
                    MICAdmin.animateNumber(element, finalValue);
                }
            });
        },

        /**
         * Animate a single number
         */
        animateNumber: function(element, target) {
            let current = 0;
            const increment = target / 30;
            const timer = setInterval(function() {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                element.text(Math.floor(current).toLocaleString());
            }, 50);
        }
    };

    /**
     * Chart.js Integration for Analytics
     */
    const MICCharts = {
        
        /**
         * Initialize daily activity chart
         */
        initDailyChart: function(data) {
            if (!data || !data.labels || !data.datasets) return;
            
            const ctx = document.getElementById('dailyChart');
            if (!ctx) return;
            
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#6b7280'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(107, 114, 128, 0.1)'
                            },
                            ticks: {
                                color: '#6b7280'
                            }
                        }
                    }
                }
            });
        },

        /**
         * Initialize status distribution chart
         */
        initStatusChart: function(data) {
            if (!data || !data.labels || !data.datasets) return;
            
            const ctx = document.getElementById('statusChart');
            if (!ctx) return;
            
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 14
                                }
                            }
                        }
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
     * Initialize when document is ready
     */
    $(document).ready(function() {
        MICAdmin.init();
    });

    /**
     * Expose objects to global scope for external access
     */
    window.MICAdmin = MICAdmin;
    window.MICCharts = MICCharts;
    window.MICUtils = MICUtils;

})(jQuery);
