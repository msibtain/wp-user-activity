<?php
/**
 * Plugin Name: WP User Activity Logger
 * Plugin URI: https://innovisionlab.com
 * Description: Logs user activity across the website including login, page views, category views, and archive views.
 * Version: 1.0.0
 * Author: Sib
 * License: GPL v2 or later
 * Text Domain: wp-user-activity-logger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPUAL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPUAL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WPUAL_VERSION', '1.0.0');

/**
 * Main plugin class
 */
class WP_User_Activity_Logger {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_loaded', array($this, 'log_page_view'));
        add_action('wp_login', array($this, 'log_user_login'), 10, 2);
        add_action('wp_logout', array($this, 'log_user_logout'));
        add_action('wp_head', array($this, 'log_category_view'));
        add_action('wp_head', array($this, 'log_archive_view'));
        add_action('wp_head', array($this, 'log_video_view'));
        
        // Frontend hooks for duration tracking
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('wp_footer', array($this, 'add_duration_tracking'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX hooks
        add_action('wp_ajax_wpual_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_wpual_export_logs', array($this, 'export_logs'));
        add_action('wp_ajax_wpual_export_active_users', array($this, 'export_active_users'));
        add_action('wp_ajax_wpual_update_duration', array($this, 'update_duration'));
        add_action('wp_ajax_nopriv_wpual_update_duration', array($this, 'update_duration'));
        add_action('wp_ajax_wpual_search_users', array($this, 'search_users'));
        add_action('wp_ajax_nopriv_wpual_search_users', array($this, 'search_users'));
        add_action('wp_ajax_wpual_test_ajax', array($this, 'test_ajax'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('wp-user-activity-logger', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Create database table on plugin activation
     */
    public static function activate() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'activity_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            user_ip varchar(45) NOT NULL,
            user_agent text,
            activity_type varchar(50) NOT NULL,
            activity_details text,
            page_url varchar(500),
            referer_url varchar(500),
            duration int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY activity_type (activity_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Check if duration column exists, if not add it
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'duration'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN duration int(11) DEFAULT 0 AFTER referer_url");
        }
        
        // Add version option
        add_option('wpual_version', WPUAL_VERSION);
    }
    
    /**
     * Cleanup on plugin deactivation
     */
    public static function deactivate() {
        // Optionally remove the table on deactivation
        // Uncomment the following lines if you want to remove the table on deactivation
        /*
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        delete_option('wpual_version');
        */
    }
    
    /**
     * Log user activity
     */
    private function log_activity($activity_type, $activity_details = '', $page_url = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'activity_log';
        
        $user_id = get_current_user_id();
        $user_ip = $this->get_user_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $referer_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        
        if (empty($page_url)) {
            $page_url = $this->get_current_page_url();
        }

        if (
            str_contains($page_url, 'admin-ajax.php') ||
            str_contains($page_url, 'wp-cron.php')
        ) {
            return;
        }

        if ( !$user_id ) {
            return;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'user_ip' => $user_ip,
                'user_agent' => $user_agent,
                'activity_type' => $activity_type,
                'activity_details' => $activity_details,
                'page_url' => $page_url,
                'referer_url' => $referer_url,
                'duration' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        // Return the inserted ID for duration tracking
        return $wpdb->insert_id;
    }
    
    /**
     * Log user login
     */
    public function log_user_login($user_login, $user) {
        $activity_details = sprintf(
            __('User %s logged in', 'wp-user-activity-logger'),
            $user->user_email
        );
        
        $this->log_activity('login', $activity_details);
    }
    
    /**
     * Log user logout
     */
    public function log_user_logout() {
        $user = wp_get_current_user();
        $activity_details = sprintf(
            __('User %s logged out', 'wp-user-activity-logger'),
            $user->user_email
        );
        
        $this->log_activity('logout', $activity_details);
    }
    
    /**
     * Log page view
     */
    public function log_page_view() {
        // Only log if not in admin and not a bot
        if (is_admin() || $this->is_bot()) {
            return;
        }
        
        // Skip video posts as they are logged separately
        $post_id = get_queried_object_id();
        $post_type = get_post_type($post_id);
        if ($post_type === 'video') {
            return;
        }

        if (isset($_GET['icat'])) {
            return;
        }
        
        $activity_details = $this->get_page_title();
        $log_id = $this->log_activity('page_view', $activity_details);
        
        // Store the log ID in a session variable for duration tracking
        if ($log_id) {
            if (!session_id()) {
                session_start();
            }
            $_SESSION['wpual_current_log_id'] = $log_id;
        }
    }
    
    /**
     * Log category view
     */
    public function log_category_view() {
        // Check for custom taxonomy video-category via icat parameter
        if (isset($_GET['icat']) && !is_admin() && !$this->is_bot()) {
            $icat_value = sanitize_text_field($_GET['icat']);
            $term = get_term_by('ID', $icat_value, 'video-category');
            
            if ($term && !is_wp_error($term)) {
                $activity_details = sprintf(
                    __('Video Category: %s', 'wp-user-activity-logger'),
                    $term->name
                );
                
                $log_id = $this->log_activity('category_view', $activity_details);
            }
        }
        // Check for regular WordPress categories
        elseif (is_category() && !is_admin() && !$this->is_bot()) {
            $category = get_queried_object();
            $activity_details = sprintf(
                __('Category: %s', 'wp-user-activity-logger'),
                $category->name
            );
            
            $log_id = $this->log_activity('category_view', $activity_details);
        }

        // Store the log ID in a session variable for duration tracking
        if ($log_id) {
            if (!session_id()) {
                session_start();
            }
            $_SESSION['wpual_current_log_id'] = $log_id;
        }
    }
    
    /**
     * Log archive view
     */
    public function log_archive_view() {
        if (is_archive() && !is_category() && !is_admin() && !$this->is_bot()) {
            $archive_title = get_the_archive_title();
            $activity_details = sprintf(
                __('Archive: %s', 'wp-user-activity-logger'),
                $archive_title
            );
            
            $log_id = $this->log_activity('archive_view', $activity_details);

            // Store the log ID in a session variable for duration tracking
            if ($log_id) {
                if (!session_id()) {
                    session_start();
                }
                $_SESSION['wpual_current_log_id'] = $log_id;
            }
        }
    }

    /**
     * Log video view
     */
    public function log_video_view() {
        // Check if we're on a video post page
        if (!is_admin() && !$this->is_bot()) {
            $post_id = get_queried_object_id();
            $post_type = get_post_type($post_id);
            
            if ($post_type === 'video') {
                $post_title = get_the_title($post_id);
                $activity_details = sprintf(__('Video View: %s', 'wp-user-activity-logger'), $post_title);
                $log_id = $this->log_activity('video_view', $activity_details);

                // Store the log ID in a session variable for duration tracking
                if ($log_id) {
                    if (!session_id()) {
                        session_start();
                    }
                    $_SESSION['wpual_current_log_id'] = $log_id;
                }
            }
        }
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get current page URL
     */
    private function get_current_page_url() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        
        return $protocol . '://' . $host . $uri . '?' . $query;
    }
    
    /**
     * Get page title
     */
    private function get_page_title() {
        if (is_home() || is_front_page()) {
            return get_bloginfo('name');
        } elseif (is_single() || is_page()) {
            return get_the_title();
        } elseif (is_category()) {
            return single_cat_title('', false);
        } elseif (is_tag()) {
            return single_tag_title('', false);
        } elseif (is_author()) {
            return get_the_author();
        } elseif (is_date()) {
            return get_the_date();
        } elseif (is_search()) {
            return sprintf(__('Search results for: %s', 'wp-user-activity-logger'), get_search_query());
        } elseif (is_404()) {
            return __('Page not found', 'wp-user-activity-logger');
        }
        
        return get_bloginfo('name');
    }
    
    /**
     * Check if user is a bot
     */
    private function is_bot() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $bot_patterns = array(
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot'
        );
        
        foreach ($bot_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('User Activity Log', 'wp-user-activity-logger'),
            __('Activity Log', 'wp-user-activity-logger'),
            'manage_options',
            'wp-user-activity-log',
            array($this, 'admin_page'),
            'dashicons-list-view',
            30
        );
        
        add_submenu_page(
            'wp-user-activity-log',
            __('Active Users', 'wp-user-activity-logger'),
            __('Active Users', 'wp-user-activity-logger'),
            'manage_options',
            'wp-user-activity-active-users',
            array($this, 'active_users_page')
        );

        add_submenu_page(
            'wp-user-activity-log',
            __('Active users at glance', 'wp-user-activity-logger'),
            __('Active users at glance', 'wp-user-activity-logger'),
            'manage_options',
            'wp-user-activity-active-glance',
            array($this, 'active_users_glance_page')
        );
    }
    
    /**
     * Admin page callback
     */
    public function admin_page() {
        include WPUAL_PLUGIN_PATH . 'admin/admin-page.php';
    }
    
    /**
     * Active users page callback
     */
    public function active_users_page() {
        include WPUAL_PLUGIN_PATH . 'admin/active-users-page.php';
    }
    
    /**
     * Active users at glance page callback
     */
    public function active_users_glance_page() {
        include WPUAL_PLUGIN_PATH . 'admin/active-users-glance.php';
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        if (is_admin() || $this->is_bot()) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'wpual_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpual_nonce')
        ));
    }
    
    /**
     * Add duration tracking to footer
     */
    public function add_duration_tracking() {
        if (is_admin() || $this->is_bot()) {
            return;
        }
        
        // Get current log ID from session
        $current_log_id = 0;
        if (session_id() && isset($_SESSION['wpual_current_log_id'])) {
            $current_log_id = intval($_SESSION['wpual_current_log_id']);
        }
        
        if ($current_log_id > 0) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var startTime = Date.now();
                var logId = <?php echo $current_log_id; ?>;
                
                // Function to send duration update
                function updateDuration() {
                    var duration = Math.floor((Date.now() - startTime) / 1000); // Duration in seconds
                    
                    $.ajax({
                        url: wpual_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wpual_update_duration',
                            log_id: logId,
                            duration: duration,
                            nonce: wpual_ajax.nonce
                        },
                        success: function(response) {
                            console.log('Duration updated:', duration + ' seconds');
                        },
                        error: function() {
                            console.log('Failed to update duration');
                        }
                    });
                }
                
                // Update duration when page is about to unload
                $(window).on('beforeunload', function() {
                    updateDuration();
                });
                
                // Update duration when user navigates away
                $(window).on('pagehide', function() {
                    updateDuration();
                });
                
                // Update duration every 30 seconds while user is on page
                setInterval(function() {
                    updateDuration();
                }, 30000);
                
                // Update duration when user becomes inactive (after 5 minutes)
                var inactivityTimer;
                function resetInactivityTimer() {
                    clearTimeout(inactivityTimer);
                    inactivityTimer = setTimeout(function() {
                        updateDuration();
                    }, 300000); // 5 minutes
                }
                
                $(document).on('mousemove keypress scroll', function() {
                    resetInactivityTimer();
                });
                
                resetInactivityTimer();
            });
            </script>
            <?php
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Load scripts for main activity log page, active users page, and glance page
        if (
            $hook !== 'toplevel_page_wp-user-activity-log' &&
            $hook !== 'activity-log_page_wp-user-activity-active-users' &&
            $hook !== 'activity-log_page_wp-user-activity-active-glance'
        ) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('wpual-admin', WPUAL_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), time(), true);
        wp_enqueue_style('wpual-admin', WPUAL_PLUGIN_URL . 'admin/css/admin.css', array(), time());
        
        wp_localize_script('wpual-admin', 'wpual_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpual_nonce'),
            'confirm_clear' => __('Are you sure you want to clear all logs? This action cannot be undone.', 'wp-user-activity-logger')
        ));
    }
    
    /**
     * Clear logs via AJAX
     */
    public function clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'wp-user-activity-logger'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'wpual_nonce')) {
            wp_die(__('Security check failed.', 'wp-user-activity-logger'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            wp_send_json_success(__('All logs have been cleared successfully.', 'wp-user-activity-logger'));
        } else {
            wp_send_json_error(__('Failed to clear logs.', 'wp-user-activity-logger'));
        }
    }
    
    /**
     * Update duration via AJAX
     */
    public function update_duration() {
        if (!wp_verify_nonce($_POST['nonce'], 'wpual_nonce')) {
            wp_die(__('Security check failed.', 'wp-user-activity-logger'));
        }
        
        $log_id = intval($_POST['log_id']);
        $duration = intval($_POST['duration']);
        
        if ($log_id <= 0 || $duration < 0) {
            wp_send_json_error(__('Invalid parameters.', 'wp-user-activity-logger'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        
        // Only update if the new duration is greater than the current duration
        $result = $wpdb->update(
            $table_name,
            array('duration' => $duration),
            array('id' => $log_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('Duration updated successfully.', 'wp-user-activity-logger'));
        } else {
            wp_send_json_error(__('Failed to update duration.', 'wp-user-activity-logger'));
        }
    }
    
    /**
     * Export logs via AJAX
     */
    public function export_logs() {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        // Debug: Log the request
        error_log('WPUAL Export Request POST: ' . print_r($_POST, true));
        error_log('WPUAL Export Request GET: ' . print_r($_GET, true));
        error_log('WPUAL Export: DOING_AJAX = ' . (defined('DOING_AJAX') && DOING_AJAX ? 'true' : 'false'));
        error_log('WPUAL Export: Current user can manage_options = ' . (current_user_can('manage_options') ? 'true' : 'false'));
        
        if (!current_user_can('manage_options')) {
            error_log('WPUAL Export: Permission denied');
            wp_die(__('You do not have permission to perform this action.', 'wp-user-activity-logger'));
        }
        
        // Handle both POST and GET requests
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['nonce']) ? $_GET['nonce'] : '');
        
        if (!wp_verify_nonce($nonce, 'wpual_nonce')) {
            error_log('WPUAL Export: Nonce verification failed');
            wp_die(__('Security check failed.', 'wp-user-activity-logger'));
        }
        
        error_log('WPUAL Export: Starting export process');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            wp_die(__('Activity log table does not exist. Please deactivate and reactivate the plugin.', 'wp-user-activity-logger'));
        }
        
        // Get filter parameters from POST or GET data
        $activity_type_filter = isset($_POST['activity_type']) ? sanitize_text_field($_POST['activity_type']) : (isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '');
        $user_filter = isset($_POST['user_id']) ? intval($_POST['user_id']) : (isset($_GET['user_id']) ? intval($_GET['user_id']) : '');
        $user_role_filter = isset($_POST['user_role']) ? sanitize_text_field($_POST['user_role']) : (isset($_GET['user_role']) ? sanitize_text_field($_GET['user_role']) : '');
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : (isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '');
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : (isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '');
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : (isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '');
        
        // Build WHERE conditions
        $where_conditions = array();
        $where_values = array();
        
        if (!empty($activity_type_filter)) {
            $where_conditions[] = 'activity_type = %s';
            $where_values[] = $activity_type_filter;
        }
        
        if (!empty($user_filter)) {
            $where_conditions[] = 'user_id = %d';
            $where_values[] = $user_filter;
        }
        
        if (!empty($user_role_filter)) {
            // Get users with the specified role
            $role_users = get_users(array('role' => $user_role_filter, 'fields' => 'ID'));
            if (!empty($role_users)) {
                $placeholders = implode(',', array_fill(0, count($role_users), '%d'));
                $where_conditions[] = "user_id IN ($placeholders)";
                $where_values = array_merge($where_values, $role_users);
            } else {
                // If no users found with this role, return no results
                $where_conditions[] = '1 = 0';
            }
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = 'DATE(created_at) >= %s';
            $where_values[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = 'DATE(created_at) <= %s';
            $where_values[] = $date_to;
        }
        
        if (!empty($search)) {
            $where_conditions[] = '(activity_details LIKE %s OR page_url LIKE %s OR user_ip LIKE %s)';
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        // Build query
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // First check if there are any logs to export
        $count_query = "SELECT COUNT(*) FROM $table_name $where_clause";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        
        // Debug: Log the query
        error_log('WPUAL Count Query: ' . $count_query);
        
        $total_logs = $wpdb->get_var($count_query);
        
        // Debug: Log the result
        error_log('WPUAL Total Logs: ' . $total_logs);
        
        if ($total_logs == 0) {
            wp_die(__('No logs found matching the selected filters.', 'wp-user-activity-logger'));
        }
        
        // Create filename with filter info
        $filename_parts = array('user-activity-logs', date('Y-m-d-H-i-s'));
        if (!empty($activity_type_filter)) {
            $filename_parts[] = $activity_type_filter;
        }
        if (!empty($user_role_filter)) {
            $filename_parts[] = $user_role_filter;
        }
        if (!empty($date_from)) {
            $filename_parts[] = 'from-' . $date_from;
        }
        if (!empty($date_to)) {
            $filename_parts[] = 'to-' . $date_to;
        }
        $filename = implode('-', $filename_parts) . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Open output stream
        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die(__('Failed to create output stream for CSV export.', 'wp-user-activity-logger'));
        }
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add headers
        $headers = array(
            'ID', 
            'User ID', 
            'User Name', 
            'User Email', 
            'User Role', 
            'Activity Type', 
            'Activity Details', 
            'Page URL', 
            'Referer URL', 
            'Duration (seconds)', 
            'Created At'
        );
        
        if (fputcsv($output, $headers) === false) {
            wp_die(__('Failed to write CSV headers.', 'wp-user-activity-logger'));
        }
        
        // Process logs in chunks to avoid memory issues
        $limit = 1000;
        $offset = 0;
        
        do {
            $query = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $query_values = array_merge($where_values, array($limit, $offset));
            $logs = $wpdb->get_results($wpdb->prepare($query, $query_values), ARRAY_A);
            
            foreach ($logs as $log) {
                // Get user information
                $user_name = '';
                $user_email = '';
                $user_role = '';
                
                if ($log['user_id']) {
                    $user = get_user_by('id', $log['user_id']);
                    if ($user) {
                        $user_name = $user->display_name;
                        $user_email = $user->user_email;
                        $user_roles = $user->roles;
                        if (!empty($user_roles)) {
                            $wp_roles = wp_roles();
                            $available_roles = $wp_roles->get_names();
                            $role_names = array();
                            foreach ($user_roles as $role) {
                                if (isset($available_roles[$role])) {
                                    $role_names[] = $available_roles[$role];
                                }
                            }
                            $user_role = implode(', ', $role_names);
                        }
                    }
                }
                
                $row_data = array(
                    $log['id'],
                    $log['user_id'],
                    $user_name,
                    $user_email,
                    $user_role,
                    $log['activity_type'],
                    $log['activity_details'],
                    $log['page_url'],
                    $log['referer_url'],
                    $log['duration'],
                    $log['created_at']
                );
                
                if (fputcsv($output, $row_data) === false) {
                    wp_die(__('Failed to write CSV data row.', 'wp-user-activity-logger'));
                }
            }
            
            $offset += $limit;
        } while (count($logs) == $limit);
        
        fclose($output);
        
        error_log('WPUAL Export: CSV export completed successfully');
        
        // Ensure we exit completely to prevent any redirects
        wp_die();
    }
    
    /**
     * Export active users to CSV
     */
    public function export_active_users() {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        // Debug: Log the request
        error_log('WPUAL Export Active Users: Function called');
        error_log('WPUAL Export Active Users Request POST: ' . print_r($_POST, true));
        error_log('WPUAL Export Active Users Request GET: ' . print_r($_GET, true));
        error_log('WPUAL Export Active Users: DOING_AJAX = ' . (defined('DOING_AJAX') && DOING_AJAX ? 'true' : 'false'));
        error_log('WPUAL Export Active Users: Current user can manage_options = ' . (current_user_can('manage_options') ? 'true' : 'false'));
        error_log('WPUAL Export Active Users: REQUEST_METHOD = ' . $_SERVER['REQUEST_METHOD']);
        error_log('WPUAL Export Active Users: REQUEST_URI = ' . $_SERVER['REQUEST_URI']);
        
        if (!current_user_can('manage_options')) {
            error_log('WPUAL Export Active Users: Permission denied');
            wp_die(__('You do not have permission to perform this action.', 'wp-user-activity-logger'));
        }
        
        // Handle both POST and GET requests
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['nonce']) ? $_GET['nonce'] : '');
        
        if (!wp_verify_nonce($nonce, 'wpual_nonce')) {
            error_log('WPUAL Export Active Users: Nonce verification failed');
            wp_die(__('Security check failed.', 'wp-user-activity-logger'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        
        // Get filter parameters from POST data
        $user_role_filter = isset($_REQUEST['user_role']) ? sanitize_text_field($_REQUEST['user_role']) : '';
        $activity_period = isset($_REQUEST['activity_period']) ? sanitize_text_field($_REQUEST['activity_period']) : '30';
        $search = isset($_REQUEST['search']) ? sanitize_text_field($_REQUEST['search']) : '';
        $selected_user_ids = isset($_REQUEST['selected_user_ids']) ? array_map('intval', $_REQUEST['selected_user_ids']) : array();
        
        // Calculate date range based on activity period
        $date_from = date('Y-m-d H:i:s', strtotime("-{$activity_period} days"));
        
        // Build WHERE conditions
        $where_conditions = array();
        $where_values = array();
        
        $where_conditions[] = 'created_at >= %s';
        $where_values[] = $date_from;
        
        // If specific users are selected, only export those users
        if (!empty($selected_user_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_user_ids), '%d'));
            $where_conditions[] = "user_id IN ($placeholders)";
            $where_values = array_merge($where_values, $selected_user_ids);
        } else {
            // Apply filters only if no specific users are selected
            if (!empty($user_role_filter)) {
                // Get users with the specified role
                $role_users = get_users(array('role' => $user_role_filter, 'fields' => 'ID'));
                if (!empty($role_users)) {
                    $placeholders = implode(',', array_fill(0, count($role_users), '%d'));
                    $where_conditions[] = "user_id IN ($placeholders)";
                    $where_values = array_merge($where_values, $role_users);
                } else {
                    $where_conditions[] = '1 = 0';
                }
            }
            
            if (!empty($search)) {
                // Search in user details
                $search_users = get_users(array(
                    'search' => '*' . $search . '*',
                    'search_columns' => array('user_login', 'user_email', 'display_name'),
                    'fields' => 'ID'
                ));
                
                if (!empty($search_users)) {
                    $placeholders = implode(',', array_fill(0, count($search_users), '%d'));
                    $where_conditions[] = "user_id IN ($placeholders)";
                    $where_values = array_merge($where_values, $search_users);
                } else {
                    $where_conditions[] = '1 = 0';
                }
            }
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get active users with activity statistics
        $query = "
            SELECT 
                user_id,
                COUNT(*) as total_activities,
                COUNT(DISTINCT DATE(created_at)) as active_days,
                SUM(duration) as total_duration,
                MAX(created_at) as last_activity,
                MIN(created_at) as first_activity,
                GROUP_CONCAT(DISTINCT activity_type) as activity_types
            FROM $table_name 
            $where_clause 
            AND user_id IS NOT NULL 
            AND user_id > 0
            GROUP BY user_id 
            ORDER BY last_activity DESC
        ";
        
        $active_users = $wpdb->get_results($wpdb->prepare($query, $where_values));
        
        // Debug: Log the query and results
        error_log('WPUAL Export Active Users Query: ' . $wpdb->prepare($query, $where_values));
        error_log('WPUAL Export Active Users Results Count: ' . count($active_users));
        error_log('WPUAL Export Active Users Results: ' . print_r($active_users, true));
        
        if (empty($active_users)) {
            error_log('WPUAL Export Active Users: No users found');
            wp_die(__('No active users to export.', 'wp-user-activity-logger'));
        }
        
        // Create filename with filter info
        $filename_parts = array('active-users', date('Y-m-d-H-i-s'));
        if (!empty($selected_user_ids)) {
            $filename_parts[] = 'selected-' . count($selected_user_ids);
        } elseif (!empty($user_role_filter)) {
            $filename_parts[] = $user_role_filter;
        }
        $filename_parts[] = $activity_period . 'days';
        $filename = implode('-', $filename_parts) . '.csv';
        
        // Debug: Log the filename
        error_log('WPUAL Export Active Users Filename: ' . $filename);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        fputcsv($output, array(
            'User ID', 
            'Display Name', 
            'Email', 
            'Username', 
            'Role', 
            'Total Activities', 
            'Active Days', 
            'Total Duration (seconds)', 
            'Activity Types', 
            'First Activity', 
            'Last Activity'
        ));
        
        // Debug: Log the data processing
        error_log('WPUAL Export Active Users: Starting data processing for ' . count($active_users) . ' users');
        
        // Add data
        foreach ($active_users as $user_data) {
            $user = get_user_by('id', $user_data->user_id);
            if ($user) {
                $user_roles = $user->roles;
                $role_names = array();
                if (!empty($user_roles)) {
                    $wp_roles = wp_roles();
                    $available_roles = $wp_roles->get_names();
                    foreach ($user_roles as $role) {
                        if (isset($available_roles[$role])) {
                            $role_names[] = $available_roles[$role];
                        }
                    }
                }
                
                fputcsv($output, array(
                    $user->ID,
                    $user->display_name,
                    $user->user_email,
                    $user->user_login,
                    implode(', ', $role_names),
                    $user_data->total_activities,
                    $user_data->active_days,
                    $user_data->total_duration,
                    $user_data->activity_types,
                    $user_data->first_activity,
                    $user_data->last_activity
                ));
            }
        }
        
        fclose($output);
        
        // Debug: Log completion
        error_log('WPUAL Export Active Users: Export completed successfully');
        
        exit;
    }
    
    /**
     * Search users for the user filter dropdown
     */
    public function search_users() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'wp-user-activity-logger'));
        }
        
        if (!wp_verify_nonce($_GET['nonce'], 'wpual_nonce')) {
            wp_send_json_error(__('Security check failed.', 'wp-user-activity-logger'));
        }
        
        $search_term = sanitize_text_field($_GET['search']);
        $users = array();
        
        if (!empty($search_term)) {
            $args = array(
                'search' => '*' . $search_term . '*',
                'search_columns' => array('user_login', 'user_email', 'display_name', 'user_nicename'),
                'number' => 20,
                'orderby' => 'display_name',
                'order' => 'ASC'
            );
            
            $user_query = new WP_User_Query($args);
            $users_data = $user_query->get_results();
            
            foreach ($users_data as $user) {
                $users[] = array(
                    'id' => $user->ID,
                    'text' => $user->display_name . ' (' . $user->user_email . ')',
                    'display_name' => $user->display_name,
                    'email' => $user->user_email
                );
            }
        }
        
        wp_send_json($users);
    }

    /**
     * Test AJAX function
     */
    public function test_ajax() {
        wp_send_json_success('AJAX test successful!');
    }
}

// Initialize plugin
$wp_user_activity_logger = new WP_User_Activity_Logger();

// Activation and deactivation hooks
register_activation_hook(__FILE__, array('WP_User_Activity_Logger', 'activate'));
register_deactivation_hook(__FILE__, array('WP_User_Activity_Logger', 'deactivate')); 