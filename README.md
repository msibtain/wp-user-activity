# WP User Activity Logger

A comprehensive WordPress plugin that logs user activity across your website, including user logins, page views, category views, and archive views.

## Features

### Activity Logging
- **User Login/Logout Tracking**: Records when users log in and out of the system
- **Page View Logging**: Tracks every page view on your website with time duration
- **Category View Logging**: Monitors when users browse specific categories
- **Archive View Logging**: Logs visits to date-based archives, author pages, and other archive types
- **Time Duration Tracking**: Records how long users stay on each page
- **Bot Detection**: Automatically filters out search engine bots and crawlers
- **IP Address Tracking**: Records user IP addresses for security analysis
- **User Agent Logging**: Stores browser and device information

### Admin Interface
- **Comprehensive Dashboard**: View all activity logs in a clean, organized table
- **Active Users Page**: Dedicated page showing user activity statistics and engagement metrics
- **Advanced Filtering**: Filter by activity type, user, user role, date range, and search terms
- **Pagination**: Navigate through large datasets efficiently
- **Bulk Operations**: Select and delete multiple logs at once
- **Export Functionality**: Export logs and active users to CSV format for external analysis
- **Duration Display**: View time spent on each page in a readable format
- **Statistics Overview**: Quick stats on total logs, activity types, and active users
- **Responsive Design**: Works perfectly on desktop and mobile devices

### Security & Performance
- **Nonce Protection**: All admin actions are protected with WordPress nonces
- **Capability Checks**: Only administrators can access the logs
- **Database Optimization**: Properly indexed database table for fast queries
- **Memory Efficient**: Pagination and filtering prevent memory issues
- **Bot Filtering**: Reduces log noise by excluding search engine bots

## Installation

### Method 1: Manual Installation
1. Download the plugin files
2. Upload the `wp-user-activity-logger.php` file and the `admin/` folder to your `/wp-content/plugins/wp-user-activity-logger/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. The plugin will automatically create the required database table

### Method 2: WordPress Admin
1. Go to Plugins > Add New in your WordPress admin
2. Click "Upload Plugin" and select the plugin zip file
3. Click "Install Now" and then "Activate Plugin"

## Database Structure

The plugin creates a custom table called `{prefix}_activity_log` with the following structure:

```sql
CREATE TABLE {prefix}_activity_log (
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
);
```

## Usage

### Accessing the Activity Logs
1. After activation, you'll find a new "Activity Log" menu item in your WordPress admin
2. Click on it to view the user activity dashboard
3. Use the filters at the top to narrow down the results

### Active Users Page
1. Navigate to Activity Log > Active Users in your WordPress admin
2. View comprehensive statistics about user engagement and activity patterns
3. Filter users by role, activity period (7, 30, 60, or 90 days), and search terms
4. See detailed metrics including:
   - Total activities per user
   - Number of active days
   - Total time spent on the site
   - Types of activities performed
   - First and last activity timestamps
5. Export active users data to CSV for further analysis
6. Click "View Logs" to see detailed activity logs for specific users

### Filtering Options
- **Activity Type**: Filter by login, logout, page_view, category_view, or archive_view
- **User**: Select a specific user to view their activity
- **User Role**: Filter by user role (Administrator, Editor, Author, Contributor, Subscriber, etc.)
- **Date Range**: Choose a specific date range for the logs
- **Search**: Search through activity details, page URLs, or IP addresses

### Exporting Data
1. Use the "Export CSV" button to download all current logs
2. The export will include all filtered results if filters are applied
3. The CSV file will be named with the current timestamp

### Managing Logs
- **Bulk Delete**: Select multiple logs using checkboxes and delete them
- **Clear All**: Use the "Clear All Logs" button to remove all activity data
- **Individual View**: Click on user names to view their profile

## Activity Types

The plugin tracks the following activity types:

| Activity Type | Description |
|---------------|-------------|
| `login` | User successfully logged in |
| `logout` | User logged out |
| `page_view` | User viewed a page or post (includes duration tracking) |
| `category_view` | User browsed a category page |
| `archive_view` | User viewed date, author, or tag archives |

## Duration Tracking

The plugin now includes advanced time duration tracking for page views:

### How It Works
- **Real-time Tracking**: JavaScript tracks when users enter and leave pages
- **Multiple Triggers**: Duration is updated when users:
  - Close the browser tab/window
  - Navigate to another page
  - Become inactive for 5 minutes
  - Every 30 seconds while actively browsing
- **Session Management**: Uses PHP sessions to link page views with their duration data
- **AJAX Updates**: Duration data is sent to the server via AJAX calls

### Duration Display
- **Admin Interface**: Duration is displayed in a human-readable format (e.g., "2m 30s", "1h 15m")
- **CSV Export**: Duration is included in exported data as seconds
- **Filtering**: You can filter and analyze user engagement patterns

### Technical Details
- Duration is stored in seconds in the database
- Only page views (not logins, logouts, etc.) include duration tracking
- Duration updates are sent via AJAX to avoid blocking page navigation
- The system handles edge cases like browser crashes or network issues

## Customization

### Adding Custom Activity Types
You can extend the plugin to log custom activities by calling the `log_activity()` method:

```php
// Example: Log a custom activity
global $wp_user_activity_logger;
$wp_user_activity_logger->log_activity('custom_action', 'User performed custom action', 'https://example.com/page');
```

### Hooks and Filters
The plugin provides several hooks for customization:

```php
// Filter activity before logging
add_filter('wpual_log_activity', function($activity_data) {
    // Modify activity data before saving
    return $activity_data;
});

// Action after activity is logged
add_action('wpual_activity_logged', function($activity_data) {
    // Perform actions after logging
});
```

## Configuration

### Performance Settings
- **Log Retention**: By default, logs are kept indefinitely. You can modify the deactivation hook to automatically clean old logs
- **Pagination**: Set to 50 logs per page by default. Modify the `$per_page` variable in `admin/admin-page.php` to change this

### Security Considerations
- **IP Logging**: The plugin logs IP addresses for security analysis. Consider your privacy policy
- **Data Retention**: Implement a log rotation policy to prevent database bloat
- **Access Control**: Only users with `manage_options` capability can access the logs

## Troubleshooting

### Common Issues

**Plugin not creating the database table:**
- Ensure your WordPress installation has proper database permissions
- Check that the `dbDelta()` function is available
- Verify the plugin activation hook is working

**High memory usage:**
- Reduce the number of logs per page
- Implement log rotation to delete old entries
- Consider using the export feature to archive old logs

**Missing activity logs:**
- Check if the user is a bot (plugin filters these out)
- Verify the activity is happening on the frontend (admin actions are not logged by default)
- Ensure the plugin is properly activated

### Debug Mode
Enable WordPress debug mode to see any PHP errors:

```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Changelog

### Version 1.0.0
- Initial release
- User login/logout tracking
- Page view logging with time duration tracking
- Category and archive view tracking
- Admin interface with filtering and export
- Duration display in admin dashboard
- Bot detection and filtering
- Responsive design

## Support

For support, feature requests, or bug reports, please create an issue on the plugin's GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed for WordPress user activity tracking and analytics.

---

**Note**: This plugin is designed for legitimate user activity tracking. Ensure compliance with privacy laws and regulations in your jurisdiction when collecting user data.
