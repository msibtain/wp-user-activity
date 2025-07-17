<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Handle bulk actions
if (isset($_POST['action']) && $_POST['action'] !== '-1' && isset($_POST['log_ids'])) {
    $action = sanitize_text_field($_POST['action']);
    $log_ids = array_map('intval', $_POST['log_ids']);
    
    if (!wp_verify_nonce($_POST['_wpnonce'], 'bulk-logs')) {
        wp_die(__('Security check failed.', 'wp-user-activity-logger'));
    }
    
    if ($action === 'delete' && current_user_can('manage_options')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_log';
        $ids = implode(',', $log_ids);
        $wpdb->query("DELETE FROM $table_name WHERE id IN ($ids)");
        
        echo '<div class="notice notice-success"><p>' . __('Selected logs have been deleted.', 'wp-user-activity-logger') . '</p></div>';
    }
}

// Get filter parameters
$activity_type_filter = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '';
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Build query
global $wpdb;
$table_name = $wpdb->prefix . 'activity_log';
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

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count
$count_query = "SELECT COUNT(*) FROM $table_name $where_clause";
if (!empty($where_values)) {
    $count_query = $wpdb->prepare($count_query, $where_values);
}
$total_logs = $wpdb->get_var($count_query);

// Get logs
$query = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_values = array_merge($where_values, array($per_page, $offset));
$logs = $wpdb->get_results($wpdb->prepare($query, $query_values));

// Get unique activity types for filter
$activity_types = $wpdb->get_col("SELECT DISTINCT activity_type FROM $table_name ORDER BY activity_type");

// Get users for filter
$users = $wpdb->get_results("SELECT DISTINCT user_id FROM $table_name WHERE user_id IS NOT NULL AND user_id > 0");

$total_pages = ceil($total_logs / $per_page);
?>

<div class="wrap">
    <h1><?php _e('User Activity Log', 'wp-user-activity-logger'); ?></h1>
    
    <!-- Statistics -->
    <div class="wpual-stats">
        <div class="stat-box">
            <h3><?php echo number_format($total_logs); ?></h3>
            <p><?php _e('Total Logs', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo count($activity_types); ?></h3>
            <p><?php _e('Activity Types', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo count($users); ?></h3>
            <p><?php _e('Active Users', 'wp-user-activity-logger'); ?></p>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="wpual-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="wp-user-activity-log">
            
            <div class="filter-row">
                <div class="filter-group">
                    <label for="activity_type"><?php _e('Activity Type:', 'wp-user-activity-logger'); ?></label>
                    <select name="activity_type" id="activity_type">
                        <option value=""><?php _e('All Types', 'wp-user-activity-logger'); ?></option>
                        <?php foreach ($activity_types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($activity_type_filter, $type); ?>>
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $type))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="user_id"><?php _e('User:', 'wp-user-activity-logger'); ?></label>
                    <select name="user_id" id="user_id">
                        <option value=""><?php _e('All Users', 'wp-user-activity-logger'); ?></option>
                        <?php foreach ($users as $user_data): ?>
                            <?php $user = get_user_by('id', $user_data->user_id); ?>
                            <?php if ($user): ?>
                                <option value="<?php echo $user->ID; ?>" <?php selected($user_filter, $user->ID); ?>>
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="date_from"><?php _e('Date From:', 'wp-user-activity-logger'); ?></label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="date_to"><?php _e('Date To:', 'wp-user-activity-logger'); ?></label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="search"><?php _e('Search:', 'wp-user-activity-logger'); ?></label>
                    <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search in details, URL, or IP...', 'wp-user-activity-logger'); ?>">
                </div>
                
                <div class="filter-actions">
                    <input type="submit" class="button" value="<?php _e('Filter', 'wp-user-activity-logger'); ?>">
                    <a href="?page=wp-user-activity-log" class="button"><?php _e('Clear Filters', 'wp-user-activity-logger'); ?></a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Actions -->
    <div class="wpual-actions">
        <button type="button" class="button" id="export-logs"><?php _e('Export CSV', 'wp-user-activity-logger'); ?></button>
        <button type="button" class="button button-secondary" id="clear-logs"><?php _e('Clear All Logs', 'wp-user-activity-logger'); ?></button>
    </div>
    
    <!-- Logs Table -->
    <form method="post" action="">
        <?php wp_nonce_field('bulk-logs'); ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="action">
                    <option value="-1"><?php _e('Bulk Actions', 'wp-user-activity-logger'); ?></option>
                    <option value="delete"><?php _e('Delete', 'wp-user-activity-logger'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'wp-user-activity-logger'); ?>">
            </div>
            
            <div class="tablenav-pages">
                <?php if ($total_pages > 1): ?>
                    <span class="displaying-num"><?php printf(__('%s items', 'wp-user-activity-logger'), number_format($total_logs)); ?></span>
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
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </td>
                    <th scope="col" class="manage-column column-id"><?php _e('ID', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-user"><?php _e('User', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-ip"><?php _e('IP Address', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-activity"><?php _e('Activity', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-details"><?php _e('Details', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-url"><?php _e('Page URL', 'wp-user-activity-logger'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php _e('Date', 'wp-user-activity-logger'); ?></th>
                </tr>
            </thead>
            
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7"><?php _e('No activity logs found.', 'wp-user-activity-logger'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="log_ids[]" value="<?php echo $log->id; ?>">
                            </th>
                            <td class="column-id"><?php echo $log->id; ?></td>
                            <td class="column-user">
                                <?php if ($log->user_id): ?>
                                    <?php $user = get_user_by('id', $log->user_id); ?>
                                    <?php if ($user): ?>
                                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>">
                                            <?php echo get_avatar($user->ID, 32); ?>
                                            <strong><?php echo esc_html($user->display_name); ?></strong>
                                        </a>
                                        <br>
                                        <small><?php echo esc_html($user->user_email); ?></small>
                                    <?php else: ?>
                                        <em><?php _e('User deleted', 'wp-user-activity-logger'); ?></em>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <em><?php _e('Guest', 'wp-user-activity-logger'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td class="column-ip"><?php echo esc_html($log->user_ip); ?></td>
                            <td class="column-activity">
                                <span class="activity-type activity-<?php echo esc_attr($log->activity_type); ?>">
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $log->activity_type))); ?>
                                </span>
                            </td>
                            <td class="column-details"><?php echo esc_html($log->activity_details); ?></td>
                            <td class="column-url">
                                <?php if ($log->page_url): ?>
                                    <a href="<?php echo esc_url($log->page_url); ?>" target="_blank" title="<?php echo esc_attr($log->page_url); ?>">
                                        <?php echo esc_html(wp_parse_url($log->page_url, PHP_URL_PATH) ?: $log->page_url); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="column-date">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action2">
                    <option value="-1"><?php _e('Bulk Actions', 'wp-user-activity-logger'); ?></option>
                    <option value="delete"><?php _e('Delete', 'wp-user-activity-logger'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'wp-user-activity-logger'); ?>">
            </div>
            
            <div class="tablenav-pages">
                <?php if ($total_pages > 1): ?>
                    <span class="displaying-num"><?php printf(__('%s items', 'wp-user-activity-logger'), number_format($total_logs)); ?></span>
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
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Export logs
    $('#export-logs').on('click', function() {
        var form = $('<form>', {
            'method': 'POST',
            'action': ajaxurl
        }).append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'wpual_export_logs'
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'nonce',
            'value': wpual_ajax.nonce
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
    });
    
    // Clear logs
    $('#clear-logs').on('click', function() {
        if (confirm(wpual_ajax.confirm_clear)) {
            $.post(ajaxurl, {
                action: 'wpual_clear_logs',
                nonce: wpual_ajax.nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert(response.data);
                }
            });
        }
    });
});
</script> 