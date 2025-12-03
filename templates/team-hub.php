<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

global $post;

// Check if user is a manager
$user_is_a_manager = get_user_meta(get_current_user_id(), 'user_is_a_manager', true);

if (!$user_is_a_manager) {
    echo '<div class="wpual-team-hub-notice"><p>You do not have permission to view this page.</p></div>';
    return;
}

// Get users Team Hub;
$team_hubs = get_user_meta(get_current_user_id(), 'team_hubs', true);
if ($team_hubs) {
    ?>
    <p>Choose a team hub to view users activities in the hub:</p>
    <ul class="wpual-team-hub-pills">
        <?php foreach ($team_hubs as $team_hub_id) { $url = add_query_arg('team_hub_id', $team_hub_id, get_permalink($post->ID)); ?>
            <li>
                <a <?php if ($_GET['team_hub_id'] == $team_hub_id) echo 'class="active"'; ?> href="<?php echo esc_url($url); ?>"><?php echo get_the_title($team_hub_id); ?></a>
            </li>
        <?php } ?>
    </ul>
    <?php
}

// Check if team_hub_id is provided in URL
$team_hub_id = isset($_GET['team_hub_id']) ? intval($_GET['team_hub_id']) : 0;

// Get team users based on team_hub_id
if ($team_hub_id) {
    // Get all users who have this team_hub_id in their team_hubs meta
    $all_users = get_users(array('fields' => 'ID'));
    $team_users = array();
    
    foreach ($all_users as $user_id) {
        $user_team_hubs = get_user_meta($user_id, 'team_hubs', true);
        if (is_array($user_team_hubs) && in_array($team_hub_id, $user_team_hubs)) {
            $team_users[] = $user_id;
        }
    }
    
    if (empty($team_users)) {
        echo '<div class="wpual-team-hub-notice"><p>No team members found for this team hub.</p></div>';
        return;
    }
 


    // Get activities for team users
    global $wpdb;
    $table_name = $wpdb->prefix . 'activity_log';

    // Build query to get activities
    $placeholders = implode(',', array_fill(0, count($team_users), '%d'));
    $where_conditions = array("user_id IN ($placeholders)");
    $where_values = $team_users;

    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

    // Get activities for table display (last 50 activities)
    $activities_query = "
        SELECT al.*, u.display_name, u.user_email
        FROM $table_name al
        LEFT JOIN {$wpdb->users} u ON al.user_id = u.ID
        $where_clause
        ORDER BY al.created_at DESC
        LIMIT 50
    ";

    $activities = $wpdb->get_results($wpdb->prepare($activities_query, $where_values));

    // Get chart data - activities grouped by date (last 30 days)
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $chart_where_conditions = array_merge($where_conditions, array('DATE(created_at) >= %s'));
    $chart_where_values = array_merge($team_users, array($date_from));
    $chart_where_clause = 'WHERE ' . implode(' AND ', $chart_where_conditions);

    $chart_query = "
        SELECT DATE(created_at) as activity_date,
            COUNT(*) as total_activities,
            COUNT(DISTINCT user_id) as active_users
        FROM $table_name
        $chart_where_clause
        GROUP BY DATE(created_at)
        ORDER BY activity_date ASC
    ";

    $chart_data = $wpdb->get_results($wpdb->prepare($chart_query, $chart_where_values));

    // Prepare chart labels and data
    $chart_labels = array();
    $chart_activities = array();
    $chart_active_users = array();

    // Fill in all dates for the last 30 days
    $current_date = strtotime($date_from);
    $end_date = strtotime('today');
    $chart_data_by_date = array();

    foreach ($chart_data as $row) {
        $chart_data_by_date[$row->activity_date] = array(
            'activities' => intval($row->total_activities),
            'users' => intval($row->active_users)
        );
    }

    for ($ts = $current_date; $ts <= $end_date; $ts = strtotime('+1 day', $ts)) {
        $date_key = date('Y-m-d', $ts);
        $chart_labels[] = date('M j', $ts);
        
        if (isset($chart_data_by_date[$date_key])) {
            $chart_activities[] = $chart_data_by_date[$date_key]['activities'];
            $chart_active_users[] = $chart_data_by_date[$date_key]['users'];
        } else {
            $chart_activities[] = 0;
            $chart_active_users[] = 0;
        }
    }

    // Get team statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT user_id) as total_users,
            SUM(duration) as total_duration,
            COUNT(DISTINCT DATE(created_at)) as active_days
        FROM $table_name
        $where_clause
    ";

    $stats = $wpdb->get_row($wpdb->prepare($stats_query, $where_values));

    // Format duration helper function
    function wpual_format_duration($seconds) {
        $seconds = intval($seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return $minutes . 'm ' . $remaining_seconds . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }
    ?>

    <div class="wpual-team-hub-container">
        <div class="wpual-team-hub-header">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h1 style="margin: 0;">Team Activity Hub</h1>
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpual_export_team_pdf&nonce=' . wp_create_nonce('wpual_nonce'))); ?>" 
                class="wpual-export-pdf-button">
                    Export to PDF
                </a>
            </div>
            <?php if ($team_hub_id): ?>
                <p>Viewing activities for team hub: <strong><?php echo esc_html(get_the_title($team_hub_id)); ?></strong></p>
            <?php else: ?>
                <p>Viewing activities for users with role: <strong><?php echo esc_html(ucfirst($user_role)); ?></strong></p>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="wpual-team-hub-stats">
            <div class="wpual-team-hub-stat-box">
                <h3><?php echo number_format($stats->total_activities); ?></h3>
                <p>Total Activities</p>
            </div>
            <div class="wpual-team-hub-stat-box">
                <h3><?php echo number_format($stats->total_users); ?></h3>
                <p>Team Members</p>
            </div>
            <div class="wpual-team-hub-stat-box">
                <h3><?php echo wpual_format_duration($stats->total_duration); ?></h3>
                <p>Total Duration</p>
            </div>
            <div class="wpual-team-hub-stat-box">
                <h3><?php echo number_format($stats->active_days); ?></h3>
                <p>Active Days</p>
            </div>
        </div>

        <!-- Chart -->
        <div class="wpual-team-hub-chart">
            <h2>Team Activity Trend (Last 30 Days)</h2>
            <div style="position: relative; height: 400px;">
                <canvas id="wpualTeamChart"></canvas>
            </div>
        </div>

        <!-- Activities Table -->
        <div class="wpual-team-hub-table-container">
            <h2>Recent Team Activities</h2>
            <?php if (!empty($activities)): ?>
                <table class="wpual-team-hub-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>User</th>
                            <th>Activity Type</th>
                            <th>Details</th>
                            <th>Duration</th>
                            <th>Page URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y H:i', strtotime($activity->created_at))); ?></td>
                                <td>
                                    <strong><?php echo esc_html($activity->display_name ? $activity->display_name : 'Unknown'); ?></strong><br>
                                    <small style="color: #646970;"><?php echo esc_html($activity->user_email ? $activity->user_email : ''); ?></small>
                                </td>
                                <td>
                                    <span class="wpual-activity-type <?php echo esc_attr($activity->activity_type); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $activity->activity_type))); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($activity->activity_details); ?></td>
                                <td><?php echo wpual_format_duration($activity->duration); ?></td>
                                <td>
                                    <?php if ($activity->page_url): ?>
                                        <a href="<?php echo esc_url($activity->page_url); ?>" target="_blank" style="color: #2271b1; text-decoration: none;">
                                            <?php echo esc_html(strlen($activity->page_url) > 50 ? substr($activity->page_url, 0, 50) . '...' : $activity->page_url); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #646970;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="padding: 20px; color: #646970;">No activities found for team members.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script type="text/javascript">
    (function($){
        $(function(){
            var ctx = document.getElementById('wpualTeamChart');
            if (!ctx) { return; }
            
            var labels = <?php echo wp_json_encode($chart_labels); ?>;
            var activities = <?php echo wp_json_encode($chart_activities); ?>;
            var activeUsers = <?php echo wp_json_encode($chart_active_users); ?>;
            
            var chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Daily Activities',
                            data: activities,
                            backgroundColor: 'rgba(34, 113, 177, 0.5)',
                            borderColor: 'rgba(34, 113, 177, 0.8)',
                            borderWidth: 1,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: 'Daily Active Users',
                            data: activeUsers,
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
                    interaction: { 
                        mode: 'index', 
                        intersect: false 
                    },
                    plugins: {
                        legend: { 
                            position: 'top',
                            display: true
                        },
                        tooltip: { 
                            enabled: true 
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            ticks: { 
                                precision: 0,
                                beginAtZero: true
                            },
                            title: {
                                display: true,
                                text: 'Activities'
                            }
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            grid: { 
                                drawOnChartArea: false 
                            },
                            ticks: { 
                                precision: 0,
                                beginAtZero: true
                            },
                            title: {
                                display: true,
                                text: 'Active Users'
                            }
                        },
                        x: {
                            grid: { 
                                display: false 
                            }
                        }
                    }
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}
?>
<style>
.wpual-team-hub-container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.wpual-team-hub-pills {
    list-style: none;
    padding: 0;
    margin: 20px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.wpual-team-hub-pills li {
    margin: 0;
}

.wpual-team-hub-pills a {
    display: inline-block;
    padding: 10px 20px;
    background: #2271b1;
    color: #fff !important;
    text-decoration: none !important;
    border-radius: 6px;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
    border: 2px solid #2271b1;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.wpual-team-hub-pills a:hover {
    background: #135e96;
    border-color: #135e96;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.wpual-team-hub-pills a:active, .wpual-team-hub-pills a.active {
    background: #0a4b78;
    border-color: #0a4b78;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    transform: translateY(0);
}

.wpual-team-hub-pills a:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.3);
}

.wpual-team-hub-notice {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 15px;
    margin: 20px;
    color: #856404;
}

.wpual-team-hub-header {
    margin-bottom: 30px;
}

.wpual-team-hub-header h1 {
    margin: 0 0 10px 0;
    color: #23282d;
    font-size: 28px;
}

.wpual-team-hub-header p {
    color: #646970;
    margin: 0;
}

.wpual-team-hub-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.wpual-team-hub-stat-box {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wpual-team-hub-stat-box h3 {
    margin: 0 0 5px 0;
    font-size: 32px;
    color: #2271b1;
    font-weight: 600;
}

.wpual-team-hub-stat-box p {
    margin: 0;
    color: #646970;
    font-size: 14px;
}

.wpual-team-hub-chart {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wpual-team-hub-chart h2 {
    margin: 0 0 20px 0;
    color: #23282d;
    font-size: 20px;
}

.wpual-team-hub-chart canvas {
    max-height: 400px;
}

.wpual-team-hub-table-container {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wpual-team-hub-table-container h2 {
    margin: 0 0 20px 0;
    color: #23282d;
    font-size: 20px;
}

.wpual-team-hub-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.wpual-team-hub-table th {
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #23282d;
}

.wpual-team-hub-table td {
    border: 1px solid #c3c4c7;
    padding: 12px;
    color: #50575e;
}

.wpual-team-hub-table tr:nth-child(even) {
    background: #f9f9f9;
}

.wpual-team-hub-table tr:hover {
    background: #f0f0f1;
}

.wpual-activity-type {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
}

.wpual-activity-type.login {
    background: #00a32a;
    color: #fff;
}

.wpual-activity-type.logout {
    background: #d63638;
    color: #fff;
}

.wpual-activity-type.page_view {
    background: #2271b1;
    color: #fff;
}

.wpual-activity-type.category_view {
    background: #dba617;
    color: #fff;
}

.wpual-activity-type.archive_view {
    background: #8c8f94;
    color: #fff;
}

.wpual-activity-type.video_view {
    background: #a7aaad;
    color: #fff;
}

.wpual-export-pdf-button {
    background: #2271b1 !important;
    color: #fff !important;
    padding: 10px 20px !important;
    text-decoration: none !important;
    border-radius: 4px !important;
    font-weight: 500 !important;
    display: inline-block !important;
    transition: background-color 0.2s ease;
    border: none;
    cursor: pointer;
}

.wpual-export-pdf-button:hover {
    background: #135e96 !important;
    color: #fff !important;
}

.wpual-export-pdf-button:active {
    background: #0a4b78 !important;
}
</style>