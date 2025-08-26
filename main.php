<?php
/**
 * Plugin Name: Made in China App Sync
 * Description: Syncs WooCommerce paid orders with Made in China app.
 * Version: 1.2.0
 * Author: Yassir Zbida
 * Text Domain: made-in-china-app-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
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
        echo '<div class="notice notice-success is-dismissible"><p><i class="ri-checkbox-circle-line"></i> Settings saved successfully!</p></div>';
    }

    $laravel_url = get_option( 'mic_laravel_app_url', '' );
    $webhook_secret = get_option( 'mic_webhook_secret', '' );
    $is_configured = mic_is_configured();
    
    ?>
    <!-- Load Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        .mic-admin-header {
            background: #a444ff;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .mic-admin-header h1 {
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mic-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .mic-status-configured {
            background: #d1f2eb;
            color: #0d7049;
        }
        .mic-status-not-configured {
            background: #fef2e7;
            color: #b7791f;
        }
        .mic-card {
            background: white;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .mic-button {
            background: #a444ff;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .mic-button:hover {
            background: #8b35e6;
            color: white;
        }
        .mic-button:disabled {
            background: #a0a0a0;
            cursor: not-allowed;
        }
        .mic-input {
            width: 100%;
            max-width: 400px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .mic-guide {
            background: #f8f9fa;
            border: 1px solid #e5e7eb ;
			border-radius : 12px ;
            padding: 15px 20px;
            margin: 15px 0;
        }
        .mic-test-result {
            margin: 15px 0;
            padding: 12px;
            border-radius: 6px;
            display: none;
        }
        .mic-test-success {
            background: #d1f2eb;
            color: #0d7049;
            border: 1px solid #a3d9cc;
        }
        .mic-test-error {
            background: #fdeaea;
            color: #c53030;
            border: 1px solid #f5b7b1;
        }
        .ri-spin {
            animation: ri-spin 1s linear infinite;
        }

        .notice-success {
            color: #1d2327;
        }
        @keyframes ri-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>

    <div class="wrap">
        <div class="mic-admin-header">
            <h1>
                <i class="ri-cloud-line"></i>
                Made in China App Sync
                <?php if ( $is_configured ): ?>
                    <span class="mic-status-badge mic-status-configured">
                    <i class="ri-checkbox-circle-line"></i>Configured
                    </span>
                <?php else: ?>
                    <span class="mic-status-badge mic-status-not-configured">
                        <i class="ri-error-warning-fill"></i> Needs Configuration
                    </span>
                <?php endif; ?>
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Sync WooCommerce orders with your Laravel ebook application</p>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-settings-3-line"></i> Configuration</h2>
            
            <form method="post">
                <?php wp_nonce_field( 'mic_save_settings', 'mic_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="laravel_app_url">
                                <i class="ri-global-line"></i> Laravel App URL
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
                                The base URL of your Laravel application (without /dashboard)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="webhook_secret">
                                <i class="ri-key-2-line"></i> Webhook Secret
                            </label>
                        </th>
                        <td>
                            <input type="text" id="webhook_secret" name="webhook_secret" 
                                   value="<?php echo esc_attr( $webhook_secret ); ?>" 
                                   class="mic-input" 
                                   placeholder="Enter your secure webhook secret"
                                   required />
                            <p class="description">
                                <i class="ri-information-line"></i>
                                The secret key configured in your Laravel app's .env file (WOOCOMMERCE_WEBHOOK_SECRET)
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="submit" class="mic-button">
                        <i class="ri-save-line"></i> Save Settings
                    </button>
                </p>
            </form>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-pulse-line"></i> Connection Test</h2>
            <p>Test the connection to your Laravel application:</p>
            <button type="button" class="mic-button" onclick="testConnection()" id="test-btn">
                <i class="ri-wifi-line"></i> Test Connection
            </button>
            <div id="test-result" class="mic-test-result"></div>
        </div>
        
        <div class="mic-card">
            <h2><i class="ri-guide-line"></i> Setup Guide</h2>
            
            <div class="mic-guide">
                <h3><i class="ri-number-1"></i> Configure Laravel App</h3>
                <p>In your Laravel app's <code>.env</code> file, add:</p>
                <pre><code>WOOCOMMERCE_ENABLED=true
WOOCOMMERCE_WEBHOOK_SECRET=<?php echo esc_html( $webhook_secret ?: 'your-secret-here' ); ?></code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-2"></i> Run Migrations</h3>
                <p>In your Laravel app, run:</p>
                <pre><code>php artisan migrate</code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-3"></i> Create Route</h3>
                <p>Ensure your Laravel app has the sync endpoint:</p>
                <pre><code>Route::post('/api/v1/woocommerce-sync', [WooCommerceController::class, 'sync']);</code></pre>
            </div>
            
            <div class="mic-guide">
                <h3><i class="ri-number-4"></i> Product SKUs</h3>
                <p>Make sure your WooCommerce products have SKUs that match your Laravel ebook identifiers.</p>
            </div>
        </div>
    </div>
    
    <script>
    function testConnection() {
        const button = document.getElementById('test-btn');
        const resultDiv = document.getElementById('test-result');
        
        button.disabled = true;
        button.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Testing...';
        resultDiv.style.display = 'block';
        resultDiv.className = 'mic-test-result';
        resultDiv.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Testing connection...';
        
        fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mic_test_connection'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                resultDiv.className = 'mic-test-result mic-test-success';
                resultDiv.innerHTML = '<i class="ri-check-circle-line"></i> Connection successful! Laravel app is responding.';
            } else {
                resultDiv.className = 'mic-test-result mic-test-error';
                resultDiv.innerHTML = '<i class="ri-error-warning-line"></i> Connection failed: ' + (data.data || 'Unknown error');
            }
        })
        .catch(error => {
            resultDiv.className = 'mic-test-result mic-test-error';
            resultDiv.innerHTML = '<i class="ri-error-warning-line"></i> Connection error: ' + error.message;
        })
        .finally(() => {
            button.disabled = false;
            button.innerHTML = '<i class="ri-wifi-line"></i> Test Connection';
        });
    }
    </script>
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
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        .mic-logs-header {
            background: #a444ff;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .mic-logs-header h1 {
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mic-card {
            background: white;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .mic-status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .mic-status-success { background: #d1f2eb; color: #0d7049; }
        .mic-status-failed { background: #fdeaea; color: #c53030; }
        .mic-status-pending { background: #fef2e7; color: #b7791f; }
        .mic-logs-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .mic-logs-table th,
        .mic-logs-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        .mic-logs-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .mic-logs-table tr:hover {
            background: #f8f9fa;
        }
        .mic-filter-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .mic-pagination {
            display: flex;
            gap: 10px;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
        }
        .mic-pagination a, .mic-pagination span {
            padding: 8px 12px;
            border: 1px solid #e1e5e9;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
        }
        .mic-pagination .current {
            background: #a444ff;
            color: white;
            border-color: #a444ff;
        }
        .mic-expandable {
            cursor: pointer;
        }
        .mic-expanded-data {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>

    <div class="wrap">
        <div class="mic-logs-header">
            <h1>
                <i class="ri-file-list-3-line"></i>
                Sync Logs
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Track all synchronization attempts and their status</p>
        </div>
        
        <div class="mic-card">
            <div class="mic-filter-bar">
                <form method="get" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="mic-sync-logs">
                    <label for="status-filter"><i class="ri-filter-line"></i> Filter by status:</label>
                    <select name="status" id="status-filter">
                        <option value="">All Status</option>
                        <option value="success" <?php selected($status_filter, 'success'); ?>>Success</option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                    </select>
                    <button type="submit" class="button">Filter</button>
                    <?php if (!empty($status_filter)): ?>
                        <a href="<?php echo admin_url('admin.php?page=mic-sync-logs'); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </form>
                
                <div style="margin-left: auto;">
                    <button type="button" class="button button-secondary" onclick="showClearLogsDialog()">
                        <i class="ri-delete-bin-line"></i> Clear Logs
                    </button>
                </div>
            </div>
            
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="ri-inbox-line" style="font-size: 48px; color: #ccc;"></i>
                    <p>No sync logs found.</p>
                </div>
            <?php else: ?>
                <table class="mic-logs-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Response</th>
                            <th>Time</th>
                            <th>Duration</th>
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
                                            <i class="ri-external-link-line"></i> View Order
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
                                        <span class="mic-expandable" onclick="toggleExpanded(this)">
                                            <i class="ri-eye-line"></i> <?php echo count($products); ?> product(s)
                                        </span>
                                        <div class="mic-expanded-data">
                                            <?php foreach ($products as $product): ?>
                                                • <?php echo esc_html($product['name']); ?> (SKU: <?php echo esc_html($product['sku']); ?>)<br>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">No products</span>
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
                                        <span class="mic-expandable" onclick="toggleExpanded(this)">
                                            <i class="ri-code-line"></i> Response Data
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
                                <i class="ri-arrow-left-line"></i> Previous
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
                                Next <i class="ri-arrow-right-line"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    function toggleExpanded(element) {
        const expandedData = element.nextElementSibling;
        if (expandedData.style.display === 'none' || expandedData.style.display === '') {
            expandedData.style.display = 'block';
            element.innerHTML = element.innerHTML.replace('ri-eye-line', 'ri-eye-off-line');
        } else {
            expandedData.style.display = 'none';
            element.innerHTML = element.innerHTML.replace('ri-eye-off-line', 'ri-eye-line');
        }
    }
    
    function showClearLogsDialog() {
        const days = prompt('Enter number of days to keep logs (0 = clear all logs, 30 = keep last 30 days):', '30');
        if (days === null) return;
        
        const dayNum = parseInt(days);
        if (isNaN(dayNum) || dayNum < 0) {
            alert('Please enter a valid number (0 or higher)');
            return;
        }
        
        const message = dayNum === 0 ? 
            'Are you sure you want to clear ALL sync logs? This cannot be undone.' :
            `Are you sure you want to clear logs older than ${dayNum} days? This cannot be undone.`;
            
        if (!confirm(message)) return;
        
        const formData = new FormData();
        formData.append('action', 'mic_clear_logs');
        formData.append('days', dayNum);
        formData.append('nonce', '<?php echo wp_create_nonce("mic_clear_logs"); ?>');
        
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.data);
                location.reload();
            } else {
                alert('Error: ' + data.data);
            }
        })
        .catch(error => {
            alert('Error clearing logs: ' + error.message);
        });
    }
    </script>
    <?php
}

/**
 * Analytics page content - Improved UI
 */
function mic_analytics_page() {
    $stats = mic_get_sync_stats();
    $success_rate = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) : 0;
    ?>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .mic-analytics-header {
            background: linear-gradient(135deg, #a444ff 0%, #8b35e6 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 4px 20px rgba(164, 68, 255, 0.15);
        }
        .mic-analytics-header h1 {
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 28px;
            font-weight: 600;
        }
        .mic-analytics-header p {
            margin: 12px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        
        .mic-card {
            background: white;
            border: 1px solid #e8ecf0;
            border-radius: 12px;
            padding: 24px;
            margin: 24px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s ease;
        }
        .mic-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .mic-card h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: 600;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Enhanced Stats Grid */
        .mic-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin: 24px 0;
        }
        
        /* Redesigned Stat Cards */
        .mic-stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
			
        }
        
        .mic-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        /* Icon and Content Layout */
        .mic-stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .mic-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--accent-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .mic-stat-trend {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .mic-stat-content {
            text-align: left;
        }
        .mic-stat-number {
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
            margin: 8px 0;
            color: #1a202c;
        }
        .mic-stat-label {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .mic-stat-subtitle {
            color: #9ca3af;
            font-size: 12px;
            margin-top: 4px;
        }
        
        /* Color Variants */
        .mic-stat-total {
            --accent-color: #6366f1;
        }
        .mic-stat-success {
            --accent-color: #10b981;
        }
        .mic-stat-failed {
            --accent-color: #ef4444;
        }
        .mic-stat-rate {
            --accent-color: #8b5cf6;
        }
        .mic-stat-pending {
            --accent-color: #f59e0b;
        }
        .mic-stat-recent {
            --accent-color: #06b6d4;
        }
        
        /* Chart Container */
        .mic-chart-container {
            position: relative;
            height: 350px;
            margin: 24px 0;
            padding: 20px;
            background: #fafbfc;
            border-radius: 8px;
            border: 1px solid #f1f5f9;
        }
        
        /* Performance Metrics */
        .mic-performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .mic-performance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.25);
        }
        .mic-performance-card .mic-stat-icon {
            background: rgba(255, 255, 255, 0.2);
            margin: 0 auto 16px auto;
        }
        .mic-performance-card .mic-stat-number {
            color: white;
            margin: 12px 0;
        }
        .mic-performance-card .mic-stat-label {
            color: rgba(255, 255, 255, 0.9);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .mic-stats-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .mic-stat-card {
                padding: 20px;
            }
            .mic-stat-number {
                font-size: 28px;
            }
            .mic-analytics-header {
                padding: 20px;
            }
            .mic-analytics-header h1 {
                font-size: 24px;
            }
        }
        
        /* Loading Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .mic-loading {
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        /* Empty State */
        .mic-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .mic-empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        .mic-empty-state h3 {
            margin: 16px 0 8px 0;
            color: #374151;
        }
        
        /* Success Rate Bar */
        .mic-success-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin: 12px 0;
        }
        .mic-success-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 4px;
            transition: width 0.6s ease;
        }
    </style>

    <div class="wrap">
        <div class="mic-analytics-header">
            <h1>
                <i class="ri-bar-chart-line"></i>
                Analytics Dashboard
            </h1>
            <p>Comprehensive sync performance and statistics overview</p>
        </div>
        
        <!-- Enhanced Stats Grid -->
        <div class="mic-stats-grid">
            <!-- Total Syncs -->
            <div class="mic-stat-card mic-stat-total">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-database-2-line"></i>
                    </div>
                    <div class="mic-stat-trend">All Time</div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Total Syncs</div>
                    <div class="mic-stat-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="mic-stat-subtitle">Total synchronization attempts</div>
                </div>
            </div>
            
            <!-- Successful Syncs -->
            <div class="mic-stat-card mic-stat-success">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-checkbox-circle-line"></i>
                    </div>
                    <div class="mic-stat-trend">
                        <i class="ri-arrow-up-line"></i> Active
                    </div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Successful</div>
                    <div class="mic-stat-number"><?php echo number_format($stats['success']); ?></div>
                    <div class="mic-stat-subtitle">Orders synced successfully</div>
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
                        <i class="ri-alert-line"></i> Issues
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Failed</div>
                    <div class="mic-stat-number"><?php echo number_format($stats['failed']); ?></div>
                    <div class="mic-stat-subtitle">Synchronization failures</div>
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
                            <i class="ri-trophy-line"></i> Excellent
                        <?php elseif ($success_rate >= 80): ?>
                            <i class="ri-thumb-up-line"></i> Good
                        <?php else: ?>
                            <i class="ri-alert-line"></i> Needs Attention
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Success Rate</div>
                    <div class="mic-stat-number"><?php echo $success_rate; ?>%</div>
                    <div class="mic-success-bar">
                        <div class="mic-success-fill" style="width: <?php echo $success_rate; ?>%"></div>
                    </div>
                    <div class="mic-stat-subtitle">Overall sync reliability</div>
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
                        <i class="ri-loader-line"></i> Processing
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Pending</div>
                    <div class="mic-stat-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="mic-stat-subtitle">Awaiting synchronization</div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="mic-stat-card mic-stat-recent">
                <div class="mic-stat-header">
                    <div class="mic-stat-icon">
                        <i class="ri-calendar-line"></i>
                    </div>
                    <div class="mic-stat-trend">7 Days</div>
                </div>
                <div class="mic-stat-content">
                    <div class="mic-stat-label">Recent Activity</div>
                    <div class="mic-stat-number"><?php echo number_format($stats['recent']); ?></div>
                    <div class="mic-stat-subtitle">Syncs in the last week</div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($stats['daily'])): ?>
        <div class="mic-card">
            <h2>
                <i class="ri-line-chart-line"></i> 
                Daily Sync Activity
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;">Last 7 Days</span>
            </h2>
            <div class="mic-chart-container">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mic-card">
            <h2>
                <i class="ri-pie-chart-line"></i> 
                Sync Status Distribution
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;">Current Overview</span>
            </h2>
            <?php if ($stats['total'] > 0): ?>
                <div class="mic-chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            <?php else: ?>
                <div class="mic-empty-state">
                    <i class="ri-pie-chart-line"></i>
                    <h3>No Data Available</h3>
                    <p>Start by processing some orders to see sync statistics</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($stats['avg_execution_time'] > 0): ?>
        <div class="mic-card">
            <h2>
                <i class="ri-speed-line"></i> 
                Performance Metrics
                <span style="font-size: 14px; font-weight: normal; color: #6b7280; margin-left: auto;">System Performance</span>
            </h2>
            <div class="mic-performance-grid">
                <div class="mic-performance-card">
                    <div class="mic-stat-icon">
                        <i class="ri-timer-line"></i>
                    </div>
                    <div class="mic-stat-number"><?php echo number_format($stats['avg_execution_time'], 3); ?>s</div>
                    <div class="mic-stat-label">Average Execution Time</div>
                </div>
                
                <?php 
                $throughput = $stats['recent'] > 0 ? round($stats['recent'] / 7, 1) : 0;
                ?>
                <div class="mic-performance-card">
                    <div class="mic-stat-icon">
                        <i class="ri-flashlight-line"></i>
                    </div>
                    <div class="mic-stat-number"><?php echo $throughput; ?></div>
                    <div class="mic-stat-label">Orders per Day</div>
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
                    <div class="mic-stat-label">Reliability Grade</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    // Daily Activity Chart
    <?php if (!empty($stats['daily'])): ?>
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: [
                <?php foreach (array_reverse($stats['daily']) as $day): ?>
                '<?php echo date('M j', strtotime($day->date)); ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Successful',
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
                label: 'Failed',
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
        },
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
    <?php endif; ?>
    
    // Status Distribution Chart
    <?php if ($stats['total'] > 0): ?>
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Successful', 'Failed', 'Pending'],
            datasets: [{
                data: [<?php echo $stats['success']; ?>, <?php echo $stats['failed']; ?>, <?php echo $stats['pending']; ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        },
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
    <?php endif; ?>
    
    // Animate numbers on page load
    document.addEventListener('DOMContentLoaded', function() {
        const numbers = document.querySelectorAll('.mic-stat-number');
        numbers.forEach(number => {
            const finalValue = parseInt(number.textContent.replace(/[,\s%]/g, ''));
            if (finalValue > 0 && finalValue < 1000) {
                animateNumber(number, finalValue);
            }
        });
    });
    
    function animateNumber(element, target) {
        let current = 0;
        const increment = target / 30;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            element.textContent = Math.floor(current).toLocaleString();
        }, 50);
    }
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
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        .mic-sync-info { margin: 10px 0; }
        .mic-sync-success { color: #0d7049; }
        .mic-sync-pending { color: #b7791f; }
        .mic-sync-error { color: #c53030; }
    </style>
    
    <?php
    if ( $synced ) {
        echo '<div class="mic-sync-info mic-sync-success">';
        echo '<p><strong><i class="ri-check-circle-line"></i> Status:</strong> Synced</p>';
        echo '<p><strong><i class="ri-time-line"></i> Sync Time:</strong> ' . esc_html( $sync_time ) . '</p>';
        echo '<p><strong><i class="ri-information-line"></i> Status:</strong> ' . esc_html( $sync_status ) . '</p>';
        echo '</div>';
    } else {
        echo '<div class="mic-sync-info mic-sync-pending">';
        echo '<p><strong><i class="ri-time-line"></i> Status:</strong> Not Synced</p>';
        echo '<p><em>This order will be synced when payment is completed.</em></p>';
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
            <button type="button" class="button button-secondary" onclick="manualSync(<?php echo $order->get_id(); ?>)">
                <i class="ri-refresh-line"></i> Sync to Laravel App
            </button>
        </p>
        
        <script>
        function manualSync(orderId) {
            if (confirm("Are you sure you want to manually sync this order to the Laravel app?")) {
                window.location.href = "<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=mic_manual_sync&order_id=' ), 'mic_manual_sync' ); ?>" + orderId;
            }
        }
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
    $settings_link = '<a href="' . admin_url( 'admin.php?page=mic-app-sync' ) . '">Settings</a>';
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
        echo '<strong>Made in China App Sync:</strong> ';
        echo 'Please configure the plugin settings to start syncing orders. ';
        echo '<a href="' . admin_url( 'admin.php?page=mic-app-sync' ) . '">Configure Now</a>';
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
    echo '<strong>Made in China Sync:</strong> ';
    echo 'This product needs a SKU to sync with your Laravel ebook app.';
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
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script>
    jQuery(document).ready(function($) {
        // Add visual indicator for SKU field
        $('#_sku').after('<span id="mic-sku-indicator" style="margin-left: 8px;"></span>');
        
        function updateSkuIndicator() {
            var sku = $('#_sku').val();
            var indicator = $('#mic-sku-indicator');
            
            if (sku && sku.trim() !== '') {
                indicator.html('<i class="ri-check-circle-line" style="color: #0d7049;"></i> Will sync');
            } else {
                indicator.html('<i class="ri-error-warning-line" style="color: #b7791f;"></i> Won\'t sync');
            }
        }
        
        $('#_sku').on('input blur', updateSkuIndicator);
        updateSkuIndicator(); // Initial check
        
        $('form#post').on('submit', function(e) {
            var sku = $('#_sku').val();
            if (!sku || sku.trim() === '') {
                if (confirm('⚠️ This product has no SKU and will not sync with the Made in China Laravel app.\n\nDo you want to continue anyway?')) {
                    return true;
                } else {
                    e.preventDefault();
                    $('#_sku').focus();
                    return false;
                }
            }
        });
    });
    </script>
    <style>
    #mic-sku-indicator {
        font-size: 12px;
        font-weight: 500;
    }
    </style>
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
        printf( _n( '%d order synced to Laravel app.', '%d orders synced to Laravel app.', $synced_count ), $synced_count );
        echo '</p></div>';
    }
}