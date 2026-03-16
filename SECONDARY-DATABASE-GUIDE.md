# Secondary Database Setup for College & Course Post Types

## Overview

This feature allows you to store College and Course post types in a separate database to reduce the load on your primary WordPress database. This is particularly useful when you have a large number of colleges and courses that are consuming significant database capacity.

## Features

✓ **Separate Database Storage** - College and Course data stored independently  
✓ **Automatic Table Creation** - Required WordPress tables are created automatically  
✓ **Seamless Integration** - Works transparently with existing WordPress functions  
✓ **Easy Migration** - One-click migration of existing data  
✓ **Connection Testing** - Test database connection before enabling  
✓ **Connection Monitoring** - Visual indicators of connection status  

## Requirements

- MySQL/MariaDB server with ability to create new databases
- Database user with full privileges on the secondary database
- WordPress 5.0 or higher
- PHP 7.4 or higher

## Setup Instructions

### Step 1: Create the Secondary Database

1. Log in to your MySQL server (via phpMyAdmin, MySQL Workbench, or command line)
2. Create a new database:
   ```sql
   CREATE DATABASE cpb_colleges_courses CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. Create a database user (or use existing):
   ```sql
   CREATE USER 'cpb_secondary_user'@'localhost' IDENTIFIED BY 'your_secure_password';
   ```

4. Grant privileges:
   ```sql
   GRANT ALL PRIVILEGES ON cpb_colleges_courses.* TO 'cpb_secondary_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

### Step 2: Configure in WordPress

1. Go to **CPB API Sync > Secondary Database** in WordPress admin
2. Enter the database credentials:
   - **Database Host**: `localhost` (or your database server IP)
   - **Database Name**: `cpb_colleges_courses`
   - **Database Username**: `cpb_secondary_user`
   - **Database Password**: Your database password
   - **Character Set**: `utf8mb4` (default)

3. Click **Test Connection** to verify the connection works
4. If successful, click **Save Settings**

### Step 3: Enable Secondary Database

1. Check the **Enable Secondary Database** checkbox
2. Click **Save Settings**
3. The plugin will automatically create required tables in the secondary database

### Step 4: Move Existing Data (Optional)

If you already have colleges and courses in your primary database:

1. **BACKUP YOUR PRIMARY DATABASE FIRST!**
2. Go to **CPB API Sync > Secondary Database**
3. Check the **Data Overview** section to see current counts in both databases
4. Scroll to the **Data Migration** section
5. Click **Move Data Now (Delete from Primary)**
6. Confirm the warning (data will be deleted from primary)
7. Wait for the migration to complete
8. Verify the data counts show all data moved to secondary database

**⚠️ CRITICAL**: This permanently deletes data from the primary database after copying to secondary. Always backup first!

## How It Works

### What Gets Moved

When the secondary database is enabled, the following data for College and Course post types is stored separately:

- Post content (from `wp_posts` table)
- Post metadata (from `wp_postmeta` table)
- Taxonomy relationships (from `wp_term_relationships` table)
- Terms and term meta (from `wp_terms`, `wp_term_taxonomy`, `wp_termmeta` tables)

### What Stays in Primary Database

- All other post types (posts, pages, etc.)
- User data
- WordPress core settings
- Plugin settings
- Theme settings

### Automatic Query Routing

The plugin automatically detects when a query involves Colleges or Courses and routes it to the secondary database. This happens transparently - you don't need to change any code.

## Usage

### Data Overview and Verification

The settings page includes a **Data Overview** section that shows real-time counts of colleges and courses in each database:

```
Post Type  | Primary Database | Secondary Database | Total
-----------|------------------|-------------------|------
Colleges   |        0         |        150        |  150
Courses    |        0         |       1,234       | 1,234
```

**Color Coding:**
- 🔴 **Red numbers** in primary database = data that should be migrated
- 🟢 **Green numbers** in secondary database = successfully migrated data
- **Goal**: Primary database should show 0 for both post types after migration

This table updates automatically when you visit the page, allowing you to:
- Verify migration completion
- Monitor data distribution
- Confirm before/after reverse migration

### Creating Content

Create colleges and courses as normal:
- **Colleges** → Add New
- **Courses** → Add New

All data is automatically saved to the secondary database when enabled.

### Querying Content

All WordPress functions work normally:

```php
// Get all colleges
$colleges = get_posts(array(
    'post_type' => 'college',
    'posts_per_page' => -1
));

// WP_Query works too
$query = new WP_Query(array(
    'post_type' => 'course',
    'posts_per_page' => 10
));
```

### API Access

The REST API continues to work normally:
```
GET /wp-json/wp/v2/college
GET /wp-json/wp/v2/course
```

## Connection Status

The admin interface shows the connection status:

- **✓ Connected** (Green) - Secondary database is active
- **✗ Connection Failed** (Red) - Check credentials
- **Disabled** (Blue) - Feature is turned off

### Admin Notices

When editing colleges or courses, you'll see a notice indicating you're working with the secondary database.

## Troubleshooting

### Connection Failed

**Problem**: "Connection test failed" message appears

**Solutions**:
1. Verify database exists:
   ```sql
   SHOW DATABASES LIKE 'cpb_colleges_courses';
   ```

2. Check user has privileges:
   ```sql
   SHOW GRANTS FOR 'cpb_secondary_user'@'localhost';
   ```

3. Verify host is correct (try `127.0.0.1` instead of `localhost`)

4. Check error logs:
   - WordPress: `wp-content/debug.log`
   - MySQL: Check MySQL error log

### Migration Issues

**Problem**: Migration doesn't complete

**Solutions**:
1. Check PHP execution time limits
2. Migrate in smaller batches (modify code if needed)
3. Check MySQL max_allowed_packet setting
4. Review error logs

**Problem**: Data deleted from primary but not in secondary

**Solutions**:
1. This should not happen - the code only deletes after successful copy
2. Restore from backup immediately
3. Check error logs for specific issues
4. Test connection before attempting migration again

### Performance Issues

**Problem**: Queries seem slower

**Solutions**:
1. Ensure indexes exist in secondary database
2. Check database server resources
3. Optimize tables:
   ```sql
   OPTIMIZE TABLE wp_posts, wp_postmeta;
   ```

### Tables Not Created

**Problem**: Tables don't appear in secondary database

**Solutions**:
1. Ensure user has CREATE privileges:
   ```sql
   GRANT CREATE ON cpb_colleges_courses.* TO 'cpb_secondary_user'@'localhost';
   ```

2. Check error logs for SQL errors
3. Manually create tables using WordPress table structure

## Security Considerations

### Database Credentials

Database passwords are stored in WordPress options table. Consider:

1. Use strong, unique passwords
2. Limit database user privileges to only what's needed
3. Use different credentials than your primary database
4. Consider encrypting credentials at rest

### Access Control

- Only users with `manage_options` capability can configure settings
- The secondary database should not be publicly accessible
- Use firewall rules to restrict database access

### Backups

**Important**: Back up both databases!

- Primary database: Contains WordPress core, users, settings
- Secondary database: Contains all college and course data

Include both in your backup routine.

## Advanced Configuration

### Using Remote Database

You can use a database on a different server:

1. Enter remote host IP/hostname in **Database Host**
2. Ensure firewall allows connection
3. Use appropriate user@host in MySQL grants:
   ```sql
   GRANT ALL ON cpb_colleges_courses.* TO 'cpb_user'@'your-wordpress-server-ip';
   ```

### Custom Table Prefix

The secondary database uses the same table prefix as your primary database (usually `wp_`). This is automatic and requires no configuration.

### Read/Write Splitting

For even better performance, you could configure read replicas of the secondary database, though this requires custom code modifications.

## Monitoring

### Check Connection Status

Programmatically check if secondary database is working:

```php
$db_manager = CPB_Secondary_DB_Manager::get_instance();
if ($db_manager->is_connected()) {
    echo "Secondary database is connected";
}
```

### Database Size

Monitor both databases sizes:

```sql
-- Primary database
SELECT table_schema AS "Database", 
       ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS "Size (MB)" 
FROM information_schema.TABLES 
WHERE table_schema = 'your_primary_database' 
GROUP BY table_schema;

-- Secondary database
SELECT table_schema AS "Database", 
       ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS "Size (MB)" 
FROM information_schema.TABLES 
WHERE table_schema = 'cpb_colleges_courses' 
GROUP BY table_schema;
```

## Reverting to Single Database

If you need to move data back to primary database:

1. **Backup the secondary database first!**
2. Go to **CPB API Sync > Secondary Database**
3. Check the **Data Overview** to see what's in the secondary database
4. Scroll to **Reverse Migration** section
5. Click **Move Back to Primary (Delete from Secondary)**
6. Confirm the warning dialog
7. Wait for migration to complete
8. **Disable** the secondary database feature in settings
9. Verify all data is in the primary database using the Data Overview
10. Delete the secondary database if no longer needed

**Note**: The reverse migration feature safely moves data back with the same verification as forward migration.

## Best Practices

1. ✓ Test in staging environment first
2. ✓ Back up both databases before enabling
3. ✓ Monitor performance after enabling
4. ✓ Keep both databases on same character set
5. ✓ Use reliable database hosting
6. ✓ Monitor disk space on both servers
7. ✓ Include secondary database in backup routines
8. ✓ Test disaster recovery procedures

## Support

For issues or questions:

1. Check error logs (WordPress and MySQL)
2. Review this documentation
3. Contact your database administrator if connection issues persist
4. Check WordPress support forums

## Changelog

### Version 1.0.0
- Initial release
- Secondary database support for College and Course post types
- Settings page for database configuration
- Connection testing
- Data migration tool
- Automatic table creation

## Technical Details

### Database Tables Used

- `wp_posts` - Post content
- `wp_postmeta` - Post metadata
- `wp_term_relationships` - Post-term relationships
- `wp_term_taxonomy` - Taxonomy definitions
- `wp_terms` - Terms
- `wp_termmeta` - Term metadata

### WordPress Hooks Used

- `posts_request` - Route queries to secondary database
- `posts_results` - Restore primary database connection
- `init` - Initialize database connections
- `admin_notices` - Display connection status

### Classes

- `CPB_Secondary_DB_Manager` - Manages database connections and query routing
- `CPB_Secondary_DB_Settings` - Admin settings interface

## License

This feature is part of the CPB CPT API Sync plugin.
