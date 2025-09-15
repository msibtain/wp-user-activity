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

// Get selected user info if user_id is provided
$selected_user = null;
if ($user_id > 0) {
    $selected_user = get_user_by('id', $user_id);
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
                    <label for="user_id"><?php _e('User:', 'wp-user-activity-logger'); ?></label>
                    <input type="text" id="user_search" placeholder="<?php _e('Search users...', 'wp-user-activity-logger'); ?>" autocomplete="off">
                    <div class="user-search-container">
                        <input type="hidden" name="user_id" id="user_id" value="<?php echo esc_attr($user_id); ?>">
                        <div class="user-search-results" id="user_search_results"></div>
                        <?php if ($user_id > 0 && $selected_user): ?>
                            <div class="selected-user" id="selected_user_display">
                                <span><?php echo esc_html($selected_user->display_name . ' (' . $selected_user->user_email . ')'); ?></span>
                                <button type="button" class="remove-user" id="remove_user">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
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

        // User search functionality
        var userSearchTimeout;
        var userSearchResults = [];
        
        $('#user_search').on('keyup', function() {
            var searchTerm = $(this).val().trim();
            var $results = $('#user_search_results');
            
            clearTimeout(userSearchTimeout);
            
            if (searchTerm.length < 2) {
                $results.hide().empty();
                return;
            }
            
            userSearchTimeout = setTimeout(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'wpual_search_users',
                        search: searchTerm
                    },
                    success: function(response) {
                        userSearchResults = response;
                        displayUserResults(response);
                    },
                    error: function() {
                        $results.html('<div class="user-result-item error">Error loading users</div>').show();
                    }
                });
            }, 300);
        });
        
        function displayUserResults(users) {
            var $results = $('#user_search_results');
            
            if (users.length === 0) {
                $results.html('<div class="user-result-item no-results">No users found</div>').show();
                return;
            }
            
            var html = '';
            users.forEach(function(user) {
                html += '<div class="user-result-item" data-user-id="' + user.id + '" data-user-text="' + user.text + '">';
                html += '<div class="user-name">' + user.display_name + '</div>';
                html += '<div class="user-email">' + user.email + '</div>';
                html += '</div>';
            });
            
            $results.html(html).show();
        }
        
        // Handle user selection
        $(document).on('click', '.user-result-item', function() {
            var userId = $(this).data('user-id');
            var userText = $(this).data('user-text');
            var userName = $(this).find('.user-name').text();
            var userEmail = $(this).find('.user-email').text();
            
            $('#user_id').val(userId);
            $('#user_search').val('').hide();
            $('#user_search_results').hide();
            
            var selectedUserHtml = '<div class="selected-user" id="selected_user_display">';
            selectedUserHtml += '<span>' + userName + ' (' + userEmail + ')</span>';
            selectedUserHtml += '<button type="button" class="remove-user" id="remove_user">&times;</button>';
            selectedUserHtml += '</div>';
            
            $('.user-search-container').append(selectedUserHtml);
        });
        
        // Handle user removal
        $(document).on('click', '.remove-user', function() {
            $('#user_id').val('');
            $('#selected_user_display').remove();
            $('#user_search').show().val('').focus();
        });
        
        // Hide results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.user-search-container').length) {
                $('#user_search_results').hide();
            }
        });
        
        // Show search input when clicking on container if no user is selected
        $('.user-search-container').on('click', function() {
            if (!$('#user_id').val()) {
                $('#user_search').show().focus();
            }
        });
        
        // Initialize: show search input if no user is selected
        if (!$('#user_id').val()) {
            $('#user_search').show();
        }
        
        // Keyboard navigation for search results
        $('#user_search').on('keydown', function(e) {
            var $results = $('#user_search_results');
            var $items = $results.find('.user-result-item');
            var currentIndex = $items.index($results.find('.user-result-item.highlighted'));
            
            switch(e.keyCode) {
                case 38: // Up arrow
                    e.preventDefault();
                    $items.removeClass('highlighted');
                    if (currentIndex > 0) {
                        $items.eq(currentIndex - 1).addClass('highlighted');
                    } else {
                        $items.last().addClass('highlighted');
                    }
                    break;
                case 40: // Down arrow
                    e.preventDefault();
                    $items.removeClass('highlighted');
                    if (currentIndex < $items.length - 1) {
                        $items.eq(currentIndex + 1).addClass('highlighted');
                    } else {
                        $items.first().addClass('highlighted');
                    }
                    break;
                case 13: // Enter
                    e.preventDefault();
                    var $highlighted = $results.find('.user-result-item.highlighted');
                    if ($highlighted.length) {
                        $highlighted.click();
                    }
                    break;
                case 27: // Escape
                    $results.hide();
                    break;
            }
        });
        
        // Highlight first result on hover
        $(document).on('mouseenter', '.user-result-item', function() {
            $('#user_search_results .user-result-item').removeClass('highlighted');
            $(this).addClass('highlighted');
        });
    });
})(jQuery);
</script>


