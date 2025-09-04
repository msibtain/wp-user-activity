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

// Build daily trend data for chart
// Reuse the existing $where_clause and $where_values to respect filters/period
$trend_query = "
    SELECT 
        DATE(created_at) as activity_date,
        COUNT(DISTINCT user_id) as daily_active_users,
        COUNT(*) as daily_activities,
        SUM(duration) as daily_duration
    FROM $table_name 
    $where_clause 
    AND user_id IS NOT NULL 
    AND user_id > 0
    GROUP BY DATE(created_at)
    ORDER BY activity_date ASC
";

$trend_rows = $wpdb->get_results($wpdb->prepare($trend_query, $where_values));

// Normalize series to include all days in the selected period
$days = intval($activity_period);
$trend_map = array();
if (!empty($trend_rows)) {
	foreach ($trend_rows as $row) {
		$trend_map[$row->activity_date] = $row;
	}
}

$chart_labels = array();
$chart_dau = array();
$chart_activities = array();

for ($i = $days - 1; $i >= 0; $i--) {
	$day_key = date('Y-m-d', strtotime("-{$i} days"));
	$label = date_i18n('M j', strtotime($day_key));
	$chart_labels[] = $label;
	if (isset($trend_map[$day_key])) {
		$chart_dau[] = intval($trend_map[$day_key]->daily_active_users);
		$chart_activities[] = intval($trend_map[$day_key]->daily_activities);
	} else {
		$chart_dau[] = 0;
		$chart_activities[] = 0;
	}
}
?>

<div class="wrap">
    <h1><?php _e('Active Users', 'wp-user-activity-logger'); ?></h1>
    
    <!-- Statistics -->
    <div class="wpual-stats" style="display: none;">
        <div class="stat-box">
            <h3><?php echo number_format($total_users); ?></h3>
            <p><?php _e('Active Users', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3><?php echo $activity_period; ?></h3>
            <p><?php _e('Days Period', 'wp-user-activity-logger'); ?></p>
        </div>
        <div class="stat-box">
            <h3>
                <a href="admin.php?page=wp-user-activity-log">
                    <?php echo number_format($overall_stats->total_activities); ?>
                </a>
            </h3>
            <p><?php _e('Total Activities', 'wp-user-activity-logger'); ?></p>
        </div>
    </div>
    
    <!-- Active Users Trend Chart -->
    <div class="wpual-chart">
        <h2><?php _e('Active Users Trend', 'wp-user-activity-logger'); ?></h2>
        <div style="position: relative; height: 360px;">
            <canvas id="wpualTrendChart"></canvas>
        </div>
    </div>

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
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="search"><?php _e('Search:', 'wp-user-activity-logger'); ?></label>
                    <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search users...', 'wp-user-activity-logger'); ?>">
                </div>
                
                <div class="filter-actions">
                    <input type="submit" class="button" value="<?php _e('Filter', 'wp-user-activity-logger'); ?>">
                    <a href="?page=wp-user-activity-active-users" class="button"><?php _e('Clear Filters', 'wp-user-activity-logger'); ?></a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Actions -->
    <div class="wpual-actions">
        <div id="frmUserExportWrapper"></div>
        <button type="button" class="button" id="export-selected-users" onclick="exportSelectedUsers('<?php echo $user_role_filter; ?>', '<?php echo $activity_period; ?>', '<?php echo $search; ?>')"><?php _e('Export Selected Users', 'wp-user-activity-logger'); ?></button>
        <span id="selected-count" class="selected-count" style="display: none;">0 <?php _e('selected', 'wp-user-activity-logger'); ?></span>
        
        <?php 
        $export_url = admin_url('admin-ajax.php') . '?' . http_build_query(array(
            'action' => 'wpual_export_active_users',
            'nonce' => wp_create_nonce('wpual_nonce'),
            'user_role' => $user_role_filter,
            'activity_period' => $activity_period,
            'search' => $search
        ));
        ?>
        <a href="<?php echo esc_url($export_url); ?>" class="button" target="_blank"><?php _e('Export All CSV', 'wp-user-activity-logger'); ?></a>
    </div>
    
    <!-- Users Table -->
    <form method="post" id="active-users-form">
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="bulk_action">
                    <option value="-1"><?php _e('Bulk Actions', 'wp-user-activity-logger'); ?></option>
                    <option value="export"><?php _e('Export Selected', 'wp-user-activity-logger'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'wp-user-activity-logger'); ?>">
            </div>
            
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
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </td>
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
                        <td colspan="10"><?php _e('No active users found.', 'wp-user-activity-logger'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($active_users as $user_data): ?>
                        <?php $user = get_user_by('id', $user_data->user_id); ?>
                        <?php if ($user): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="selected_users[]" value="<?php echo $user->ID; ?>">
                                </th>
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
    </form>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Debug: Check if AJAX variables are loaded
    console.log('wpual_ajax object:', wpual_ajax);
    console.log('AJAX URL:', wpual_ajax.ajax_url);
    console.log('Nonce:', wpual_ajax.nonce);
    console.log('Expected AJAX URL:', '<?php echo admin_url('admin-ajax.php'); ?>');
    
    // Select all functionality
    $('#cb-select-all-1').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('input[name="selected_users[]"]').prop('checked', isChecked);
        
        var totalCheckboxes = $('input[name="selected_users[]"]').length;
        if (isChecked) {
            $('#selected-count').show().text(totalCheckboxes + ' <?php _e('selected', 'wp-user-activity-logger'); ?>');
        } else {
            $('#selected-count').hide();
        }
    });
    
    // Individual checkbox change
    $('input[name="selected_users[]"]').on('change', function() {
        var totalCheckboxes = $('input[name="selected_users[]"]').length;
        var checkedCheckboxes = $('input[name="selected_users[]"]:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#cb-select-all-1').prop('indeterminate', false).prop('checked', false);
            $('#selected-count').hide();
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#cb-select-all-1').prop('indeterminate', false).prop('checked', true);
            $('#selected-count').show().text(checkedCheckboxes + ' <?php _e('selected', 'wp-user-activity-logger'); ?>');
        } else {
            $('#cb-select-all-1').prop('indeterminate', true);
            $('#selected-count').show().text(checkedCheckboxes + ' <?php _e('selected', 'wp-user-activity-logger'); ?>');
        }
    });
    
    
    
});

function exportSelectedUsers(user_role, activity_period, search) {
    var selectedUsers = jQuery('input[name="selected_users[]"]:checked').map(function() {
        return jQuery(this).val();
    }).get();
    
    if (selectedUsers.length === 0) {
        alert('<?php _e('Please select at least one user to export.', 'wp-user-activity-logger'); ?>');
        return;
    }
    
    console.log('Exporting selected users:', selectedUsers);
    
    window.open(wpual_ajax.ajax_url + '?' + jQuery.param({
        action: 'wpual_export_active_users',
        nonce: wpual_ajax.nonce,
        user_role: user_role,
        activity_period: activity_period,
        search: search,
        selected_user_ids: selectedUsers
    }), '_blank');
}
</script>


<!-- Chart.js and chart render -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script type="text/javascript">
(function($){
	$(function(){
		var ctx = document.getElementById('wpualTrendChart');
		if (!ctx) { return; }
		var labels = <?php echo wp_json_encode($chart_labels); ?>;
		var dau = <?php echo wp_json_encode($chart_dau); ?>;
		var activities = <?php echo wp_json_encode($chart_activities); ?>;
		var chart = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label: '<?php echo esc_js(__('Daily Activities', 'wp-user-activity-logger')); ?>',
						data: activities,
						backgroundColor: 'rgba(0, 115, 170, 0.25)',
						borderColor: 'rgba(0, 115, 170, 0.8)',
						borderWidth: 1,
						yAxisID: 'y'
					},
					{
						type: 'line',
						label: '<?php echo esc_js(__('Daily Active Users', 'wp-user-activity-logger')); ?>',
						data: dau,
						borderColor: 'rgba(220, 53, 69, 0.9)',
						backgroundColor: 'rgba(220, 53, 69, 0.2)',
						tension: 0.25,
						fill: false,
						yAxisID: 'y1'
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'top' },
					tooltip: { enabled: true }
				},
				scales: {
					y: {
						type: 'linear',
						position: 'left',
						ticks: { precision: 0 }
					},
					y1: {
						type: 'linear',
						position: 'right',
						grid: { drawOnChartArea: false },
						ticks: { precision: 0 }
					},
					x: {
						grid: { display: false }
					}
				}
			}
		});
	});
})(jQuery);
</script>
 