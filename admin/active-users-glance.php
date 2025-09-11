<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Inputs
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$activity_type = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : 'page_view';

// Default to last 30 days if not provided
if (empty($date_from) || empty($date_to)) {
    $date_to = date('Y-m-d');
    $date_from = date('Y-m-d', strtotime('-30 days'));
}

global $wpdb;
$table_name = $wpdb->prefix . 'activity_log';

// Build where clauses
$where_conditions = array('DATE(created_at) BETWEEN %s AND %s');
$where_values = array($date_from, $date_to);

if ($user_id > 0) {
    $where_conditions[] = 'user_id = %d';
    $where_values[] = $user_id;
}

if (in_array($activity_type, array('page_view', 'category_view', 'video_view'), true)) {
    $where_conditions[] = 'activity_type = %s';
    $where_values[] = $activity_type;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions) . ' AND user_id IS NOT NULL AND user_id > 0';

// Daily aggregation for chart
$chart_query = "
    SELECT DATE(created_at) as activity_date,
           COUNT(*) as total
    FROM $table_name
    $where_clause
    GROUP BY DATE(created_at)
    ORDER BY activity_date ASC
";
$chart_rows = $wpdb->get_results($wpdb->prepare($chart_query, $where_values));

// Prepare chart series across the range
$labels = array();
$values = array();
$current = strtotime($date_from);
$end = strtotime($date_to);
$by_date = array();
foreach ($chart_rows as $row) {
    $by_date[$row->activity_date] = intval($row->total);
}
for ($ts = $current; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
    $key = date('Y-m-d', $ts);
    $labels[] = date_i18n('M j', $ts);
    $values[] = isset($by_date[$key]) ? $by_date[$key] : 0;
}

// Build Users list for dropdown (users with activity in the period/filter)
$users_for_dropdown = array();
$users_ids_query = "
    SELECT DISTINCT user_id
    FROM $table_name
    $where_clause
    ORDER BY user_id ASC
    LIMIT 500
";
$dropdown_user_ids = $wpdb->get_col($wpdb->prepare($users_ids_query, $where_values));
if (!empty($dropdown_user_ids)) {
    $wp_users = get_users(array(
        'include' => $dropdown_user_ids,
        'orderby' => 'display_name',
        'order' => 'ASC',
        'fields' => array('ID', 'display_name', 'user_email')
    ));
    foreach ($wp_users as $uobj) {
        $users_for_dropdown[] = array(
            'id' => $uobj->ID,
            'label' => $uobj->display_name . ' (' . $uobj->user_email . ')'
        );
    }
}

// User dropdown helper
function wpual_glance_user_label($user) {
    return $user->display_name . ' (' . $user->user_email . ')';
}

// Preload one user display if selected
$selected_user_label = '';
if ($user_id > 0) {
    $u = get_user_by('id', $user_id);
    if ($u) { $selected_user_label = wpual_glance_user_label($u); }
}
?>

<div class="wrap">
    <h1><?php _e('Active users at glance', 'wp-user-activity-logger'); ?></h1>

    <div class="wpual-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="wp-user-activity-active-glance">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="date_from"><?php _e('Date from', 'wp-user-activity-logger'); ?></label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to"><?php _e('Date to', 'wp-user-activity-logger'); ?></label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>
                <div class="filter-group">
                    <label for="user_id"><?php _e('User', 'wp-user-activity-logger'); ?></label>
                    <select id="user_id" name="user_id" data-loaded="true">
                        <option value="0"><?php _e('All users', 'wp-user-activity-logger'); ?></option>
                        <?php if (!empty($users_for_dropdown)): ?>
                            <?php foreach ($users_for_dropdown as $ud): ?>
                                <option value="<?php echo esc_attr($ud['id']); ?>" <?php selected($user_id, $ud['id']); ?>><?php echo esc_html($ud['label']); ?></option>
                            <?php endforeach; ?>
                        <?php elseif ($user_id > 0 && $selected_user_label): ?>
                            <option value="<?php echo esc_attr($user_id); ?>" selected><?php echo esc_html($selected_user_label); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="activity_type"><?php _e('Activity Type', 'wp-user-activity-logger'); ?></label>
                    <select id="activity_type" name="activity_type">
                        <option value="page_view" <?php selected($activity_type, 'page_view'); ?>><?php _e('Page View Activity', 'wp-user-activity-logger'); ?></option>
                        <option value="category_view" <?php selected($activity_type, 'category_view'); ?>><?php _e('Category View Activity', 'wp-user-activity-logger'); ?></option>
                        <option value="video_view" <?php selected($activity_type, 'video_view'); ?>><?php _e('Video View Activity', 'wp-user-activity-logger'); ?></option>
                    </select>
                </div>
                <div class="filter-actions">
                    <input type="submit" class="button button-primary" value="<?php _e('Apply', 'wp-user-activity-logger'); ?>">
                    <a class="button" href="?page=wp-user-activity-active-glance"><?php _e('Reset', 'wp-user-activity-logger'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <div class="wpual-chart">
        <?php
        $jsLabel = '';
        if ($activity_type == '' || $activity_type == 'page_view') 
        {
            $jsLabel = 'Page View Activity Trend';
            ?>
            <h2><?php _e('Page View Activity Trend', 'wp-user-activity-logger'); ?></h2>
            <?php
        }
        if ($activity_type == 'category_view') 
        {
            $jsLabel = 'Category View Activity Trend';
            ?>
            <h2><?php _e('Category View Activity Trend', 'wp-user-activity-logger'); ?></h2>
            <?php
        }
        if ($activity_type == 'video_view') 
        {
            $jsLabel = 'Video View Activity Trend';
            ?>
            <h2><?php _e('Video View Activity Trend', 'wp-user-activity-logger'); ?></h2>
            <?php
        }
        ?>
        
        <div style="position: relative; height: 360px;">
            <canvas id="wpualGlanceChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script type="text/javascript">
(function($){
    $(function(){
        var ctx = document.getElementById('wpualGlanceChart');
        if (!ctx) { return; }
        var labels = <?php echo wp_json_encode($labels); ?>;
        var values = <?php echo wp_json_encode($values); ?>;
        var color = 'rgba(0, 115, 170, 0.8)';
        if ('<?php echo esc_js($activity_type); ?>' === 'category_view') {
            color = 'rgba(255, 159, 64, 0.9)';
        } else if ('<?php echo esc_js($activity_type); ?>' === 'video_view') {
            color = 'rgba(220, 53, 69, 0.9)';
        }
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '<?php echo esc_js($jsLabel); ?>',
                    data: values,
                    borderColor: color,
                    backgroundColor: 'rgba(0,0,0,0)',
                    tension: 0.25,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top' } },
                scales: { y: { ticks: { precision: 0 } }, x: { grid: { display: false } } }
            }
        });

        // Dropdown is pre-populated server-side; no lazy load needed
    });
})(jQuery);
</script>


