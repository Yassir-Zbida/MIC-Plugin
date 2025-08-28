<?php
/**
 * Plugin Name: MIC Woo to App Sync
 * Description: Syncs WooCommerce paid orders with Laravel app. HPOS compatible.
 * Version: 1.2.1
 * Author: Yassir Zbida
 * Text Domain: mic-woo-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 8.2
 * WC tested up to: 8.9
 * Requires Plugins: woocommerce
 * Woo: 12345:342928dfsfhsf2349842374wdf4234sfd
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Check WooCommerce version
if (defined('WC_VERSION') && version_compare(WC_VERSION, '8.0', '<')) {
    return;
}

class MICWooToAppSyncPlugin {
    
    private $option_name = 'mic_woo_sync_settings';
    private $log_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->log_table_name = $wpdb->prefix . 'mic_sync_logs';
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        add_action('wp_ajax_mic_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_mic_basic_test', array($this, 'ajax_basic_test'));
        add_action('wp_ajax_mic_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_mic_sync_order', array($this, 'ajax_sync_order'));
        add_action('wp_ajax_mic_retry_sync', array($this, 'ajax_retry_sync'));
        
        // HPOS compatible hooks - use only the modern approach
        add_action('woocommerce_order_status_changed', array($this, 'sync_order_hpos'), 10, 4);
        
        // Declare HPOS compatibility - run early for proper registration
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'), 1);
        
        // Add debugging for development (remove in production)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_notices', array($this, 'debug_notice'));
        }
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Load textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // FIXED: Add only ONE order meta box with HPOS support
        add_action('add_meta_boxes', array($this, 'add_order_meta_boxes'));
        
        // Add sync status column to orders list
        add_filter('manage_edit-shop_order_columns', array($this, 'add_sync_status_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_sync_status_column'), 10, 2);
        
        // HPOS compatibility for orders list
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_sync_status_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'display_sync_status_column'), 10, 2);
        
        // Manual sync
        add_action('wp_ajax_manual_sync', array($this, 'manual_sync'));
        
        // Bulk actions
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_sync_action'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_sync'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_sync_notice'));
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('mic-woo-sync', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Enqueue admin assets (CSS and JS) - IMPROVED VERSION
     */
    public function enqueue_admin_assets($hook) {
        // Load on our plugin pages and WooCommerce order pages
        $load_assets = false;
        
        // Plugin pages
        if (strpos($hook, 'mic-') !== false || $hook === 'toplevel_page_mic-woo-sync') {
            $load_assets = true;
        }
        
        // WooCommerce order pages (both legacy and HPOS)
        if ($hook === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') {
            $load_assets = true;
        }
        
        // HPOS orders page
        if ($hook === 'woocommerce_page_wc-orders') {
            $load_assets = true;
        }
        
        // WooCommerce orders list page
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
            $load_assets = true;
        }
        
        if (!$load_assets) {
            return;
        }
        
        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_version = '1.2.1';
        
        // Enqueue CSS
        wp_enqueue_style(
            'mic-woo-sync-admin-css',
            $plugin_url . 'assets/css/admin.css',
            array(),
            $plugin_version
        );
        
        // Enqueue Remix Icons
        wp_enqueue_style(
            'remix-icons',
            'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
            array(),
            '3.5.0'
        );
        
        // IMPROVED: Always enqueue Chart.js for analytics functionality
        $is_analytics_page = (strpos($hook, 'mic-analytics') !== false);
        if ($is_analytics_page) {
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js',
                array(),
                '4.4.0',
                true
            );
            
            // Add fallback script inline
            wp_add_inline_script('chart-js', '
                // Fallback Chart.js loading
                if (typeof Chart === "undefined") {
                    console.warn("Primary Chart.js CDN failed, trying fallback...");
                    var fallbackScript = document.createElement("script");
                    fallbackScript.src = "https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js";
                    fallbackScript.async = false;
                    document.head.appendChild(fallbackScript);
                }
            ', 'after');
        }
        
        // Enqueue our admin JavaScript
        wp_enqueue_script(
            'mic-woo-sync-admin-js',
            $plugin_url . 'assets/js/admin.js',
            array('jquery'),
            $plugin_version,
            true
        );
        
        // Localize script with translated strings
        wp_localize_script('mic-woo-sync-admin-js', 'micStrings', array(
            'testing' => __('Testing...', 'mic-woo-sync'),
            'testingConnection' => __('Testing connection...', 'mic-woo-sync'),
            'connectionSuccessful' => __('Connection successful! Laravel app is responding.', 'mic-woo-sync'),
            'connectionFailed' => __('Connection failed:', 'mic-woo-sync'),
            'connectionError' => __('Connection error:', 'mic-woo-sync'),
            'unknownError' => __('Unknown error', 'mic-woo-sync'),
            'testConnection' => __('Test Connection', 'mic-woo-sync'),
            'manualSyncConfirm' => __('Are you sure you want to manually sync this order to the Laravel app?', 'mic-woo-sync'),
            'retrySyncConfirm' => __('Are you sure you want to retry syncing this order?', 'mic-woo-sync'),
            'manualSyncUrl' => wp_nonce_url(admin_url('admin-ajax.php?action=manual_sync&order_id='), 'manual_sync'),
            'willSync' => __('Will sync', 'mic-woo-sync'),
            'wontSync' => __('Won\'t sync', 'mic-woo-sync'),
            'retrying' => __('Retrying...', 'mic-woo-sync'),
            'retryFailed' => __('Retry failed:', 'mic-woo-sync'),
            'skuWarning' => __('⚠️ This product has no SKU and will not sync with the Made in China Laravel app.\n\nDo you want to continue anyway?', 'mic-woo-sync'),
            'synced' => __('Synced', 'mic-woo-sync'),
            'notSynced' => __('Not Synced', 'mic-woo-sync'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mic_test_connection'),
            'syncOrderNonce' => wp_create_nonce('mic_sync_order'),
            'retrySyncNonce' => wp_create_nonce('mic_retry_sync')
        ));
        
        // Localize script for logs page
        wp_localize_script('mic-woo-sync-admin-js', 'micLogsStrings', array(
            'enterDays' => __('Enter number of days to keep logs (0 = clear all logs, 30 = keep last 30 days):', 'mic-woo-sync'),
            'validNumber' => __('Please enter a valid number (0 or higher)', 'mic-woo-sync'),
            'clearAllConfirm' => __('Are you sure you want to clear ALL sync logs? This cannot be undone.', 'mic-woo-sync'),
            'clearOldConfirm' => __('Are you sure you want to clear logs older than %d days? This cannot be undone.', 'mic-woo-sync'),
            'error' => __('Error:', 'mic-woo-sync'),
            'errorClearing' => __('Error clearing logs:', 'mic-woo-sync'),
            'nonce' => wp_create_nonce('mic_clear_logs')
        ));
        
        // IMPROVED: Add Chart.js ready state check for analytics page
        if ($is_analytics_page) {
            wp_add_inline_script('mic-woo-sync-admin-js', '
                // Chart.js readiness check
                jQuery(document).ready(function($) {
                    window.micChartJsReady = false;
                    
                    function checkChartJsReady() {
                        if (typeof Chart !== "undefined") {
                            window.micChartJsReady = true;
                            console.log("Chart.js is ready");
                            $(document).trigger("micChartJsReady");
                        } else {
                            setTimeout(checkChartJsReady, 100);
                        }
                    }
                    
                    checkChartJsReady();
                });
            ', 'after');
        }
    }
    
    /**
     * Declare HPOS compatibility - FIXED VERSION
     */
    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // Use the correct feature key - 'custom_order_tables' (plural)
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            
            // Also declare compatibility with the legacy feature key for older WooCommerce versions
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_table', __FILE__, true);
        }
    }
    
    public function init() {
        // Create logs table if it doesn't exist
        $this->create_logs_table();
        
        // Add admin notice if WooCommerce is not active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }
    }
    
    /**
     * Admin notice for missing WooCommerce
     */
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . __('MIC Woo to App Sync:', 'mic-woo-sync') . '</strong> ';
        echo __('This plugin requires WooCommerce to be installed and activated.', 'mic-woo-sync');
        echo '</p></div>';
    }
    
    /**
     * Debug notice for development
     */
    public function debug_notice() {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>' . __('Debug Info:', 'mic-woo-sync') . '</strong> ';
        echo __('MIC Woo to App Sync plugin is active.', 'mic-woo-sync') . ' ';
        echo __('WooCommerce version:', 'mic-woo-sync') . ' ' . (defined('WC_VERSION') ? WC_VERSION : __('Unknown', 'mic-woo-sync')) . '. ';
        echo __('HPOS enabled:', 'mic-woo-sync') . ' ' . (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil') ? __('Yes', 'mic-woo-sync') : __('No', 'mic-woo-sync'));
        echo '</p></div>';
    }
    
    public function activate() {
        $this->create_logs_table();
        $this->set_default_options();
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    private function set_default_options() {
        $defaults = array(
            'laravel_url' => '',
            'webhook_secret' => '',
            'sync_on_status' => array('completed', 'processing')
        );
        
        if (!get_option($this->option_name)) {
            add_option($this->option_name, $defaults);
        }
    }
    
    private function create_logs_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->log_table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            order_number varchar(50),
            customer_email varchar(100) NOT NULL,
            customer_name varchar(255) NOT NULL,
            products_data text NOT NULL,
            sync_status varchar(20) NOT NULL,
            response_code int(3),
            response_message text,
            response_data text,
            sync_time datetime DEFAULT CURRENT_TIMESTAMP,
            execution_time float,
            retry_count int(2) DEFAULT 0,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY sync_status (sync_status),
            KEY sync_time (sync_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Debug: Check if table creation was successful
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MIC Table Creation Result: ' . print_r($result, true));
            error_log('MIC Table Name: ' . $this->log_table_name);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('MIC Woo to App Sync', 'mic-woo-sync'),
            __('MIC Sync', 'mic-woo-sync'),
            'manage_options',
            'mic-woo-sync',
            array($this, 'admin_page'),
            'dashicons-cloud',
            30
        );
        
        add_submenu_page(
            'mic-woo-sync',
            __('Settings', 'mic-woo-sync'),
            __('Settings', 'mic-woo-sync'),
            'manage_options',
            'mic-woo-sync',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'mic-woo-sync',
            __('Sync Logs', 'mic-woo-sync'),
            __('Sync Logs', 'mic-woo-sync'),
            'manage_options',
            'mic-sync-logs',
            array($this, 'logs_page')
        );
        
        add_submenu_page(
            'mic-woo-sync',
            __('Analytics', 'mic-woo-sync'),
            __('Analytics', 'mic-woo-sync'),
            'manage_options',
            'mic-analytics',
            array($this, 'analytics_page')
        );
    }
    
    /**
     * Check if plugin is properly configured
     */
    private function is_configured() {
        $options = get_option($this->option_name);
        return !empty($options['laravel_url']) && !empty($options['webhook_secret']);
    }
    
    /**
     * Admin page content with improved UI and proper translations
     */
    public function admin_page() {
        // Save settings
        if (isset($_POST['submit'])) {
            // Debug: Check if form is being submitted
            if (!wp_verify_nonce($_POST['mic_nonce'], 'mic_save_settings')) {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Security check failed. Please try again.', 'mic-woo-sync') . '</p></div>';
            } else {
                $options = array(
                    'laravel_url' => sanitize_url($_POST['laravel_url']),
                    'webhook_secret' => sanitize_text_field($_POST['webhook_secret']),
                    'sync_on_status' => isset($_POST['sync_on_status']) ? $_POST['sync_on_status'] : array('completed', 'processing')
                );
                
                update_option($this->option_name, $options);
                echo '<div class="notice notice-success is-dismissible"><p><i class="ri-checkbox-circle-line"></i> ' . __('Settings saved successfully!', 'mic-woo-sync') . '</p></div>';
            }
        }

        $options = get_option($this->option_name);
        $laravel_url = isset($options['laravel_url']) ? $options['laravel_url'] : '';
        $webhook_secret = isset($options['webhook_secret']) ? $options['webhook_secret'] : '';
        $is_configured = $this->is_configured();
        
        ?>
        <div class="wrap">
            <div class="mic-admin-header">
                <h1>
                    <i class="ri-cloud-line"></i>
                    <?php _e('MIC Woo to App Sync', 'mic-woo-sync'); ?>
                    <?php if ($is_configured): ?>
                        <span class="mic-status-badge mic-status-configured">
                        <i class="ri-checkbox-circle-line"></i><?php _e('Configured', 'mic-woo-sync'); ?>
                        </span>
                    <?php else: ?>
                        <span class="mic-status-badge mic-status-not-configured">
                            <i class="ri-error-warning-fill"></i> <?php _e('Needs Configuration', 'mic-woo-sync'); ?>
                        </span>
                    <?php endif; ?>
                </h1>
                <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e('Sync WooCommerce orders with your Laravel ebook application', 'mic-woo-sync'); ?></p>
            </div>
            
            <div class="mic-card">
                <h2><i class="ri-settings-3-line"></i> <?php _e('Configuration', 'mic-woo-sync'); ?></h2>
                
                <form method="post" action="" class="mic-settings-form">
                    <?php wp_nonce_field('mic_save_settings', 'mic_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="laravel_url"><?php _e('Laravel App URL', 'mic-woo-sync'); ?></label>
                            </th>
                            <td>
                                <input type="url" name="laravel_url" id="laravel_url" value="<?php echo esc_attr($laravel_url); ?>" class="regular-text" placeholder="https://your-domain.com" />
                                <p class="description"><?php _e('The base URL of your Laravel application (without /api/v1/woocommerce-sync)', 'mic-woo-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="webhook_secret"><?php _e('Webhook Secret', 'mic-woo-sync'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="webhook_secret" id="webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" />
                                <p class="description"><?php _e('The secret key configured in your Laravel app\'s .env file', 'mic-woo-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sync_on_status"><?php _e('Sync on Order Status', 'mic-woo-sync'); ?></label>
                            </th>
                            <td>
                                <?php
                                $available_statuses = wc_get_order_statuses();
                                foreach ($available_statuses as $status => $label) {
                                    $status_key = str_replace('wc-', '', $status);
                                    $checked = in_array($status_key, $options['sync_on_status'] ?? array('completed', 'processing')) ? 'checked' : '';
                                    echo '<label><input type="checkbox" name="sync_on_status[]" value="' . esc_attr($status_key) . '" ' . $checked . ' /> ' . esc_html($label) . '</label><br>';
                                }
                                ?>
                                <p class="description"><?php _e('Select which order statuses should trigger sync', 'mic-woo-sync'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" value="<?php echo esc_attr(__('Save Settings', 'mic-woo-sync')); ?>" class="button button-primary" />
                    </p>
                </form>
            </div>
            
            <div class="mic-card">
                <h2><i class="ri-pulse-line"></i> <?php _e('Connection Test', 'mic-woo-sync'); ?></h2>
                <p><?php _e('Test the connection to your Laravel application. This will verify that your Laravel app is accessible and can receive requests from WooCommerce.', 'mic-woo-sync'); ?></p>
                
                <div class="mic-test-buttons">
                    <button type="button" class="mic-button mic-button-secondary" id="basic-test-btn">
                        <i class="ri-link"></i> <?php _e('Basic Connectivity Test', 'mic-woo-sync'); ?>
                    </button>
                    <button type="button" class="mic-button" id="test-btn">
                        <i class="ri-wifi-line"></i> <?php _e('Full Connection Test', 'mic-woo-sync'); ?>
                    </button>
                </div>
                <div id="basic-test-result" class="mic-test-result mic-test-result-basic"></div>
                <div id="test-result" class="mic-test-result"></div>
                
                <div class="mic-troubleshooting">
                    <h4><?php _e('Troubleshooting Tips:', 'mic-woo-sync'); ?></h4>
                    <ul>
                        <li><?php _e('Ensure your Laravel app is running and accessible from your WordPress server', 'mic-woo-sync'); ?></li>
                        <li><?php _e('Check that the route /api/v1/woocommerce-sync exists in your Laravel app', 'mic-woo-sync'); ?></li>
                        <li><?php _e('Verify CORS settings allow requests from your WordPress domain', 'mic-woo-sync'); ?></li>
                        <li><?php _e('Check Laravel logs for any errors when the test request is made', 'mic-woo-sync'); ?></li>
                    </ul>
                </div>
            </div>
            
            <div class="mic-card">
                <h2><i class="ri-guide-line"></i> <?php _e('Setup Guide', 'mic-woo-sync'); ?></h2>
                
                <div class="mic-guide">
                    <h3><i class="ri-number-1"></i> <?php _e('Configure Laravel App', 'mic-woo-sync'); ?></h3>
                    <p><?php _e('In your Laravel app\'s', 'mic-woo-sync'); ?> <code>.env</code> <?php _e('file, add:', 'mic-woo-sync'); ?></p>
                    <pre><code>WOOCOMMERCE_ENABLED=true
WOOCOMMERCE_WEBHOOK_SECRET=<?php echo esc_html($webhook_secret ?: 'your-secret-here'); ?></code></pre>
                </div>
                
                <div class="mic-guide">
                    <h3><i class="ri-number-2"></i> <?php _e('Run Migrations', 'mic-woo-sync'); ?></h3>
                    <p><?php _e('In your Laravel app, run:', 'mic-woo-sync'); ?></p>
                    <pre><code>php artisan migrate</code></pre>
                </div>
                
                <div class="mic-guide">
                    <h3><i class="ri-number-3"></i> <?php _e('Create Route', 'mic-woo-sync'); ?></h3>
                    <p><?php _e('Ensure your Laravel app has the sync endpoint:', 'mic-woo-sync'); ?></p>
                    <pre><code>Route::post('/api/v1/woocommerce-sync', [WooCommerceController::class, 'sync']);</code></pre>
                </div>
                
                <div class="mic-guide">
                    <h3><i class="ri-number-4"></i> <?php _e('Product SKUs', 'mic-woo-sync'); ?></h3>
                    <p><?php _e('Make sure your WooCommerce products have SKUs that match your Laravel ebook identifiers.', 'mic-woo-sync'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * FIXED: Connection test with same authentication as actual sync
     */
    public function test_connection() {
        check_ajax_referer('mic_test_connection', 'nonce');
        
        $options = get_option($this->option_name);
        $laravel_url = rtrim($options['laravel_url'], '/');
        
        if (empty($laravel_url)) {
            wp_send_json_error(__('Laravel URL not configured. Please enter your Laravel app URL in the settings above.', 'mic-woo-sync'));
            return;
        }
        
        if (empty($options['webhook_secret'])) {
            wp_send_json_error(__('Webhook secret not configured. Please enter your webhook secret in the settings above.', 'mic-woo-sync'));
            return;
        }
        
        // Add debug info if WP_DEBUG is enabled
        $debug_info = '';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $debug_info = "\n\n" . __('Debug Info:', 'mic-woo-sync') . "\n- " . __('Laravel URL:', 'mic-woo-sync') . " $laravel_url\n- " . __('WordPress Site URL:', 'mic-woo-sync') . " " . get_site_url();
        }
        
        // Use regular sync endpoint with realistic test data
        $test_url = $laravel_url . '/api/v1/woocommerce-sync';
        
        // FIXED: Send realistic order data that matches Laravel validation
        $test_order_id = 999990000 + (current_time('timestamp') % 9999); // Generate test order ID in 999990000-999999999 range
        $test_data = array(
            'order_id' => $test_order_id, // Integer as required by Laravel
            'email' => 'test@' . parse_url(get_site_url(), PHP_URL_HOST),
            'name' => 'WP Connection Test - ' . get_bloginfo('name'),
            'products' => array(
                array(
                    'name' => 'Test Product - Connection Verification',
                    'sku' => 'TEST-CONNECTION-' . current_time('timestamp'),
                    'quantity' => 1,
                    'price' => 0.01
                )
            ),
            'total' => 0.01,
            'currency' => get_woocommerce_currency(),
            'status' => 'test-connection',
            'created_at' => current_time('mysql'),
            'test_connection' => true,
            'wp_domain' => parse_url(get_site_url(), PHP_URL_HOST),
            'wp_site_name' => get_bloginfo('name')
        );
        
        $payload = wp_json_encode($test_data);
        $signature = base64_encode(hash_hmac('sha256', $payload, $options['webhook_secret'], true));
        
        $test_response = wp_remote_post($test_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-WC-Webhook-Signature' => $signature  // Use same header as actual sync
            ),
            'body' => $payload
        ));
        
        if (is_wp_error($test_response)) {
            wp_send_json_error(__('Connection test failed', 'mic-woo-sync') . ': ' . $test_response->get_error_message() . $debug_info);
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($test_response);
        $response_body = wp_remote_retrieve_body($test_response);
        
        // Check response codes with proper translations
        if ($response_code === 200 || $response_code === 201) {
            wp_send_json_success(array(
                'message' => __('Connection test completed successfully! Your Laravel app received and processed the test order data correctly.', 'mic-woo-sync') . 
                "\n\n" . __('Test data sent:', 'mic-woo-sync') . 
                "\n- " . __('Domain:', 'mic-woo-sync') . " " . parse_url(get_site_url(), PHP_URL_HOST) .
                "\n- " . __('Site name:', 'mic-woo-sync') . " " . get_bloginfo('name') .
                "\n- " . __('Test order ID:', 'mic-woo-sync') . " " . $test_order_id .
                $debug_info
            ));
        } elseif ($response_code === 401) {
            // Enhanced error message for 401 errors
            wp_send_json_error(__('❌ Authentication failed (HTTP 401). The webhook signature verification failed.', 'mic-woo-sync') . "\n\n" . 
                __('Possible causes:', 'mic-woo-sync') . 
                "\n- " . __('Incorrect webhook secret key', 'mic-woo-sync') .
                "\n- " . __('Signature verification method mismatch', 'mic-woo-sync') .
                "\n- " . __('CORS settings blocking requests', 'mic-woo-sync') .
                $debug_info);
        } elseif ($response_code === 404) {
            wp_send_json_error(__('❌ Endpoint not found (HTTP 404). Please check that /api/v1/woocommerce-sync exists in your Laravel app.', 'mic-woo-sync') . $debug_info);
        } elseif ($response_code === 422) {
            wp_send_json_error(__('❌ Validation error (HTTP 422): Your Laravel app rejected the test data.', 'mic-woo-sync') . "\n\n" . 
                __('This usually means your Laravel validation rules are different from what the plugin is sending.', 'mic-woo-sync') . "\n\n" . 
                __('Raw response:', 'mic-woo-sync') . " $response_body" . $debug_info);
        } else {
            wp_send_json_error(__('❌ Unexpected response', 'mic-woo-sync') . " (HTTP $response_code): $response_body" . $debug_info);
        }
    }
    
    /**
 * FIXED: AJAX handler for manual order sync
 */
public function ajax_sync_order() {
    check_ajax_referer('mic_sync_order', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Unauthorized', 'mic-woo-sync'));
        return;
    }
    
    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(__('Order not found', 'mic-woo-sync'));
        return;
    }
    
    // Check if plugin is configured
    $options = get_option($this->option_name);
    if (empty($options['laravel_url']) || empty($options['webhook_secret'])) {
        wp_send_json_error(__('Plugin not configured. Please configure Laravel URL and webhook secret.', 'mic-woo-sync'));
        return;
    }
    
    // Process the sync (force sync for manual sync regardless of status)
    $this->process_order_sync_force($order_id, $order);
    
    // FIXED: Reload order object and check both meta data and logs
    $order = wc_get_order($order_id); // Reload order
    $synced = $order->get_meta('_laravel_synced');
    $sync_time = $order->get_meta('_laravel_sync_time');
    
    // Also check the latest log entry to be sure
    global $wpdb;
    $latest_log = $wpdb->get_row($wpdb->prepare(
        "SELECT sync_status, response_message FROM {$this->log_table_name} 
         WHERE order_id = %d ORDER BY sync_time DESC LIMIT 1",
        $order_id
    ));
    
    // Consider it successful if either meta says synced OR latest log is success
    if ($synced || ($latest_log && $latest_log->sync_status === 'success')) {
        wp_send_json_success(array(
            'message' => __('Order sync successful!', 'mic-woo-sync'),
            'status' => 'synced',
            'sync_time' => $sync_time,
            'log_status' => $latest_log ? $latest_log->sync_status : 'no_log'
        ));
    } else {
        // Provide more detailed error information
        $error_message = __('Order sync failed. Check the logs for details.', 'mic-woo-sync');
        if ($latest_log && $latest_log->response_message) {
            $error_message = $latest_log->response_message;
        }
        
        wp_send_json_error(array(
            'message' => $error_message,
            'log_status' => $latest_log ? $latest_log->sync_status : 'no_log',
            'synced_meta' => $synced ? 'yes' : 'no'
        ));
    }
}

    
    /**
 * FIXED: AJAX handler for retrying failed syncs
 */
public function ajax_retry_sync() {
    check_ajax_referer('mic_retry_sync', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Unauthorized', 'mic-woo-sync'));
        return;
    }
    
    $order_id = intval($_POST['order_id']);
    $log_id = intval($_POST['log_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(__('Order not found', 'mic-woo-sync'));
        return;
    }
    
    // Check if plugin is configured
    $options = get_option($this->option_name);
    if (empty($options['laravel_url']) || empty($options['webhook_secret'])) {
        wp_send_json_error(__('Plugin not configured. Please configure Laravel URL and webhook secret.', 'mic-woo-sync'));
        return;
    }
    
    // Process the sync (force sync regardless of order status for retry)
    $this->process_order_sync_force($order_id, $order);
    
    // FIXED: Reload order object and check both meta data and logs
    $order = wc_get_order($order_id); // Reload order
    $synced = $order->get_meta('_laravel_synced');
    $sync_time = $order->get_meta('_laravel_sync_time');
    
    // Also check the latest log entry to be sure
    global $wpdb;
    $latest_log = $wpdb->get_row($wpdb->prepare(
        "SELECT sync_status, response_message FROM {$this->log_table_name} 
         WHERE order_id = %d ORDER BY sync_time DESC LIMIT 1",
        $order_id
    ));
    
    // Consider it successful if either meta says synced OR latest log is success
    if ($synced || ($latest_log && $latest_log->sync_status === 'success')) {
        wp_send_json_success(array(
            'message' => __('Order sync retry successful!', 'mic-woo-sync'),
            'status' => 'synced',
            'sync_time' => $sync_time,
            'log_status' => $latest_log ? $latest_log->sync_status : 'no_log'
        ));
    } else {
        // Provide more detailed error information
        $error_message = __('Order sync retry failed. Check the logs for details.', 'mic-woo-sync');
        if ($latest_log && $latest_log->response_message) {
            $error_message = $latest_log->response_message;
        }
        
        wp_send_json_error(array(
            'message' => $error_message,
            'log_status' => $latest_log ? $latest_log->sync_status : 'no_log',
            'synced_meta' => $synced ? 'yes' : 'no'
        ));
    }
}
    
    /**
     * AJAX handler for basic connectivity test
     */
    public function ajax_basic_test() {
        check_ajax_referer('mic_test_connection', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized', 'mic-woo-sync'));
            return;
        }
        
        $options = get_option($this->option_name);
        if (empty($options['laravel_url'])) {
            wp_send_json_error(__('Laravel URL not configured', 'mic-woo-sync'));
            return;
        }
        
        $laravel_url = rtrim($options['laravel_url'], '/');
        
        // Simple GET request to test basic connectivity
        $response = wp_remote_get($laravel_url, array(
            'timeout' => 10,
            'user-agent' => 'MIC-Woo-Sync/1.0'
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(__('Basic connectivity test failed:', 'mic-woo-sync') . ' ' . $response->get_error_message());
            return;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code >= 200 && $code < 400) {
            wp_send_json_success(__('Basic connectivity test completed successfully', 'mic-woo-sync'));
        } else {
            wp_send_json_error(__('Basic connectivity test failed - HTTP', 'mic-woo-sync') . ' ' . $code);
        }
    }
    
    // HPOS compatible order sync - THIS IS THE CORE WORKING LOGIC FROM THE FIRST PLUGIN
    public function sync_order_hpos($order_id, $old_status, $new_status, $order) {
        $options = get_option($this->option_name);
        
        if (empty($options['laravel_url']) || empty($options['webhook_secret'])) {
            $this->log_sync($order_id, 'failed', __('Plugin not configured', 'mic-woo-sync'));
            return;
        }
        
        // Check if order status should trigger sync
        $sync_statuses = isset($options['sync_on_status']) ? $options['sync_on_status'] : array('completed', 'processing');
        
        // Remove 'wc-' prefix if present
        $new_status = str_replace('wc-', '', $new_status);
        
        if (!in_array($new_status, $sync_statuses)) {
            return; // Don't sync this status
        }
        
        $this->process_order_sync($order_id, $order);
    }
    
    /**
 * FIXED: Force sync method for retries (bypasses status check) - with better error handling
 */
private function process_order_sync_force($order_id, $order) {
    $options = get_option($this->option_name);
    
    if (empty($options['laravel_url']) || empty($options['webhook_secret'])) {
        $this->log_sync($order_id, 'failed', __('Plugin not configured', 'mic-woo-sync'));
        return false;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        $this->log_sync($order_id, 'failed', __('Order not found', 'mic-woo-sync'));
        return false;
    }
    
    $start_time = microtime(true);
    
    // Prepare order data - SAME FORMAT AS FIRST PLUGIN
    $order_data = array(
        'order_id' => $order_id,
        'order_number' => $order->get_order_number(),
        'email' => $order->get_billing_email(),
        'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'products' => array()
    );
    
    // Get products with SKUs - SAME LOGIC AS FIRST PLUGIN
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->get_sku()) {
            $order_data['products'][] = array(
                'sku' => $product->get_sku(),
                'name' => $product->get_name()
            );
        }
    }
    
    if (empty($order_data['products'])) {
        $this->log_sync($order_id, 'failed', __('No products with SKUs found', 'mic-woo-sync'));
        return false;
    }
    
    // Send to Laravel - SAME METHOD AS FIRST PLUGIN
    $laravel_url = rtrim($options['laravel_url'], '/') . '/api/v1/woocommerce-sync';
    $payload = wp_json_encode($order_data);
    $signature = base64_encode(hash_hmac('sha256', $payload, $options['webhook_secret'], true));
    
    $response = wp_remote_post($laravel_url, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-WC-Webhook-Signature' => $signature
        ),
        'body' => $payload,
        'timeout' => 30
    ));
    
    $execution_time = microtime(true) - $start_time;
    
    if (is_wp_error($response)) {
        $this->log_sync($order_id, 'failed', $response->get_error_message(), $execution_time);
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($code === 200 || $code === 201) {
        $this->log_sync($order_id, 'success', __('Sync completed successfully', 'mic-woo-sync'), $execution_time, $code, $body);
        
        // FIXED: Store sync metadata in order and ensure it's saved
        $order->update_meta_data('_laravel_synced', true);
        $order->update_meta_data('_laravel_sync_time', current_time('mysql'));
        $order->save();
        
        // Force a cache clear for this order
        wp_cache_delete($order_id, 'orders');
        clean_post_cache($order_id);
        
        return true;
    } else {
        $this->log_sync($order_id, 'failed', "HTTP $code: $body", $execution_time, $code, $body);
        return false;
    }
}

    // Main sync processing method - WORKING LOGIC FROM FIRST PLUGIN
    private function process_order_sync($order_id, $order) {
        $options = get_option($this->option_name);
        
        if (empty($options['laravel_url']) || empty($options['webhook_secret'])) {
            $this->log_sync($order_id, 'failed', __('Plugin not configured', 'mic-woo-sync'));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_sync($order_id, 'failed', __('Order not found', 'mic-woo-sync'));
            return;
        }
        
        // Check if order status should trigger sync
        $sync_statuses = isset($options['sync_on_status']) ? $options['sync_on_status'] : array('completed');
        $current_status = str_replace('wc-', '', $order->get_status());
        
        if (!in_array($current_status, $sync_statuses)) {
            return; // Don't sync this status
        }
        
        $start_time = microtime(true);
        
        // Prepare order data - SAME FORMAT AS FIRST PLUGIN
        $order_data = array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'email' => $order->get_billing_email(),
            'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'products' => array()
        );
        
        // Get products with SKUs - SAME LOGIC AS FIRST PLUGIN
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_sku()) {
                $order_data['products'][] = array(
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name()
                );
            }
        }
        
        if (empty($order_data['products'])) {
            $this->log_sync($order_id, 'failed', __('No products with SKUs found', 'mic-woo-sync'));
            return;
        }
        
        // Send to Laravel - SAME METHOD AS FIRST PLUGIN
        $laravel_url = rtrim($options['laravel_url'], '/') . '/api/v1/woocommerce-sync';
        $payload = wp_json_encode($order_data);
        $signature = base64_encode(hash_hmac('sha256', $payload, $options['webhook_secret'], true));
        
        $response = wp_remote_post($laravel_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-WC-Webhook-Signature' => $signature
            ),
            'body' => $payload,
            'timeout' => 30
        ));
        
        $execution_time = microtime(true) - $start_time;
        
        if (is_wp_error($response)) {
            $this->log_sync($order_id, 'failed', $response->get_error_message(), $execution_time);
            return;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code === 200 || $code === 201) {
            $this->log_sync($order_id, 'success', __('Sync completed successfully', 'mic-woo-sync'), $execution_time);
            
            // Store sync metadata in order
            $order->update_meta_data('_laravel_synced', true);
            $order->update_meta_data('_laravel_sync_time', current_time('mysql'));
            $order->save();
        } else {
            $this->log_sync($order_id, 'failed', "HTTP $code: $body", $execution_time);
        }
    }
    
    /**
 * FIXED: Enhanced logging method with response code parameter
 */
private function log_sync($order_id, $status, $message, $execution_time = 0, $response_code = null, $response_data = '') {
    global $wpdb;
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    $result = $wpdb->insert(
        $this->log_table_name,
        array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'products_data' => wp_json_encode($this->get_order_products($order)),
            'sync_status' => $status,
            'response_code' => $response_code,
            'response_message' => $message,
            'response_data' => $response_data,
            'execution_time' => $execution_time
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f')
    );
    
    // Debug logging if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("MIC Sync Log - Order: $order_id, Status: $status, Message: $message, Insert Result: " . ($result ? 'success' : 'failed'));
    }
}
    
    private function get_order_products($order) {
        $products = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_sku()) {
                $products[] = array(
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name()
                );
            }
        }
        return $products;
    }
    
    // FIXED: Add only ONE order meta box with HPOS compatibility
    public function add_order_meta_boxes() {
        // Get screen - works with both legacy and HPOS
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';
        
        add_meta_box(
            'mic-order-sync',
            __('MIC App Sync Status', 'mic-woo-sync'),
            array($this, 'display_order_sync_meta_box'),
            $screen,
            'side',
            'high'
        );
    }

    /**
     * FIXED: Display the order sync meta box content with proper button logic
     */
    public function display_order_sync_meta_box($post_or_order_object) {
        $order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
        
        if (!$order) {
            return;
        }
        
        $order_id = $order->get_id();
        $synced = $order->get_meta('_laravel_synced');
        $sync_time = $order->get_meta('_laravel_sync_time');
        $sync_attempts = $order->get_meta('_laravel_sync_attempts') ?: 0;
        $last_error = $order->get_meta('_laravel_sync_error');
        
        // Get sync logs for this order
        global $wpdb;
        $sync_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->log_table_name} WHERE order_id = %d ORDER BY sync_time DESC LIMIT 5",
            $order_id
        ));
        
        // Check if plugin is configured
        $is_configured = $this->is_configured();
        
        echo '<div class="mic-sync-meta-box">';
        
        // Main sync status section
        echo '<div class="mic-sync-main-action">';
        echo '<div class="mic-sync-main-action-content">';
        
        if ($is_configured) {
            if ($synced) {
                // SYNCED ORDER - Show status only, NO BUTTON
                echo '<div class="mic-sync-main-status mic-synced">';
                echo '<i class="ri-checkbox-circle-line"></i>';
                echo '<div class="mic-sync-main-text">';
                echo '<h4>' . __('Order Successfully Synced', 'mic-woo-sync') . '</h4>';
                echo '<p>' . __('This order has been synchronized with your Laravel application.', 'mic-woo-sync') . '</p>';
                if ($sync_time) {
                    echo '<p><small>' . sprintf(__('Last sync: %s', 'mic-woo-sync'), esc_html($sync_time)) . '</small></p>';
                }
                echo '</div>';
                echo '</div>';
                
                // NO BUTTON FOR SYNCED ORDERS
                
            } else {
                // NOT SYNCED ORDER - Show sync button OR retry button if there were failed attempts
                $has_failed_attempts = false;
                if (!empty($sync_logs)) {
                    foreach ($sync_logs as $log) {
                        if ($log->sync_status === 'failed') {
                            $has_failed_attempts = true;
                            break;
                        }
                    }
                }
                
                echo '<div class="mic-sync-main-status mic-not-synced">';
                echo '<i class="ri-time-line"></i>';
                echo '<div class="mic-sync-main-text">';
                if ($has_failed_attempts) {
                    echo '<h4>' . __('Order Sync Failed', 'mic-woo-sync') . '</h4>';
                    echo '<p>' . __('Previous sync attempts have failed. You can retry the synchronization.', 'mic-woo-sync') . '</p>';
                } else {
                    echo '<h4>' . __('Order Not Synced', 'mic-woo-sync') . '</h4>';
                    echo '<p>' . __('This order has not been synchronized with your Laravel application yet.', 'mic-woo-sync') . '</p>';
                }
                if ($last_error) {
                    echo '<p><small class="mic-sync-error">' . esc_html($last_error) . '</small></p>';
                }
                echo '</div>';
                echo '</div>';
                
                if ($has_failed_attempts) {
                    // Show retry button for failed orders
                    echo '<button type="button" class="mic-button mic-button-large mic-button-primary mic-retry-btn" data-order-id="' . esc_attr($order_id) . '">';
                    echo '<i class="ri-refresh-line"></i> ' . __('Retry Sync', 'mic-woo-sync');
                    echo '</button>';
                } else {
                    // Show regular sync button for never-synced orders
                    echo '<button type="button" class="mic-button mic-button-large mic-button-primary mic-sync-btn" data-order-id="' . esc_attr($order_id) . '">';
                    echo '<i class="ri-send-plane-line"></i> ' . __('Sync Order Now', 'mic-woo-sync');
                    echo '</button>';
                }
            }
        } else {
            echo '<div class="mic-sync-main-status mic-not-configured">';
            echo '<i class="ri-error-warning-line"></i>';
            echo '<div class="mic-sync-main-text">';
            echo '<h4>' . __('Plugin Not Configured', 'mic-woo-sync') . '</h4>';
            echo '<p>' . __('Please configure the plugin settings to enable sync functionality.', 'mic-woo-sync') . '</p>';
            echo '</div>';
            echo '</div>';
            
            echo '<a href="' . admin_url('admin.php?page=mic-woo-sync') . '" class="mic-button mic-button-large">';
            echo '<i class="ri-settings-line"></i> ' . __('Configure Plugin', 'mic-woo-sync');
            echo '</a>';
        }
        
        echo '</div>'; // Close mic-sync-main-action-content
        echo '</div>'; // Close mic-sync-main-action
        
        // Sync status section
        if ($is_configured) {
            echo '<div class="mic-sync-status-section">';
            echo '<h4><i class="ri-information-line"></i> ' . __('Current Status', 'mic-woo-sync') . '</h4>';
            
            echo '<div class="mic-sync-status-row">';
            echo '<span class="mic-sync-label"><i class="ri-pulse-line"></i> ' . __('Status:', 'mic-woo-sync') . '</span>';
            if ($synced) {
                echo '<span class="mic-sync-value mic-sync-success-value">' . __('Successfully Synced', 'mic-woo-sync') . '</span>';
            } else {
                echo '<span class="mic-sync-value mic-sync-pending-value">' . __('Not Synced', 'mic-woo-sync') . '</span>';
            }
            echo '</div>';
            
            if ($sync_time) {
                echo '<div class="mic-sync-status-row">';
                echo '<span class="mic-sync-label"><i class="ri-time-line"></i> ' . __('Last Sync Time:', 'mic-woo-sync') . '</span>';
                echo '<span class="mic-sync-value">' . esc_html($sync_time) . '</span>';
                echo '</div>';
            }
            
            if ($sync_attempts > 0) {
                echo '<div class="mic-sync-status-row">';
                echo '<span class="mic-sync-label"><i class="ri-repeat-line"></i> ' . __('Total Attempts:', 'mic-woo-sync') . '</span>';
                echo '<span class="mic-sync-value">' . intval($sync_attempts) . '</span>';
                echo '</div>';
            }
            
            if ($last_error && !$synced) {
                echo '<div class="mic-sync-status-row">';
                echo '<span class="mic-sync-label"><i class="ri-error-warning-line"></i> ' . __('Last Error:', 'mic-woo-sync') . '</span>';
                echo '<span class="mic-sync-value mic-sync-error-value">' . esc_html($last_error) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        // Order details section
        echo '<div class="mic-sync-details-section">';
        echo '<h4><i class="ri-file-list-line"></i> ' . __('Order Information', 'mic-woo-sync') . '</h4>';
        echo '<div class="mic-order-details">';
        
        echo '<div class="mic-order-detail-row">';
        echo '<span class="mic-order-label">' . __('Customer:', 'mic-woo-sync') . '</span>';
        echo '<span class="mic-order-value">' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</span>';
        echo '</div>';
        
        echo '<div class="mic-order-detail-row">';
        echo '<span class="mic-order-label">' . __('Email:', 'mic-woo-sync') . '</span>';
        echo '<span class="mic-order-value">' . esc_html($order->get_billing_email()) . '</span>';
        echo '</div>';
        
        echo '<div class="mic-order-detail-row">';
        echo '<span class="mic-order-label">' . __('Total:', 'mic-woo-sync') . '</span>';
        echo '<span class="mic-order-value">' . $order->get_formatted_order_total() . '</span>';
        echo '</div>';
        
        echo '</div>'; // Close mic-order-details
        echo '</div>'; // Close mic-sync-details-section
        
        // Products section
        echo '<div class="mic-products-section">';
        echo '<h4><i class="ri-shopping-bag-line"></i> ' . __('Products', 'mic-woo-sync') . '</h4>';
        
        $products = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $sku = $product->get_sku();
                $products[] = array(
                    'name' => $product->get_name(),
                    'sku' => $sku,
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total()
                );
            }
        }
        
        if (!empty($products)) {
            echo '<div class="mic-products-list">';
            foreach ($products as $product) {
                echo '<div class="mic-product-item">';
                echo '<div class="mic-product-info">';
                echo '<span class="mic-product-name">' . esc_html($product['name']) . '</span>';
                echo '<span class="mic-product-sku">' . __('SKU:', 'mic-woo-sync') . ' ' . esc_html($product['sku'] ?: __('No SKU', 'mic-woo-sync')) . '</span>';
                echo '</div>';
                echo '<div class="mic-product-meta">';
                echo '<span class="mic-product-qty">' . __('Qty:', 'mic-woo-sync') . ' ' . esc_html($product['quantity']) . '</span>';
                echo '<span class="mic-product-price">' . wc_price($product['price']) . '</span>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="mic-no-products">' . __('No products found in this order.', 'mic-woo-sync') . '</p>';
        }
        echo '</div>';
        
        // Sync history section
        if (!empty($sync_logs)) {
            echo '<div class="mic-sync-history-section">';
            echo '<h4><i class="ri-history-line"></i> ' . __('Sync History', 'mic-woo-sync') . '</h4>';
            echo '<div class="mic-sync-history-list">';
            foreach ($sync_logs as $log) {
                $status_class = 'mic-status-' . $log->sync_status;
                $status_icon = $log->sync_status === 'success' ? 'check-circle-line' : 
                              ($log->sync_status === 'failed' ? 'close-circle-line' : 'time-line');
                
                echo '<div class="mic-sync-history-item">';
                echo '<div class="mic-sync-history-header">';
                echo '<span class="mic-status-badge ' . $status_class . '">';
                echo '<i class="ri-' . $status_icon . '"></i> ' . ucfirst($log->sync_status);
                echo '</span>';
                echo '<span class="mic-sync-history-time">' . date('M j, Y H:i:s', strtotime($log->sync_time)) . '</span>';
                echo '</div>';
                if ($log->response_message) {
                    echo '<div class="mic-sync-history-message">' . esc_html($log->response_message) . '</div>';
                }
                if ($log->execution_time > 0) {
                    echo '<div class="mic-sync-history-duration">' . sprintf(__('Duration: %s seconds', 'mic-woo-sync'), number_format($log->execution_time, 3)) . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>'; // Close mic-sync-meta-box
    }
    
    // Keep all the UI methods from the second plugin but fix the sync logic
    public function logs_page() {
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($current_page - 1) * $per_page;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        $logs = $this->get_sync_logs($per_page, $offset, $status_filter);
        
        // Get total count for pagination
        global $wpdb;
        $where = !empty($status_filter) ? $wpdb->prepare(" WHERE sync_status = %s", $status_filter) : '';
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name} $where");
        $total_pages = ceil($total_logs / $per_page);
        
        ?>
        <div class="wrap">
            <div class="mic-logs-header">
                <h1>
                    <i class="ri-file-list-3-line"></i>
                    <?php _e('Sync Logs', 'mic-woo-sync'); ?>
                </h1>
                <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e('Track all synchronization attempts and their status', 'mic-woo-sync'); ?></p>
            </div>
            <div class="mic-card">
                <div class="mic-filter-bar">
                    <form method="get" style="display: flex; gap: 10px; align-items: center;">
                        <input type="hidden" name="page" value="mic-sync-logs">
                        <label for="status-filter"><i class="ri-filter-line"></i> <?php _e('Filter by status:', 'mic-woo-sync'); ?></label>
                        <select name="status" id="status-filter">
                            <option value=""><?php _e('All Status', 'mic-woo-sync'); ?></option>
                            <option value="success" <?php selected($status_filter, 'success'); ?>><?php _e('Success', 'mic-woo-sync'); ?></option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Failed', 'mic-woo-sync'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'mic-woo-sync'); ?></option>
                        </select>
                        <button type="submit" class="button"><?php _e('Filter', 'mic-woo-sync'); ?></button>
                        <?php if (!empty($status_filter)): ?>
                            <a href="<?php echo admin_url('admin.php?page=mic-sync-logs'); ?>" class="button"><?php _e('Clear', 'mic-woo-sync'); ?></a>
                        <?php endif; ?>
                    </form>
                    
                    <div style="margin-left: auto;">
                        <button type="button" class="button button-secondary" id="clear-logs-btn">
                            <i class="ri-delete-bin-line"></i> <?php _e('Clear Logs', 'mic-woo-sync'); ?>
                        </button>
                    </div>
                </div>
                
                <?php if (empty($logs)): ?>
                    <div style="text-align: center; padding: 40px;">
                        <i class="ri-inbox-line" style="font-size: 48px; color: #ccc;"></i>
                        <p><?php _e('No sync logs found.', 'mic-woo-sync'); ?></p>
                    </div>
                <?php else: ?>
                    <!-- Logs table implementation here - keeping the same UI from second plugin -->
                    <?php $this->display_logs_table($logs); ?>
                    
                    <?php if ($total_pages > 1): ?>
                        <!-- Pagination implementation here -->
                        <?php $this->display_pagination($current_page, $total_pages, $status_filter); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private function get_sync_logs($limit = 50, $offset = 0, $status_filter = '') {
        global $wpdb;
        
        $where = '';
        if (!empty($status_filter)) {
            $where = $wpdb->prepare(" WHERE sync_status = %s", $status_filter);
        }
        
        $sql = "SELECT * FROM {$this->log_table_name} $where ORDER BY sync_time DESC LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset));
    }
    
    /**
     * FIXED: Display logs table with clickable products and proper action buttons
     */
    private function display_logs_table($logs) {
        echo '<table class="mic-logs-table">';
        echo '<thead><tr>';
        echo '<th>' . __('Order ID', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Customer', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Products', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Status', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Response', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Time', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Duration', 'mic-woo-sync') . '</th>';
        echo '<th>' . __('Actions', 'mic-woo-sync') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $status_class = 'mic-status-' . $log->sync_status;
            $status_icon = $log->sync_status === 'success' ? 'ri-checkbox-circle-line' : 
                          ($log->sync_status === 'failed' ? 'ri-close-circle-line' : 'ri-time-line');
            
            echo '<tr>';
            echo '<td><strong>#' . esc_html($log->order_id) . '</strong><br>';
            echo '<small><a href="' . admin_url("post.php?post={$log->order_id}&action=edit") . '" target="_blank">';
            echo '<i class="ri-external-link-line"></i> ' . __('View Order', 'mic-woo-sync') . '</a></small></td>';
            echo '<td><strong>' . esc_html($log->customer_name) . '</strong><br>';
            echo '<small>' . esc_html($log->customer_email) . '</small></td>';
            
            $products = json_decode($log->products_data, true);
            echo '<td>';
            if (!empty($products)) {
                // FIXED: Make the entire span clickable (both icon and text)
                echo '<span class="mic-expandable" style="cursor: pointer; display: flex; align-items: center; gap: 6px;">';
                echo '<i class="ri-eye-line"></i>';
                echo '<span>' . count($products) . ' ' . __('product(s)', 'mic-woo-sync') . '</span>';
                echo '</span>';
                echo '<div class="mic-expandable-content">';
                foreach ($products as $product) {
                    echo '• ' . esc_html($product['name']) . ' (' . __('SKU:', 'mic-woo-sync') . ' ' . esc_html($product['sku']) . ')<br>';
                }
                echo '</div>';
            } else {
                echo '<span class="mic-no-products">' . __('No products', 'mic-woo-sync') . '</span>';
            }
            echo '</td>';
            
            echo '<td>';
            echo '<span class="mic-status-badge ' . $status_class . '">';
            echo '<i class="' . $status_icon . '"></i>';
            echo ucfirst($log->sync_status);
            echo '</span>';
            if ($log->response_code) {
                echo '<br><small>HTTP ' . esc_html($log->response_code) . '</small>';
            }
            echo '</td>';
            
            echo '<td><div>' . esc_html($log->response_message) . '</div></td>';
            echo '<td><div>' . date('M j, Y', strtotime($log->sync_time)) . '</div>';
            echo '<small>' . date('H:i:s', strtotime($log->sync_time)) . '</small></td>';
            echo '<td>';
            if ($log->execution_time > 0) {
                echo number_format($log->execution_time, 3) . 's';
            } else {
                echo '-';
            }
            echo '</td>';
            
            // FIXED: Actions column - only show retry for failed syncs
            echo '<td class="mic-actions">';
            if ($log->sync_status === 'failed') {
                // Add retry button for failed syncs only
                echo '<button type="button" class="mic-button mic-button-small mic-retry-btn" data-order-id="' . esc_attr($log->order_id) . '" data-log-id="' . esc_attr($log->id) . '">';
                echo '<i class="ri-refresh-line"></i> ' . __('Retry', 'mic-woo-sync');
                echo '</button>';
            } else {
                // No action for successful or pending syncs
                echo '<span class="mic-no-action">-</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    private function display_pagination($current_page, $total_pages, $status_filter) {
        echo '<div class="mic-pagination">';
        $base_url = admin_url('admin.php?page=mic-sync-logs');
        if (!empty($status_filter)) {
            $base_url .= '&status=' . urlencode($status_filter);
        }
        
        if ($current_page > 1) {
            echo '<a href="' . $base_url . '&paged=' . ($current_page - 1) . '">';
            echo '<i class="ri-arrow-left-line"></i> ' . __('Previous', 'mic-woo-sync');
            echo '</a>';
        }
        
        for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
            if ($i == $current_page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                echo '<a href="' . $base_url . '&paged=' . $i . '">' . $i . '</a>';
            }
        }
        
        if ($current_page < $total_pages) {
            echo '<a href="' . $base_url . '&paged=' . ($current_page + 1) . '">';
            echo __('Next', 'mic-woo-sync') . ' <i class="ri-arrow-right-line"></i>';
            echo '</a>';
        }
        echo '</div>';
    }
    
    public function clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'mic-woo-sync'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mic_clear_logs')) {
            wp_send_json_error(__('Security check failed', 'mic-woo-sync'));
            return;
        }
        
        global $wpdb;
        
        $days = intval($_POST['days']);
        if ($days > 0) {
            // Clear logs older than X days
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->log_table_name} WHERE sync_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        } else {
            // Clear all logs
            $result = $wpdb->query("DELETE FROM {$this->log_table_name}");
        }
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => sprintf(__('Cleared %d log entries', 'mic-woo-sync'), $result)
            ));
        } else {
            wp_send_json_error(__('Failed to clear logs', 'mic-woo-sync'));
        }
    }
    
    // Keep analytics page from second plugin
    public function analytics_page() {
        $stats = $this->get_sync_stats();
        $success_rate = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) : 0;
        
        // Debug: Check if stats are being retrieved
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MIC Analytics Stats: ' . print_r($stats, true));
        }
        
        ?>
        <div class="wrap">
            <div class="mic-analytics-header">
                <div class="mic-header-content">
                    <div class="mic-header-left">
                        <h1>
                            <i class="ri-bar-chart-line"></i>
                            <?php _e('Analytics Dashboard', 'mic-woo-sync'); ?>
                        </h1>
                        <p><?php _e('Comprehensive sync performance and statistics overview', 'mic-woo-sync'); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="mic-stats-grid">
                <!-- Total Syncs -->
                <div class="mic-stat-card mic-stat-total">
                    <div class="mic-stat-icon">
                        <i class="ri-database-2-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('TOTAL SYNCS', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo number_format($stats['total']); ?></div>
                        <div class="mic-stat-description"><?php _e('All time synchronization attempts', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge"><?php _e('All Time', 'mic-woo-sync'); ?></div>
                </div>
                
                <!-- Successful -->
                <div class="mic-stat-card mic-stat-success">
                    <div class="mic-stat-icon">
                        <i class="ri-check-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('SUCCESSFUL', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo number_format($stats['success']); ?></div>
                        <div class="mic-stat-description"><?php _e('Orders synced successfully', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge mic-badge-success"><?php _e('Active', 'mic-woo-sync'); ?></div>
                </div>
                
                <!-- Failed -->
                <div class="mic-stat-card mic-stat-failed">
                    <div class="mic-stat-icon">
                        <i class="ri-close-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('FAILED', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo number_format($stats['failed']); ?></div>
                        <div class="mic-stat-description"><?php _e('Synchronization failures', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge mic-badge-failed"><?php _e('Issue', 'mic-woo-sync'); ?></div>
                </div>
                
                <!-- Success Rate -->
                <div class="mic-stat-card mic-stat-rate">
                    <div class="mic-stat-icon">
                        <i class="ri-percent-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('SUCCESS RATE', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo $success_rate; ?>%</div>
                        <div class="mic-stat-description"><?php _e('Overall sync reliability', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge mic-badge-rate"><?php _e('Needs Attention', 'mic-woo-sync'); ?></div>
                </div>
                
                <!-- Pending -->
                <div class="mic-stat-card mic-stat-pending">
                    <div class="mic-stat-icon">
                        <i class="ri-time-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('PENDING', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo number_format($stats['pending']); ?></div>
                        <div class="mic-stat-description"><?php _e('Awaiting synchronization', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge mic-badge-pending"><?php _e('Waiting', 'mic-woo-sync'); ?></div>
                </div>
                
                <!-- Recent Activity -->
                <div class="mic-stat-card mic-stat-recent">
                    <div class="mic-stat-icon">
                        <i class="ri-calendar-line"></i>
                    </div>
                    <div class="mic-stat-content">
                        <div class="mic-stat-label"><?php _e('RECENT ACTIVITY', 'mic-woo-sync'); ?></div>
                        <div class="mic-stat-number"><?php echo number_format($stats['recent']); ?></div>
                        <div class="mic-stat-description"><?php _e('Syncs in the last week', 'mic-woo-sync'); ?></div>
                    </div>
                    <div class="mic-stat-badge mic-badge-recent"><?php _e('7 Days', 'mic-woo-sync'); ?></div>
                </div>
            </div>
            

            
            <?php if (!empty($stats['daily'])): ?>
            <div class="mic-daily-activity">
                <div class="mic-section-header">
                    <h2><i class="ri-calendar-line"></i> <?php _e('Daily Activity Table (Last 7 Days)', 'mic-woo-sync'); ?></h2>
                    <p><?php _e('Detailed breakdown of synchronization activity by day', 'mic-woo-sync'); ?></p>
                </div>
                <div class="mic-table-container">
                    <table class="mic-daily-table">
                        <thead>
                            <tr>
                                <th><i class="ri-calendar-line"></i> <?php _e('Date', 'mic-woo-sync'); ?></th>
                                <th><i class="ri-bar-chart-line"></i> <?php _e('Total', 'mic-woo-sync'); ?></th>
                                <th><i class="ri-check-line"></i> <?php _e('Success', 'mic-woo-sync'); ?></th>
                                <th><i class="ri-close-line"></i> <?php _e('Failed', 'mic-woo-sync'); ?></th>
                                <th><i class="ri-percent-line"></i> <?php _e('Success Rate', 'mic-woo-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['daily'] as $day): 
                                $day_success_rate = $day->total > 0 ? round(($day->success / $day->total) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td class="mic-date-cell">
                                        <div class="mic-date-info">
                                            <span class="mic-date-day"><?php echo date('j', strtotime($day->date)); ?></span>
                                            <span class="mic-date-month"><?php echo date('M Y', strtotime($day->date)); ?></span>
                                        </div>
                                    </td>
                                    <td class="mic-total-cell">
                                        <span class="mic-number-badge mic-total-badge"><?php echo number_format($day->total); ?></span>
                                    </td>
                                    <td class="mic-success-cell">
                                        <span class="mic-number-badge mic-success-badge"><?php echo number_format($day->success); ?></span>
                                    </td>
                                    <td class="mic-failed-cell">
                                        <span class="mic-number-badge mic-failed-badge"><?php echo number_format($day->failed); ?></span>
                                    </td>
                                    <td class="mic-rate-cell">
                                        <span class="mic-rate-badge" style="background: <?php echo $day_success_rate >= 80 ? 'rgba(0, 163, 42, 0.1)' : ($day_success_rate >= 50 ? 'rgba(219, 166, 23, 0.1)' : 'rgba(214, 54, 56, 0.1)'); ?>; color: <?php echo $day_success_rate >= 80 ? '#00a32a' : ($day_success_rate >= 50 ? '#dba617' : '#d63638'); ?>;">
                                            <?php echo $day_success_rate; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="mic-no-data">
                <div class="mic-no-data-content">
                    <i class="ri-bar-chart-2-line"></i>
                    <h3><?php _e('No Data Available', 'mic-woo-sync'); ?></h3>
                    <p><?php _e('Start by processing some orders to see sync statistics', 'mic-woo-sync'); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php
    }
    
    private function get_sync_stats() {
        global $wpdb;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->log_table_name}'") != $this->log_table_name) {
            return array(
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'pending' => 0,
                'recent' => 0,
                'avg_execution_time' => 0,
                'daily' => array()
            );
        }
        
        $stats = array();
        
        try {
            // Total syncs
            $stats['total'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name}"));
            
            // Success rate
            $stats['success'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name} WHERE sync_status = 'success'"));
            $stats['failed'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name} WHERE sync_status = 'failed'"));
            $stats['pending'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name} WHERE sync_status = 'pending'"));
            
            // Recent activity (last 7 days)
            $stats['recent'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name} WHERE sync_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)"));
            
            // Average execution time
            $avg_time = $wpdb->get_var("SELECT AVG(execution_time) FROM {$this->log_table_name} WHERE execution_time > 0");
            $stats['avg_execution_time'] = floatval($avg_time);
            
            // Daily stats for last 7 days
            $daily_stats = $wpdb->get_results("
                SELECT 
                    DATE(sync_time) as date,
                    COUNT(*) as total,
                    SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM {$this->log_table_name} 
                WHERE sync_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(sync_time)
                ORDER BY date DESC
            ");
            
            $stats['daily'] = $daily_stats ? $daily_stats : array();
            
        } catch (Exception $e) {
            error_log('MIC Sync Stats Error: ' . $e->getMessage());
            $stats = array(
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'pending' => 0,
                'recent' => 0,
                'avg_execution_time' => 0,
                'daily' => array()
            );
        }
        
        return $stats;
    }
    
    // Manual sync
    public function manual_sync() {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'manual_sync')) {
            wp_die(__('Security check failed', 'mic-woo-sync'));
        }
        
        $order_id = intval($_GET['order_id']);
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'mic-woo-sync'));
        }
        
        $order = wc_get_order($order_id);
        if ($order) {
            $this->process_order_sync($order_id, $order);
        }
        
        // Redirect back to order page
        wp_redirect(admin_url("post.php?post=$order_id&action=edit"));
        exit;
    }
    
    // Bulk sync actions
    public function add_bulk_sync_action($actions) {
        if ($this->is_configured()) {
            $actions['mic_bulk_sync'] = __('Sync to App', 'mic-woo-sync');
        }
        return $actions;
    }
    
    public function handle_bulk_sync($redirect_to, $action, $post_ids) {
        if ($action !== 'mic_bulk_sync') {
            return $redirect_to;
        }
        
        $synced_count = 0;
        foreach ($post_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && !$order->get_meta('_laravel_synced')) {
                $this->process_order_sync($order_id, $order);
                $synced_count++;
            }
        }
        
        $redirect_to = add_query_arg('mic_synced', $synced_count, $redirect_to);
        return $redirect_to;
    }
    
    public function bulk_sync_notice() {
        if (!empty($_REQUEST['mic_synced'])) {
            $synced_count = intval($_REQUEST['mic_synced']);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>';
            printf(_n('%d order synced to Laravel app.', '%d orders synced to Laravel app.', $synced_count, 'mic-woo-sync'), $synced_count);
            echo '</p></div>';
        }
    }
    
    /**
     * FIXED: Add sync status column to orders list
     */
    public function add_sync_status_column($columns) {
        $columns['mic_sync_status'] = __('Sync Status', 'mic-woo-sync');
        return $columns;
    }
    
    /**
     * FIXED: Display sync status column with proper button logic
     */
    public function display_sync_status_column($column, $post_id) {
        if ($column !== 'mic_sync_status') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $synced = $order->get_meta('_laravel_synced');
        $sync_time = $order->get_meta('_laravel_sync_time');
        
        // Check if plugin is configured
        $options = get_option($this->option_name);
        $is_configured = !empty($options['laravel_url']) && !empty($options['webhook_secret']);
        
        echo '<div class="mic-sync-column-info">';
        
        if ($synced) {
            // SYNCED ORDER - Show synced status only, NO BUTTON
            echo '<div class="mic-sync-status mic-synced">';
            echo '<span class="mic-sync-badge mic-synced-badge">';
            echo '<i class="ri-checkbox-circle-line"></i> ' . __('Synced', 'mic-woo-sync');
            echo '</span>';
            if ($sync_time) {
                echo '<br><small class="mic-sync-time">' . date('M j, Y H:i', strtotime($sync_time)) . '</small>';
            }
            echo '</div>';
            
            // NO BUTTON FOR SYNCED ORDERS
            
        } else {
            // NOT SYNCED ORDER - Show not synced status with sync button
            echo '<div class="mic-sync-status mic-not-synced">';
            echo '<span class="mic-sync-badge mic-not-synced-badge">';
            echo '<i class="ri-time-line"></i> ' . __('Not Synced', 'mic-woo-sync');
            echo '</span>';
            echo '</div>';
            
            // Check if there were failed attempts
            global $wpdb;
            $failed_attempts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log_table_name} WHERE order_id = %d AND sync_status = 'failed'",
                $post_id
            ));
            
            if ($is_configured) {
                if ($failed_attempts > 0) {
                    // Show RETRY button for orders with failed attempts
                    echo '<button type="button" class="mic-sync-btn mic-retry-btn" data-order-id="' . esc_attr($post_id) . '" title="' . __('Retry Sync', 'mic-woo-sync') . '">';
                    echo '<i class="ri-refresh-line"></i>';
                    echo '</button>';
                } else {
                    // Show SYNC button for never-synced orders
                    echo '<button type="button" class="mic-sync-btn mic-sync-now-btn" data-order-id="' . esc_attr($post_id) . '" title="' . __('Sync Now', 'mic-woo-sync') . '">';
                    echo '<i class="ri-send-plane-line"></i>';
                    echo '</button>';
                }
            }
        }
        
        echo '</div>';
    }
}

// Initialize the plugin
new MICWooToAppSyncPlugin();