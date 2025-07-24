<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get filter parameters
$user_role_filter = isset($_GET['user_role']) ? sanitize_text_field($_GET['user_role']) : '';
$activity_period = isset($_GET['activity_period']) ? sanitize_text_field($_GET['activity_period']) : '30'; // days
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Build query to get active users
global $wpdb;
$table_name = $wpdb->prefix . 'activity_log';

// Calculate date range based on activity period
$date_from = date('Y-m-d H:i:s', strtotime("-{$activity_period} days"));

// Get active users with their activity statistics
$where_conditions = array();
$where_values = array();

$where_conditions[] = 'created_at >= %s';
$where_values[] = $date_from;

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
    LIMIT %d OFFSET %d
";

$query_values = array_merge($where_values, array($per_page, $offset));
$active_users = $wpdb->get_results($wpdb->prepare($query, $query_values));

// Get total count for pagination
$count_query = "
    SELECT COUNT(DISTINCT user_id) 
    FROM $table_name 
    $where_clause 
    AND user_id IS NOT NULL 
    AND user_id > 0
";
$total_users = $wpdb->get_var($wpdb->prepare($count_query, $where_values));

// Get available user roles for filter
$wp_roles = wp_roles();
$available_roles = $wp_roles->get_names();

$total_pages = ceil($total_users / $per_page);

// Get overall statistics
$overall_stats_query = "
    SELECT 
        COUNT(DISTINCT user_id) as total_active_users,
        COUNT(*) as total_activities,
        AVG(activities_per_user) as avg_activities_per_user
    FROM (
        SELECT 
            user_id,
            COUNT(*) as activities_per_user
        FROM $table_name 
        WHERE created_at >= %s 
        AND user_id IS NOT NULL 
        AND user_id > 0
        GROUP BY user_id
    ) as user_stats
";
$overall_stats = $wpdb->get_row($wpdb->prepare($overall_stats_query, $date_from));
?>

<div class="wrap">
    <h1><?php _e('Active Users', 'wp-user-activity-logger'); ?></h1>
    
    <!-- Filters -->
    <div class="wpual-filters" style="display: none;">
        <form method="get" action="">
            <input type="hidden" name="page" value="wp-user-activity-active-users">
            
            <div class="filter-row">
                <div class="filter-group">
                    <label for="user_role"><?php _e('User Role:', 'wp-user-activity-logger'); ?></label>
                    <select name="user_role" id="user_role">
                        <option value=""><?php _e('All Roles', 'wp-user-activity-logger'); ?></option>
                        <?php foreach ($available_roles as $role_key => $role_name): ?>
                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($user_role_filter, $role_key); ?>>
                                <?php echo esc_html($role_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="activity_period"><?php _e('Activity Period:', 'wp-user-activity-logger'); ?></label>
                    <select name="activity_period" id="activity_period">
                        <option value="7" <?php selected($activity_period, '7'); ?>><?php _e('Last 7 days', 'wp-user-activity-logger'); ?></option>
                        <option value="30" <?php selected($activity_period, '30'); ?>><?php _e('Last 30 days', 'wp-user-activity-logger'); ?></option>
                        <option value="60" <?php selected($activity_period, '60'); ?>><?php _e('Last 60 days', 'wp-user-activity-logger'); ?></option>
                        <option value="90" <?php selected($activity_period, '90'); ?>><?php _e('Last 90 days', 'wp-user-activity-logger'); ?></option>
                        <option value="180" <?php selected($activity_period, '180'); ?>><?php _e('Last 180 days', 'wp-user-activity-logger'); ?></option>
                        <option value="365" <?php selected($activity_period, '365'); ?>><?php _e('Last 365 days', 'wp-user-activity-logger'); ?></option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="search"><?php _e('Search:', 'wp-user-activity-logger'); ?></label>
                    <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search by username, email, or display name', 'wp-user-activity-logger'); ?>">
                </div>
                
                <div class="filter-actions">
                    <input type="submit" class="button button-primary" value="<?php _e('Apply Filters', 'wp-user-activity-logger'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=wp-user-activity-active-users'); ?>" class="button"><?php _e('Clear Filters', 'wp-user-activity-logger'); ?></a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Overall Statistics -->
    <?php if ($overall_stats): ?>
    <div class="wpual-stats" style="display: none;">
        <div class="stat-box">
            <h3><?php echo number_format($overall_stats->total_active_users); ?></h3>
            <p><?php _e('Active Users', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo number_format($overall_stats->total_activities); ?></h3>
            <p><?php _e('Total Activities', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo number_format(round($overall_stats->avg_activities_per_user, 1)); ?></h3>
            <p><?php _e('Avg Activities/User', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo esc_html($activity_period); ?></h3>
            <p><?php _e('Days Period', 'wp-user-activity-logger'); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Actions -->
    <div class="wpual-actions">
        <?php 
        $export_url = admin_url('admin-ajax.php') . '?' . http_build_query(array(
            'action' => 'wpual_export_active_users',
            'nonce' => wp_create_nonce('wpual_nonce'),
            'user_role' => $user_role_filter,
            'activity_period' => $activity_period,
            'search' => $search
        ));
        ?>
        <a href="<?php echo esc_url($export_url); ?>" class="button" target="_blank"><?php _e('Export CSV', 'wp-user-activity-logger'); ?></a>
    </div>
    
    <!-- Users Table -->
    <div class="tablenav top">
        <div class="tablenav-pages">
            <?php if ($total_pages > 1): ?>
                <span class="displaying-num"><?php printf(__('%s users', 'wp-user-activity-logger'), number_format($total_users)); ?></span>
                <span class="pagination-links">
                    <?php
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'type' => 'array'
                    ));
                    
                    if ($page_links) {
                        echo join("\n", $page_links);
                    }
                    ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-user"><?php _e('User', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-role"><?php _e('Role', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-activities"><?php _e('Total Activities', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-days"><?php _e('Active Days', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-duration"><?php _e('Total Duration', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-types"><?php _e('Activity Types', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-first"><?php _e('First Activity', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-last"><?php _e('Last Activity', 'wp-user-activity-logger'); ?></th>
                <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'wp-user-activity-logger'); ?></th>
            </tr>
        </thead>
        
        <tbody>
            <?php if (empty($active_users)): ?>
                <tr>
                    <td colspan="9"><?php _e('No active users found.', 'wp-user-activity-logger'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($active_users as $user_data): ?>
                    <?php $user = get_user_by('id', $user_data->user_id); ?>
                    <?php if ($user): ?>
                        <tr>
                            <td class="column-user">
                                <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>">
                                    <?php echo get_avatar($user->ID, 32); ?>
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                </a>
                                <br>
                                <small><?php echo esc_html($user->user_email); ?></small>
                                <br>
                                <small><?php echo esc_html($user->user_login); ?></small>
                            </td>
                            <td class="column-role">
                                <?php 
                                $user_roles = $user->roles;
                                if (!empty($user_roles)) {
                                    $role_names = array();
                                    foreach ($user_roles as $role) {
                                        if (isset($available_roles[$role])) {
                                            $role_names[] = $available_roles[$role];
                                        }
                                    }
                                    echo esc_html(implode(', ', $role_names));
                                } else {
                                    echo '<em>' . __('No role', 'wp-user-activity-logger') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-activities">
                                <strong><?php echo number_format($user_data->total_activities); ?></strong>
                            </td>
                            <td class="column-days">
                                <?php echo number_format($user_data->active_days); ?>
                            </td>
                            <td class="column-duration">
                                <?php 
                                if ($user_data->total_duration > 0) {
                                    $duration = intval($user_data->total_duration);
                                    if ($duration < 60) {
                                        echo esc_html($duration . 's');
                                    } elseif ($duration < 3600) {
                                        echo esc_html(floor($duration / 60) . 'm ' . ($duration % 60) . 's');
                                    } else {
                                        echo esc_html(floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm');
                                    }
                                } else {
                                    echo '<em>' . __('N/A', 'wp-user-activity-logger') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-types">
                                <?php 
                                if ($user_data->activity_types) {
                                    $types = explode(',', $user_data->activity_types);
                                    $type_labels = array();
                                    foreach ($types as $type) {
                                        $type_labels[] = ucfirst(str_replace('_', ' ', $type));
                                    }
                                    echo esc_html(implode(', ', $type_labels));
                                } else {
                                    echo '<em>' . __('N/A', 'wp-user-activity-logger') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-first">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($user_data->first_activity))); ?>
                            </td>
                            <td class="column-last">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($user_data->last_activity))); ?>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo admin_url('admin.php?page=wp-user-activity-log&user_id=' . $user->ID); ?>" class="button button-small">
                                    <?php _e('View Logs', 'wp-user-activity-logger'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php if ($total_pages > 1): ?>
                <span class="displaying-num"><?php printf(__('%s users', 'wp-user-activity-logger'), number_format($total_users)); ?></span>
                <span class="pagination-links">
                    <?php
                    if ($page_links) {
                        echo join("\n", $page_links);
                    }
                    ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

 