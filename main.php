<?php
/**
 * Plugin Name: Made in China App Sync
 * Description: Syncs WooCommerce paid orders with Made in China app.
 * Version: 1.2.0
 * Author: Yassir Zbida
 * Text Domain: made-in-china-app-sync
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Load plugin text domain for translations
 */
add_action( 'plugins_loaded', 'mic_load_textdomain' );

function mic_load_textdomain() {
    load_plugin_textdomain( 'made-in-china-app-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Enqueue admin assets (CSS and JS)
 */
add_action( 'admin_enqueue_scripts', 'mic_enqueue_admin_assets' );

function mic_enqueue_admin_assets( $hook ) {
    // Only load on our plugin pages
    if ( strpos( $hook, 'mic-' ) === false && $hook !== 'toplevel_page_mic-app-sync' ) {
        return;
    }
    
    $plugin_url = plugin_dir_url( __FILE__ );
    $plugin_version = '1.2.0';
    
    // Enqueue CSS
    wp_enqueue_style(
        'mic-admin-css',
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
    
    // Enqueue Chart.js for analytics page
    if ( $hook === 'mic-app-sync_page_mic-analytics' ) {
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array(),
            '3.9.1',
            true
        );
    }
    
    // Enqueue our admin JavaScript
    wp_enqueue_script(
        'mic-admin-js',
        $plugin_url . 'assets/js/admin.js',
        array( 'jquery' ),
        $plugin_version,
        true
    );
    
    // Localize script with translated strings
    wp_localize_script( 'mic-admin-js', 'micStrings', array(
        'testing' => __( 'Testing...', 'made-in-china-app-sync' ),
        'testingConnection' => __( 'Testing connection...', 'made-in-china-app-sync' ),
        'connectionSuccessful' => __( 'Connection successful! Laravel app is responding.', 'made-in-china-app-sync' ),
        'connectionFailed' => __( 'Connection failed:', 'made-in-china-app-sync' ),
        'connectionError' => __( 'Connection error:', 'made-in-china-app-sync' ),
        'unknownError' => __( 'Unknown error', 'made-in-china-app-sync' ),
        'testConnection' => __( 'Test Connection', 'made-in-china-app-sync' ),
        'manualSyncConfirm' => __( 'Are you sure you want to manually sync this order to the Laravel app?', 'made-in-china-app-sync' ),
        'manualSyncUrl' => wp_nonce_url( admin_url( 'admin-ajax.php?action=mic_manual_sync&order_id=' ), 'mic_manual_sync' ),
        'willSync' => __( 'Will sync', 'made-in-china-app-sync' ),
        'wontSync' => __( 'Won\'t sync', 'made-in-china-app-sync' ),
        'skuWarning' => __( '⚠️ This product has no SKU and will not sync with the Made in China Laravel app.\n\nDo you want to continue anyway?', 'made-in-china-app-sync' ),
        'ajaxurl' => admin_url( 'admin-ajax.php' )
    ) );
    
    // Localize script for logs page
    wp_localize_script( 'mic-admin-js', 'micLogsStrings', array(
        'enterDays' => __( 'Enter number of days to keep logs (0 = clear all logs, 30 = keep last 30 days):', 'made-in-china-app-sync' ),
        'validNumber' => __( 'Please enter a valid number (0 or higher)', 'made-in-china-app-sync' ),
        'clearAllConfirm' => __( 'Are you sure you want to clear ALL sync logs? This cannot be undone.', 'made-in-china-app-sync' ),
        'clearOldConfirm' => __( 'Are you sure you want to clear logs older than %d days? This cannot be undone.', 'made-in-china-app-sync' ),
        'error' => __( 'Error:', 'made-in-china-app-sync' ),
        'errorClearing' => __( 'Error clearing logs:', 'made-in-china-app-sync' ),
        'nonce' => wp_create_nonce( 'mic_clear_logs' )
    ) );
}

/**
 * Hook into WooCommerce payment completion
 * This runs when payment is confirmed.
 */
add_action( 'woocommerce_payment_complete', 'mic_sync_order_to_laravel', 10, 1 );

function mic_sync_order_to_laravel( $order_id ) {
    $start_time = microtime(true);
    
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        error_log("Laravel Sync Error: Order $order_id not found.");
        return;
    }

    // Collect customer data
    $email = $order->get_billing_email();
    $name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

    // Collect products with SKU mapping
    $products = [];
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product ) {
            $sku = $product->get_sku();
            
            // Skip products without SKU (these won't sync properly)
            if ( empty( $sku ) ) {
                error_log("Laravel Sync Warning: Product {$product->get_name()} has no SKU, skipping.");
                continue;
            }
            
            $products[] = [
                'sku'  => $sku,
                'name' => $product->get_name(),
            ];
        }
    }

    // Skip if no valid products found
    if ( empty( $products ) ) {
        $execution_time = microtime(true) - $start_time;
        mic_log_sync_attempt($order_id, $email, $name, [], 'failed', null, 'No products with valid SKUs found', '', $execution_time);
        error_log("Laravel Sync Error: Order $order_id has no products with valid SKUs.");
        return;
    }

    // Prepare payload
    $data = [
        'order_id' => $order->get_id(),
        'email'    => $email,
        'name'     => $name,
        'products' => $products
    ];

    // Get configuration from WordPress options
    $laravel_url = get_option( 'mic_laravel_app_url' );
    $webhook_secret = get_option( 'mic_webhook_secret' );
    
    // Calculate webhook signature for security
    $payload = wp_json_encode( $data );
    $signature = base64_encode( hash_hmac( 'sha256', $payload, $webhook_secret, true ) );

    // Send request to Laravel API
    $response = wp_remote_post( $laravel_url . '/api/v1/woocommerce-sync', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'X-WC-Webhook-Signature' => $signature,
            'User-Agent'    => 'WooCommerce-MadeInChina-Sync/1.2.0'
        ],
        'body'    => $payload,
        'timeout' => 30,
    ]);

    $execution_time = microtime(true) - $start_time;

    // Error handling
    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        mic_log_sync_attempt($order_id, $email, $name, $products, 'failed', null, $error_message, '', $execution_time);
        error_log('Laravel Sync Error: ' . $error_message);
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code !== 200 && $code !== 201 ) {
        mic_log_sync_attempt($order_id, $email, $name, $products, 'failed', $code, "HTTP $code", $body, $execution_time);
        error_log("Laravel Sync Failed: HTTP $code - $body");
        
        // Log additional details for debugging
        error_log("Laravel Sync Debug - Order ID: $order_id, Email: $email, Products: " . wp_json_encode( $products ));
    } else {
        // Parse response for better logging
        $response_data = json_decode( $body, true );
        $status = $response_data['data']['status'] ?? 'unknown';
        
        mic_log_sync_attempt($order_id, $email, $name, $products, 'success', $code, 'Sync completed successfully', $body, $execution_time);
        error_log("Laravel Sync Success: Order $order_id synced successfully. Status: $status");
        
        // Store sync metadata in order
        $order->update_meta_data( '_laravel_synced', true );
        $order->update_meta_data( '_laravel_sync_time', current_time( 'mysql' ) );
        $order->update_meta_data( '_laravel_sync_status', $status );
        $order->save();
    }
}

/**
 * Add admin settings page for configuration
 */
add_action( 'admin_menu', 'mic_add_admin_menu' );

function mic_add_admin_menu() {
    add_menu_page(
        'Made in China App Sync',
        'MIC App Sync',
        'manage_options',
        'mic-app-sync',
        'mic_admin_page',
        'dashicons-cloud',
        30
    );
    
    add_submenu_page(
        'mic-app-sync',
        'Settings',
        'Settings',
        'manage_options',
        'mic-app-sync',
        'mic_admin_page'
    );
    
    add_submenu_page(
        'mic-app-sync',
        'Sync Logs',
        'Sync Logs',
        'manage_options',
        'mic-sync-logs',
        'mic_logs_page'
    );
    
    add_submenu_page(
        'mic-app-sync',
        'Analytics',
        'Analytics',
        'manage_options',
        'mic-analytics',
        'mic_analytics_page'
    );
}

/**
 * Check if plugin is properly configured
 */
function mic_is_configured() {
    $laravel_url = get_option( 'mic_laravel_app_url' );
    $webhook_secret = get_option( 'mic_webhook_secret' );
    
    return !empty($laravel_url) && 
           !empty($webhook_secret) && 
           strlen($webhook_secret) > 10; // Just ensure secret is reasonably long
}

/**
 * Create logs table
 */
function mic_create_logs_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
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
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * Log sync attempt
 */
function mic_log_sync_attempt($order_id, $customer_email, $customer_name, $products, $status, $response_code = null, $response_message = '', $response_data = '', $execution_time = 0) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    
    $wpdb->insert(
        $table_name,
        array(
            'order_id' => $order_id,
            'customer_email' => $customer_email,
            'customer_name' => $customer_name,
            'products_data' => wp_json_encode($products),
            'sync_status' => $status,
            'response_code' => $response_code,
            'response_message' => $response_message,
            'response_data' => $response_data,
            'execution_time' => $execution_time
        ),
        array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f')
    );
}

/**
 * Get sync logs with pagination
 */
function mic_get_sync_logs($limit = 50, $offset = 0, $status_filter = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    
    $where = '';
    if (!empty($status_filter)) {
        $where = $wpdb->prepare(" WHERE sync_status = %s", $status_filter);
    }
    
    $sql = "SELECT * FROM $table_name $where ORDER BY sync_time DESC LIMIT %d OFFSET %d";
    
    return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset));
}

/**
 * Get sync statistics
 */
function mic_get_sync_stats() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    
    // Check if table exists
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        // Table doesn't exist, return empty stats
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
        $stats['total'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ) );
        
        // Success rate
        $stats['success'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE sync_status = 'success'" ) );
        $stats['failed'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE sync_status = 'failed'" ) );
        $stats['pending'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE sync_status = 'pending'" ) );
        
        // Recent activity (last 7 days)
        $stats['recent'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE sync_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)" ) );
        
        // Average execution time
        $avg_time = $wpdb->get_var( "SELECT AVG(execution_time) FROM $table_name WHERE execution_time > 0" );
        $stats['avg_execution_time'] = floatval( $avg_time );
        
        // Daily stats for last 7 days
        $daily_stats = $wpdb->get_results( "
            SELECT 
                DATE(sync_time) as date,
                COUNT(*) as total,
                SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM $table_name 
            WHERE sync_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(sync_time)
            ORDER BY date DESC
        " );
        
        $stats['daily'] = $daily_stats ? $daily_stats : array();
        
    } catch ( Exception $e ) {
        // If there's an error, return default stats
        error_log( 'MIC Sync Stats Error: ' . $e->getMessage() );
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

/**
 * Admin page content with improved UI using Remix Icons
 */
function mic_admin_page() {
    // Save settings
    if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['mic_nonce'], 'mic_save_settings' ) ) {
        update_option( 'mic_laravel_app_url', sanitize_url( $_POST['laravel_app_url'] ) );
        update_option( 'mic_webhook_secret', sanitize_text_field( $_POST['webhook_secret'] ) );
        echo '<div class="notice notice-success is-dismissible"><p><i class="ri-checkbox-circle-line"></i> ' . __( 'Settings saved successfully!', 'made-in-china-app-sync' ) . '</p></div>';
    }

    $laravel_url = get_option( 'mic_laravel_app_url', '' );
    $webhook_secret = get_option( 'mic_webhook_secret', '' );
    $is_configured = mic_is_configured();
    
    ?>

    <div class="wrap">
        <div class="mic-admin-header">
            <h1>
                <i class="ri-cloud-line"></i>
                <?php _e( 'Made in China App Sync', 'made-in-china-app-sync' ); ?>
                <?php if ( $is_configured ): ?>
                    <span class="mic-status-badge mic-status-configured">
                    <i class="ri-checkbox-circle-line"></i><?php _e( 'Configured', 'made-in-china-app-sync' ); ?>
                    </span>
                <?php else: ?>
                    <span class="mic-status-badge mic-status-not-configured">
                        <i class="ri-error-warning-fill"></i> <?php _e( 'Needs Configuration', 'made-in-china-app-sync' ); ?>
                    </span>
                <?php endif; ?>
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e( 'Sync WooCommerce orders with your Laravel ebook application', 'made-in-china-app-sync' ); ?></p>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-settings-3-line"></i> <?php _e( 'Configuration', 'made-in-china-app-sync' ); ?></h2>
            
            <form method="post">
                <?php wp_nonce_field( 'mic_save_settings', 'mic_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="laravel_app_url">
                                <i class="ri-global-line"></i> <?php _e( 'Laravel App URL', 'made-in-china-app-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="url" id="laravel_app_url" name="laravel_app_url" 
                                   value="<?php echo esc_attr( $laravel_url ); ?>" 
                                   class="mic-input" 
                                   placeholder="https://app.madeinchina-ebook.com" 
                                   required />
                            <p class="description">
                                <i class="ri-information-line"></i>
                                <?php _e( 'The base URL of your Laravel application (without /dashboard)', 'made-in-china-app-sync' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="webhook_secret">
                                <i class="ri-key-2-line"></i> <?php _e( 'Webhook Secret', 'made-in-china-app-sync' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" id="webhook_secret" name="webhook_secret" 
                                   value="<?php echo esc_attr( $webhook_secret ); ?>" 
                                   class="mic-input" 
                                   placeholder="<?php _e( 'Enter your secure webhook secret', 'made-in-china-app-sync' ); ?>"
                                   required />
                            <p class="description">
                                <i class="ri-information-line"></i>
                                <?php _e( 'The secret key configured in your Laravel app\'s .env file (WOOCOMMERCE_WEBHOOK_SECRET)', 'made-in-china-app-sync' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="submit" class="mic-button">
                        <i class="ri-save-line"></i> <?php _e( 'Save Settings', 'made-in-china-app-sync' ); ?>
                    </button>
                </p>
            </form>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-pulse-line"></i> <?php _e( 'Connection Test', 'made-in-china-app-sync' ); ?></h2>
            <p><?php _e( 'Test the connection to your Laravel application:', 'made-in-china-app-sync' ); ?></p>
            <button type="button" class="mic-button" id="test-btn">
                <i class="ri-wifi-line"></i> <?php _e( 'Test Connection', 'made-in-china-app-sync' ); ?>
            </button>
            <div id="test-result" class="mic-test-result"></div>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-guide-line"></i> <?php _e( 'Setup Guide', 'made-in-china-app-sync' ); ?></h2>
            
            <div class="mic-guide">
                <h3><i class="ri-number-1"></i> <?php _e( 'Configure Laravel App', 'made-in-china-app-sync' ); ?></h3>
                <p><?php _e( 'In your Laravel app\'s', 'made-in-china-app-sync' ); ?> <code>.env</code> <?php _e( 'file, add:', 'made-in-china-app-sync' ); ?></p>
                <pre><code>WOOCOMMERCE_ENABLED=true
WOOCOMMERCE_WEBHOOK_SECRET=<?php echo esc_html( $webhook_secret ?: 'your-secret-here' ); ?></code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-2"></i> <?php _e( 'Run Migrations', 'made-in-china-app-sync' ); ?></h3>
                <p><?php _e( 'In your Laravel app, run:', 'made-in-china-app-sync' ); ?></p>
                <pre><code>php artisan migrate</code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-3"></i> <?php _e( 'Create Route', 'made-in-china-app-sync' ); ?></h3>
                <p><?php _e( 'Ensure your Laravel app has the sync endpoint:', 'made-in-china-app-sync' ); ?></p>
                <pre><code>Route::post('/api/v1/woocommerce-sync', [WooCommerceController::class, 'sync']);</code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-4"></i> <?php _e( 'Product SKUs', 'made-in-china-app-sync' ); ?></h3>
                <p><?php _e( 'Make sure your WooCommerce products have SKUs that match your Laravel ebook identifiers.', 'made-in-china-app-sync' ); ?></p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Logs page content
 */
function mic_logs_page() {
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($current_page - 1) * $per_page;
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    
    $logs = mic_get_sync_logs($per_page, $offset, $status_filter);
    
    // Get total count for pagination
    global $wpdb;
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    $where = !empty($status_filter) ? $wpdb->prepare(" WHERE sync_status = %s", $status_filter) : '';
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
    $total_pages = ceil($total_logs / $per_page);
    
    ?>

    <div class="wrap">
        <div class="mic-logs-header">
            <h1>
                <i class="ri-file-list-3-line"></i>
                <?php _e( 'Sync Logs', 'made-in-china-app-sync' ); ?>
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e( 'Track all synchronization attempts and their status', 'made-in-china-app-sync' ); ?></p>
        </div>
        
        <div class="mic-card">
            <div class="mic-filter-bar">
                <form method="get" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="mic-sync-logs">
                    <label for="status-filter"><i class="ri-filter-line"></i> <?php _e( 'Filter by status:', 'made-in-china-app-sync' ); ?></label>
                    <select name="status" id="status-filter">
                        <option value=""><?php _e( 'All Status', 'made-in-china-app-sync' ); ?></option>
                        <option value="success" <?php selected($status_filter, 'success'); ?>><?php _e( 'Success', 'made-in-china-app-sync' ); ?></option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e( 'Failed', 'made-in-china-app-sync' ); ?></option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e( 'Pending', 'made-in-china-app-sync' ); ?></option>
                    </select>
                    <button type="submit" class="button"><?php _e( 'Filter', 'made-in-china-app-sync' ); ?></button>
                    <?php if (!empty($status_filter)): ?>
                        <a href="<?php echo admin_url('admin.php?page=mic-sync-logs'); ?>" class="button"><?php _e( 'Clear', 'made-in-china-app-sync' ); ?></a>
                    <?php endif; ?>
                </form>
                
                <div style="margin-left: auto;">
                    <button type="button" class="button button-secondary" id="clear-logs-btn">
                        <i class="ri-delete-bin-line"></i> <?php _e( 'Clear Logs', 'made-in-china-app-sync' ); ?>
                    </button>
                </div>
            </div>
            
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="ri-inbox-line" style="font-size: 48px; color: #ccc;"></i>
                    <p><?php _e( 'No sync logs found.', 'made-in-china-app-sync' ); ?></p>
                </div>
            <?php else: ?>
                <table class="mic-logs-table">
                    <thead>
                        <tr>
                            <th><?php _e( 'Order ID', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Customer', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Products', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Status', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Response', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Time', 'made-in-china-app-sync' ); ?></th>
                            <th><?php _e( 'Duration', 'made-in-china-app-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo esc_html($log->order_id); ?></strong>
                                    <br>
                                    <small>
                                        <a href="<?php echo admin_url("post.php?post={$log->order_id}&action=edit"); ?>" target="_blank">
                                            <i class="ri-external-link-line"></i> <?php _e( 'View Order', 'made-in-china-app-sync' ); ?>
                                        </a>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($log->customer_name); ?></strong>
                                    <br>
                                    <small><?php echo esc_html($log->customer_email); ?></small>
                                </td>
                                <td>
                                    <?php 
                                    $products = json_decode($log->products_data, true);
                                    if (!empty($products)):
                                    ?>
                                        <span class="mic-expandable">
                                            <i class="ri-eye-line"></i> <?php echo count($products); ?> <?php _e( 'product(s)', 'made-in-china-app-sync' ); ?>
                                        </span>
                                        <div class="mic-expanded-data">
                                            <?php foreach ($products as $product): ?>
                                                • <?php echo esc_html($product['name']); ?> (SKU: <?php echo esc_html($product['sku']); ?>)<br>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;"><?php _e( 'No products', 'made-in-china-app-sync' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $status_class = 'mic-status-' . $log->sync_status;
                                    $status_icon = $log->sync_status === 'success' ? 'ri-check-circle-fill' : 
                                                  ($log->sync_status === 'failed' ? 'ri-close-circle-fill' : 'ri-time-fill');
                                    ?>
                                    <span class="mic-status-badge <?php echo $status_class; ?>">
                                        <i class="<?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($log->sync_status); ?>
                                    </span>
                                    <?php if ($log->response_code): ?>
                                        <br><small>HTTP <?php echo esc_html($log->response_code); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo esc_html($log->response_message); ?></div>
                                    <?php if (!empty($log->response_data) && $log->response_data !== $log->response_message): ?>
                                        <span class="mic-expandable">
                                            <i class="ri-code-line"></i> <?php _e( 'Response Data', 'made-in-china-app-sync' ); ?>
                                        </span>
                                        <div class="mic-expanded-data">
                                            <?php echo esc_html($log->response_data); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo date('M j, Y', strtotime($log->sync_time)); ?></div>
                                    <small><?php echo date('H:i:s', strtotime($log->sync_time)); ?></small>
                                </td>
                                <td>
                                    <?php if ($log->execution_time > 0): ?>
                                        <?php echo number_format($log->execution_time, 3); ?>s
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="mic-pagination">
                        <?php
                        $base_url = admin_url('admin.php?page=mic-sync-logs');
                        if (!empty($status_filter)) {
                            $base_url .= '&status=' . urlencode($status_filter);
                        }
                        
                        if ($current_page > 1):
                        ?>
                            <a href="<?php echo $base_url . '&paged=' . ($current_page - 1); ?>">
                                <i class="ri-arrow-left-line"></i> <?php _e( 'Previous', 'made-in-china-app-sync' ); ?>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <?php if ($i == $current_page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo $base_url . '&paged=' . $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo $base_url . '&paged=' . ($current_page + 1); ?>">
                                <?php _e( 'Next', 'made-in-china-app-sync' ); ?> <i class="ri-arrow-right-line"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Analytics page content - Improved UI
 */
function mic_analytics_page() {
    $stats = mic_get_sync_stats();
    $success_rate = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) : 0;
    ?>

    <div class="wrap">
        <div class="mic-analytics-header">
            <h1>
                <i class="ri-bar-chart-line"></i>
                <?php _e( 'Analytics Dashboard', 'made-in-china-app-sync' ); ?>
            </h1>
            <p><?php _e( 'Comprehensive sync performance and statistics overview', 'made-in-china-app-sync' ); ?></p>
        </div>
        
        <!-- Enhanced Stats Grid -->
        <div class="mic-stats-grid">
            <!-- Total Syncs -->
            <div class="mic-stat-card mic-stat-total">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-database-2-line"></i>
                    </div>
                    <div class="mic-stat-trend"><?php _e( 'All Time', 'made-in-china-app-sync' ); ?></div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Total Syncs', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="mic-stat-subtitle"><?php _e( 'Total synchronization attempts', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
            
            <!-- Successful Syncs -->
            <div class="mic-stat-card mic-stat-success">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-checkbox-circle-line"></i>
                    </div>
                    <div class="mic-stat-trend">
                        <i class="ri-arrow-up-line"></i> <?php _e( 'Active', 'made-in-china-app-sync' ); ?>
                    </div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Successful', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo number_format($stats['success']); ?></div>
                    <div class="mic-stat-subtitle"><?php _e( 'Orders synced successfully', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
            
            <!-- Failed Syncs -->
            <div class="mic-stat-card mic-stat-failed">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-close-circle-line"></i>
                    </div>
                    <?php if ($stats['failed'] > 0): ?>
                    <div class="mic-stat-trend" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                        <i class="ri-alert-line"></i> <?php _e( 'Issues', 'made-in-china-app-sync' ); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Failed', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo number_format($stats['failed']); ?></div>
                    <div class="mic-stat-subtitle"><?php _e( 'Synchronization failures', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
            
            <!-- Success Rate -->
            <div class="mic-stat-card mic-stat-rate">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-percent-line"></i>
                    </div>
                    <div class="mic-stat-trend">
                        <?php if ($success_rate >= 95): ?>
                            <i class="ri-trophy-line"></i> <?php _e( 'Excellent', 'made-in-china-app-sync' ); ?>
                        <?php elseif ($success_rate >= 80): ?>
                            <i class="ri-thumb-up-line"></i> <?php _e( 'Good', 'made-in-china-app-sync' ); ?>
                        <?php else: ?>
                            <i class="ri-alert-line"></i> <?php _e( 'Needs Attention', 'made-in-china-app-sync' ); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Success Rate', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo $success_rate; ?>%</div>
                    <div class="mic-success-bar">
                        <div class="mic-success-fill" style="width: <?php echo $success_rate; ?>%"></div>
                    </div>
                    <div class="mic-stat-subtitle"><?php _e( 'Overall sync reliability', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
            
            <!-- Pending Syncs -->
            <div class="mic-stat-card mic-stat-pending">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-time-line"></i>
                    </div>
                    <?php if ($stats['pending'] > 0): ?>
                    <div class="mic-stat-trend" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                        <i class="ri-loader-line"></i> <?php _e( 'Processing', 'made-in-china-app-sync' ); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Pending', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="mic-stat-subtitle"><?php _e( 'Awaiting synchronization', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="mic-stat-card mic-stat-recent">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-calendar-line"></i>
                    </div>
                    <div class="mic-stat-trend"><?php _e( '7 Days', 'made-in-china-app-sync' ); ?></div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label"><?php _e( 'Recent Activity', 'made-in-china-app-sync' ); ?></div>
                    <div class="mic-stat-number"><?php echo number_format($stats['recent']); ?></div>
                    <div class="mic-stat-subtitle"><?php _e( 'Syncs in the last week', 'made-in-china-app-sync' ); ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($stats['daily'])): ?>
        <div class="mic-card">
            <h2>
                <i class="ri-line-chart-line"></i> 
                <?php _e( 'Daily Sync Activity', 'made-in-china-app-sync' ); ?>
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;"><?php _e( 'Last 7 Days', 'made-in-china-app-sync' ); ?></span>
            </h2>
            <div class="mic-chart-container">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mic-card">
            <h2>
                <i class="ri-pie-chart-line"></i> 
                <?php _e( 'Sync Status Distribution', 'made-in-china-app-sync' ); ?>
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;"><?php _e( 'Current Overview', 'made-in-china-app-sync' ); ?></span>
            </h2>
            <?php if ($stats['total'] > 0): ?>
                <div class="mic-chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            <?php else: ?>
                <div class="mic-empty-state">
                    <i class="ri-pie-chart-line"></i>
                    <h3><?php _e( 'No Data Available', 'made-in-china-app-sync' ); ?></h3>
                    <p><?php _e( 'Start by processing some orders to see sync statistics', 'made-in-china-app-sync' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($stats['avg_execution_time'] > 0): ?>
        <div class="mic-card">
            <h2>
                <i class="ri-speed-line"></i> 
                <?php _e( 'Performance Metrics', 'made-in-china-app-sync' ); ?>
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;"><?php _e( 'System Performance', 'made-in-china-app-sync' ); ?></span>
            </h2>
            <div class="mic-performance-grid">
                <div class="mic-performance-card">
                    <div class="mic-stat-icon">
                        <i class="ri-timer-line"></i>
                    </div>
                    <div class="mic-stat-number"><?php echo number_format($stats['avg_execution_time'], 3); ?>s</div>
                    <div class="mic-stat-label"><?php _e( 'Average Execution Time', 'made-in-china-app-sync' ); ?></div>
                </div>
                
                <?php 
                $throughput = $stats['recent'] > 0 ? round($stats['recent'] / 7, 1) : 0;
                ?>
                <div class="mic-performance-card">
                    <div class="mic-stat-icon">
                        <i class="ri-flashlight-line"></i>
                    </div>
                    <div class="mic-stat-number"><?php echo $throughput; ?></div>
                    <div class="mic-stat-label"><?php _e( 'Orders per Day', 'made-in-china-app-sync' ); ?></div>
                </div>
                
                <?php if ($stats['total'] > 0): ?>
                <div class="mic-performance-card">
                    <div class="mic-stat-icon">
                        <i class="ri-shield-check-line"></i>
                    </div>
                    <div class="mic-stat-number">
                        <?php 
                        if ($success_rate >= 95) echo "A+";
                        elseif ($success_rate >= 90) echo "A";
                        elseif ($success_rate >= 80) echo "B";
                        elseif ($success_rate >= 70) echo "C";
                        else echo "D";
                        ?>
                    </div>
                    <div class="mic-stat-label"><?php _e( 'Reliability Grade', 'made-in-china-app-sync' ); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    // Initialize charts when document is ready
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($stats['daily'])): ?>
        // Daily Activity Chart
        const dailyData = {
            labels: [
                <?php foreach (array_reverse($stats['daily']) as $day): ?>
                '<?php echo date('M j', strtotime($day->date)); ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: '<?php echo esc_js( __( 'Successful', 'made-in-china-app-sync' ) ); ?>',
                data: [
                    <?php foreach (array_reverse($stats['daily']) as $day): ?>
                    <?php echo $day->success; ?>,
                    <?php endforeach; ?>
                ],
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5
            }, {
                label: '<?php echo esc_js( __( 'Failed', 'made-in-china-app-sync' ) ); ?>',
                data: [
                    <?php foreach (array_reverse($stats['daily']) as $day): ?>
                    <?php echo $day->failed; ?>,
                    <?php endforeach; ?>
                ],
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#ef4444',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        };
        
        if (window.MICCharts) {
            window.MICCharts.initDailyChart(dailyData);
        }
        <?php endif; ?>
        
        <?php if ($stats['total'] > 0): ?>
        // Status Distribution Chart
        const statusData = {
            labels: ['<?php echo esc_js( __( 'Successful', 'made-in-china-app-sync' ) ); ?>', '<?php echo esc_js( __( 'Failed', 'made-in-china-app-sync' ) ); ?>', '<?php echo esc_js( __( 'Pending', 'made-in-china-app-sync' ) ); ?>'],
            datasets: [{
                data: [<?php echo $stats['success']; ?>, <?php echo $stats['failed']; ?>, <?php echo $stats['pending']; ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        };
        
        if (window.MICCharts) {
            window.MICCharts.initStatusChart(statusData);
        }
        <?php endif; ?>
    });
    </script>
    <?php
}

/**
 * AJAX handler for testing connection
 */
add_action( 'wp_ajax_mic_test_connection', 'mic_test_connection' );

function mic_test_connection() {
    try {
        $laravel_url = get_option( 'mic_laravel_app_url' );
        
        if ( empty( $laravel_url ) ) {
            wp_send_json_error( 'Laravel URL not configured. Please check your settings.' );
            return;
        }
        
        // Clean URL - remove trailing slashes
        $laravel_url = rtrim( $laravel_url, '/' );
        
        // Test endpoint URL - try a simple GET request first, then test the actual endpoint
        $test_url = $laravel_url . '/api/v1/woocommerce-sync';
        
        // First try a simple GET request to see if the endpoint exists
        $response = wp_remote_get( $test_url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WooCommerce-MadeInChina-Sync/1.2.0'
            ]
        ]);
        
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            wp_send_json_error( "Connection failed: $error_message. Check your Laravel URL: $test_url" );
            return;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        if ( $code === 200 || $code === 405 ) {
            // 200 = success, 405 = Method Not Allowed (endpoint exists but GET not allowed, which is expected for POST endpoint)
            wp_send_json_success( "Connection successful! Laravel app is responding. Endpoint is accessible." );
        } else if ( $code === 404 ) {
            wp_send_json_error( "Endpoint not found. Please check if your Laravel app has the route: POST /api/v1/woocommerce-sync" );
        } else {
            // Provide detailed error information
            $error_details = "HTTP $code";
            if ( !empty( $body ) ) {
                $error_details .= " - Response: " . substr( $body, 0, 200 );
            }
            $error_details .= ". URL tested: $test_url";
            
            wp_send_json_error( $error_details );
        }
        
    } catch ( Exception $e ) {
        wp_send_json_error( 'Connection test failed: ' . $e->getMessage() );
    }
}

/**
 * AJAX handler for clearing logs
 */
add_action( 'wp_ajax_mic_clear_logs', 'mic_clear_logs' );

function mic_clear_logs() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    if ( ! wp_verify_nonce( $_POST['nonce'], 'mic_clear_logs' ) ) {
        wp_send_json_error( 'Security check failed' );
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'mic_sync_logs';
    
    $days = intval($_POST['days']);
    if ($days > 0) {
        // Clear logs older than X days
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE sync_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    } else {
        // Clear all logs
        $result = $wpdb->query("DELETE FROM $table_name");
    }
    
    if ($result !== false) {
        wp_send_json_success("Cleared $result log entries");
    } else {
        wp_send_json_error('Failed to clear logs');
    }
}

/**
 * Add order meta box to show sync status
 */
add_action( 'add_meta_boxes', 'mic_add_order_meta_box' );

function mic_add_order_meta_box() {
    add_meta_box(
        'mic-sync-status',
        'Made in China App Sync',
        'mic_order_meta_box_content',
        'shop_order',
        'side',
        'default'
    );
}

/**
 * Meta box content with improved styling
 */
function mic_order_meta_box_content( $post ) {
    $order = wc_get_order( $post->ID );
    $synced = $order->get_meta( '_laravel_synced' );
    $sync_time = $order->get_meta( '_laravel_sync_time' );
    $sync_status = $order->get_meta( '_laravel_sync_status' );
    
    ?>
    
    <?php
    if ( $synced ) {
        echo '<div class="mic-sync-info mic-sync-success">';
        echo '<p><strong><i class="ri-check-circle-line"></i> ' . __( 'Status:', 'made-in-china-app-sync' ) . '</strong> ' . __( 'Synced', 'made-in-china-app-sync' ) . '</p>';
        echo '<p><strong><i class="ri-time-line"></i> ' . __( 'Sync Time:', 'made-in-china-app-sync' ) . '</strong> ' . esc_html( $sync_time ) . '</p>';
        echo '<p><strong><i class="ri-information-line"></i> ' . __( 'Status:', 'made-in-china-app-sync' ) . '</strong> ' . esc_html( $sync_status ) . '</p>';
        echo '</div>';
    } else {
        echo '<div class="mic-sync-info mic-sync-pending">';
        echo '<p><strong><i class="ri-time-line"></i> ' . __( 'Status:', 'made-in-china-app-sync' ) . '</strong> ' . __( 'Not Synced', 'made-in-china-app-sync' ) . '</p>';
        echo '<p><em>' . __( 'This order will be synced when payment is completed.', 'made-in-china-app-sync' ) . '</em></p>';
        echo '</div>';
    }
}

/**
 * Manual sync button for orders
 */
add_action( 'woocommerce_admin_order_data_after_order_details', 'mic_add_manual_sync_button' );

function mic_add_manual_sync_button( $order ) {
    $synced = $order->get_meta( '_laravel_synced' );
    
    if ( ! $synced && mic_is_configured() ) {
        ?>
        <p>
            <button type="button" class="button button-secondary" data-order-id="<?php echo $order->get_id(); ?>">
                <i class="ri-refresh-line"></i> <?php _e( 'Sync to Laravel App', 'made-in-china-app-sync' ); ?>
            </button>
        </p>
        
        <script>
        // Manual sync functionality is now handled by admin.js
        </script>
        <?php
    }
}

/**
 * AJAX handler for manual sync
 */
add_action( 'wp_ajax_mic_manual_sync', 'mic_manual_sync' );

function mic_manual_sync() {
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'mic_manual_sync' ) ) {
        wp_die( 'Security check failed' );
    }
    
    $order_id = intval( $_GET['order_id'] );
    
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Unauthorized' );
    }
    
    // Trigger the sync function
    mic_sync_order_to_laravel( $order_id );
    
    // Redirect back to order page
    wp_redirect( admin_url( "post.php?post=$order_id&action=edit" ) );
    exit;
}

/**
 * Add settings link to plugins page
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mic_add_settings_link' );

function mic_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=mic-app-sync' ) . '">' . __( 'Settings', 'made-in-china-app-sync' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Activation hook
 */
register_activation_hook( __FILE__, 'mic_activate' );

function mic_activate() {
    // Only set defaults if options don't exist
    if ( ! get_option( 'mic_laravel_app_url' ) ) {
        add_option( 'mic_laravel_app_url', '' );
    }
    if ( ! get_option( 'mic_webhook_secret' ) ) {
        add_option( 'mic_webhook_secret', '' );
    }
    
    // Create logs table
    mic_create_logs_table();
    
    flush_rewrite_rules();
}

/**
 * Deactivation hook
 */
register_deactivation_hook( __FILE__, 'mic_deactivate' );

function mic_deactivate() {
    flush_rewrite_rules();
}

/**
 * Improved admin notice - only show when not configured
 */
add_action( 'admin_notices', 'mic_admin_notice' );

function mic_admin_notice() {
    // Only show notice if plugin is not configured
    if ( ! mic_is_configured() ) {
        $current_screen = get_current_screen();
        
        // Don't show on our settings page or related pages
        if ( $current_screen && (
            $current_screen->id === 'settings_page_mic-app-sync' ||
            $current_screen->id === 'toplevel_page_mic-app-sync' ||
            $current_screen->id === 'mic-app-sync_page_mic-sync-logs' ||
            $current_screen->id === 'mic-app-sync_page_mic-analytics'
        )) {
            return;
        }
        
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>';
        echo '<strong>' . __( 'Made in China App Sync:', 'made-in-china-app-sync' ) . '</strong> ';
        echo __( 'Please configure the plugin settings to start syncing orders.', 'made-in-china-app-sync' ) . ' ';
        echo '<a href="' . admin_url( 'admin.php?page=mic-app-sync' ) . '">' . __( 'Configure Now', 'made-in-china-app-sync' ) . '</a>';
        echo '</p>';
        echo '</div>';
    }
}

/**
 * Improved SKU validation with better UX
 */
add_action( 'woocommerce_product_options_general_product_data', 'mic_add_sku_validation_notice' );

function mic_add_sku_validation_notice() {
    if ( ! mic_is_configured() ) {
        return; // Don't show if plugin not configured
    }
    
    echo '<div class="options_group" style="border-left: 4px solid #a444ff; padding-left: 12px; background: #f8f9ff;">';
    echo '<p class="form-field">';
    echo '<strong>' . __( 'Made in China Sync:', 'made-in-china-app-sync' ) . '</strong> ';
    echo __( 'This product needs a SKU to sync with your Laravel ebook app.', 'made-in-china-app-sync' );
    echo '</p>';
    echo '</div>';
}

/**
 * Enhanced SKU validation
 */
add_action( 'woocommerce_admin_product_data_after_title', 'mic_validate_sku_on_save' );

function mic_validate_sku_on_save() {
    if ( ! mic_is_configured() ) {
        return; // Don't add validation if plugin not configured
    }
    ?>
    <script>
    // SKU validation functionality is now handled by admin.js
    </script>
    <?php
}

/**
 * Create a debug endpoint for testing
 */
add_action( 'wp_ajax_mic_debug_info', 'mic_debug_info' );
add_action( 'wp_ajax_nopriv_mic_debug_info', 'mic_debug_info' );

function mic_debug_info() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
        return;
    }
    
    global $wpdb;
    
    $debug_info = array(
        'plugin_configured' => mic_is_configured(),
        'laravel_url' => get_option( 'mic_laravel_app_url' ),
        'webhook_secret_set' => !empty( get_option( 'mic_webhook_secret' ) ),
        'table_exists' => $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}mic_sync_logs'" ) == $wpdb->prefix . 'mic_sync_logs',
        'woocommerce_active' => class_exists( 'WooCommerce' ),
        'wordpress_version' => get_bloginfo( 'version' ),
        'php_version' => PHP_VERSION
    );
    
    wp_send_json_success( $debug_info );
}

/**
 * Add bulk sync action for orders
 */
add_filter( 'bulk_actions-edit-shop_order', 'mic_add_bulk_sync_action' );

function mic_add_bulk_sync_action( $actions ) {
    if ( mic_is_configured() ) {
        $actions['mic_bulk_sync'] = __( 'Sync to Laravel App', 'made-in-china-app-sync' );
    }
    return $actions;
}

/**
 * Handle bulk sync action
 */
add_filter( 'handle_bulk_actions-edit-shop_order', 'mic_handle_bulk_sync', 10, 3 );

function mic_handle_bulk_sync( $redirect_to, $action, $post_ids ) {
    if ( $action !== 'mic_bulk_sync' ) {
        return $redirect_to;
    }
    
    $synced_count = 0;
    foreach ( $post_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && ! $order->get_meta( '_laravel_synced' ) ) {
            mic_sync_order_to_laravel( $order_id );
            $synced_count++;
        }
    }
    
    $redirect_to = add_query_arg( 'mic_synced', $synced_count, $redirect_to );
    return $redirect_to;
}

/**
 * Show bulk sync notice
 */
add_action( 'admin_notices', 'mic_bulk_sync_notice' );

function mic_bulk_sync_notice() {
    if ( ! empty( $_REQUEST['mic_synced'] ) ) {
        $synced_count = intval( $_REQUEST['mic_synced'] );
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>';
        printf( _n( '%d order synced to Laravel app.', '%d orders synced to Laravel app.', $synced_count, 'made-in-china-app-sync' ), $synced_count );
        echo '</p></div>';
    }
}