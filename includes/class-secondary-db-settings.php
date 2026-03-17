<?php
/**
 * Secondary Database Settings Page
 * Admin interface for managing secondary database configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class CPB_Secondary_DB_Settings {
    
    /**
     * Initialize settings page
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'), 20); // Priority 20 to ensure parent menu exists first
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_cpb_test_secondary_db', array(__CLASS__, 'handle_test_connection'));
        add_action('admin_post_cpb_migrate_to_secondary_db', array(__CLASS__, 'handle_migration'));
        add_action('admin_post_cpb_reverse_migrate_to_primary_db', array(__CLASS__, 'handle_reverse_migration'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    /**
     * Add settings page to WordPress admin menu
     */
    public static function add_settings_page() {
        // Add as submenu under CPB CPT API Sync
        add_submenu_page(
            'cpb-cpt-api-sync',
            'Secondary Database',
            'Secondary Database',
            'manage_options',
            'cpb-secondary-db',
            array(__CLASS__, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public static function register_settings() {
        // Register settings
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_enabled');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_host');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_name');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_user');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_pass');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_charset');
        register_setting('cpb_secondary_db_settings', 'cpb_secondary_db_collate');
        
        // Settings sections
        add_settings_section(
            'cpb_secondary_db_main',
            'Secondary Database Configuration',
            array(__CLASS__, 'render_section_intro'),
            'cpb-secondary-db'
        );
        
        // Settings fields
        add_settings_field(
            'cpb_secondary_db_enabled',
            'Enable Secondary Database',
            array(__CLASS__, 'render_enabled_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
        
        add_settings_field(
            'cpb_secondary_db_host',
            'Database Host',
            array(__CLASS__, 'render_host_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
        
        add_settings_field(
            'cpb_secondary_db_name',
            'Database Name',
            array(__CLASS__, 'render_name_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
        
        add_settings_field(
            'cpb_secondary_db_user',
            'Database Username',
            array(__CLASS__, 'render_user_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
        
        add_settings_field(
            'cpb_secondary_db_pass',
            'Database Password',
            array(__CLASS__, 'render_pass_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
        
        add_settings_field(
            'cpb_secondary_db_charset',
            'Database Character Set',
            array(__CLASS__, 'render_charset_field'),
            'cpb-secondary-db',
            'cpb_secondary_db_main'
        );
    }
    
    /**
     * Render section introduction
     */
    public static function render_section_intro() {
        echo '<p>Configure a separate database for College and Course post types to reduce load on your primary database.</p>';
        echo '<p><strong>Important:</strong> Make sure the secondary database exists before enabling this feature.</p>';
    }
    
    /**
     * Render enabled field
     */
    public static function render_enabled_field() {
        $enabled = get_option('cpb_secondary_db_enabled', false);
        ?>
        <label>
            <input type="checkbox" name="cpb_secondary_db_enabled" value="1" <?php checked($enabled, 1); ?>>
            Enable secondary database for Colleges and Courses
        </label>
        <p class="description">When enabled, all College and Course data will be stored in and retrieved from the secondary database.</p>
        <?php
    }
    
    /**
     * Render host field
     */
    public static function render_host_field() {
        $host = get_option('cpb_secondary_db_host', 'localhost');
        ?>
        <input type="text" name="cpb_secondary_db_host" value="<?php echo esc_attr($host); ?>" class="regular-text" placeholder="localhost">
        <p class="description">Database server hostname or IP address (e.g., localhost, 127.0.0.1, or remote host)</p>
        <?php
    }
    
    /**
     * Render name field
     */
    public static function render_name_field() {
        $name = get_option('cpb_secondary_db_name', '');
        ?>
        <input type="text" name="cpb_secondary_db_name" value="<?php echo esc_attr($name); ?>" class="regular-text" placeholder="database_name" required>
        <p class="description">The name of the secondary database</p>
        <?php
    }
    
    /**
     * Render user field
     */
    public static function render_user_field() {
        $user = get_option('cpb_secondary_db_user', '');
        ?>
        <input type="text" name="cpb_secondary_db_user" value="<?php echo esc_attr($user); ?>" class="regular-text" placeholder="db_username" required>
        <p class="description">Database username with full privileges on the secondary database</p>
        <?php
    }
    
    /**
     * Render pass field
     */
    public static function render_pass_field() {
        $pass = get_option('cpb_secondary_db_pass', '');
        ?>
        <input type="password" name="cpb_secondary_db_pass" value="<?php echo esc_attr($pass); ?>" class="regular-text" placeholder="••••••••">
        <p class="description">Database password (stored in WordPress options table)</p>
        <?php
    }
    
    /**
     * Render charset field
     */
    public static function render_charset_field() {
        $charset = get_option('cpb_secondary_db_charset', 'utf8mb4');
        ?>
        <input type="text" name="cpb_secondary_db_charset" value="<?php echo esc_attr($charset); ?>" class="regular-text" placeholder="utf8mb4">
        <p class="description">Database character set (default: utf8mb4)</p>
        <?php
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get connection status
        $db_manager = CPB_Secondary_DB_Manager::get_instance();
        $is_enabled = $db_manager->is_enabled();
        $is_connected = $db_manager->is_connected();
        
        // Get data counts
        $counts = $db_manager->get_data_counts();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('cpb_secondary_db_settings'); ?>
            
            <!-- Connection Status -->
            <div class="card">
                <h2>Connection Status</h2>
                <?php if ($is_enabled): ?>
                    <?php if ($is_connected): ?>
                        <div class="notice notice-success inline">
                            <p><strong>✓ Connected</strong> - Secondary database is active and working properly.</p>
                        </div>
                    <?php else: ?>
                        <div class="notice notice-error inline">
                            <p><strong>✗ Connection Failed</strong> - Unable to connect to the secondary database. Please check your credentials.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="notice notice-info inline">
                        <p><strong>Disabled</strong> - Secondary database is currently disabled. All data uses the primary database.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Data Count Overview -->
            <div class="card">
                <h2>Data Overview</h2>
                <p>Current distribution of College and Course data across databases:</p>
                <table class="widefat" style="max-width: 600px; margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Post Type</th>
                            <th style="text-align: center;">Primary Database</th>
                            <th style="text-align: center;">Secondary Database</th>
                            <th style="text-align: center;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Colleges</strong></td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong style="color: <?php echo $counts['primary']['colleges'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                                    <?php echo number_format($counts['primary']['colleges']); ?>
                                </strong>
                            </td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong style="color: <?php echo $counts['secondary']['colleges'] > 0 ? '#00a32a' : '#999'; ?>;">
                                    <?php echo number_format($counts['secondary']['colleges']); ?>
                                </strong>
                            </td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong><?php echo number_format($counts['primary']['colleges'] + $counts['secondary']['colleges']); ?></strong>
                            </td>
                        </tr>
                        <tr style="background: #f6f7f7;">
                            <td><strong>Courses</strong></td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong style="color: <?php echo $counts['primary']['courses'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                                    <?php echo number_format($counts['primary']['courses']); ?>
                                </strong>
                            </td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong style="color: <?php echo $counts['secondary']['courses'] > 0 ? '#00a32a' : '#999'; ?>;">
                                    <?php echo number_format($counts['secondary']['courses']); ?>
                                </strong>
                            </td>
                            <td style="text-align: center; font-size: 16px;">
                                <strong><?php echo number_format($counts['primary']['courses'] + $counts['secondary']['courses']); ?></strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top: 15px; font-size: 12px; color: #666;">
                    <strong>Goal:</strong> Primary database should show <span style="color: #00a32a;">0</span> for colleges and courses when fully migrated.
                </p>
            </div>
            
            <!-- Settings Form -->
            <form method="post" action="options.php" id="cpb-secondary-db-form">
                <?php
                settings_fields('cpb_secondary_db_settings');
                do_settings_sections('cpb-secondary-db');
                submit_button('Save Settings');
                ?>
            </form>
            
            <!-- Test Connection -->
            <div class="card">
                <h2>Test Connection</h2>
                <p>Test the connection to the secondary database using the credentials above.</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                    <input type="hidden" name="action" value="cpb_test_secondary_db">
                    <?php wp_nonce_field('cpb_test_secondary_db'); ?>
                    <button type="submit" class="button">Test Connection</button>
                </form>
            </div>
            
            <!-- Data Migration -->
            <div class="card">
                <h2>Data Migration</h2>
                <p><strong>Move</strong> existing College and Course data from the primary database to the secondary database.</p>
                <p><strong>⚠️ IMPORTANT:</strong> This will <strong>permanently delete</strong> the data from your primary database after copying it to the secondary database. Make sure you have a complete backup before proceeding!</p>
                <div class="notice notice-warning inline">
                    <p><strong>What happens during migration:</strong></p>
                    <ol style="margin: 10px 0 0 20px;">
                        <li>Copy all colleges and courses to the secondary database</li>
                        <li>Verify the data was copied successfully</li>
                        <li><strong>Delete the data from the primary database</strong></li>
                    </ol>
                </div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline; margin-top: 15px;" onsubmit="return confirm('⚠️ WARNING: This will MOVE all College and Course data to the secondary database and DELETE them from the primary database.\n\nDo you have a backup?\n\nAre you sure you want to continue?');">
                    <input type="hidden" name="action" value="cpb_migrate_to_secondary_db">
                    <?php wp_nonce_field('cpb_migrate_to_secondary_db'); ?>
                    <button type="submit" class="button button-primary" <?php echo !$is_connected ? 'disabled' : ''; ?>>
                        Move Data Now (Delete from Primary)
                    </button>
                </form>
            </div>
            
            <!-- Reverse Migration -->
            <div class="card">
                <h2>Reverse Migration (Secondary → Primary)</h2>
                <p><strong>Move data back</strong> from the secondary database to the primary database.</p>
                <p><strong>⚠️ IMPORTANT:</strong> This will <strong>permanently delete</strong> the data from your secondary database after copying it back to the primary database. Use this if you want to revert to using a single database.</p>
                <div class="notice notice-warning inline">
                    <p><strong>What happens during reverse migration:</strong></p>
                    <ol style="margin: 10px 0 0 20px;">
                        <li>Copy all colleges and courses back to the primary database</li>
                        <li>Verify the data was copied successfully</li>
                        <li><strong>Delete the data from the secondary database</strong></li>
                    </ol>
                </div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline; margin-top: 15px;" onsubmit="return confirm('⚠️ WARNING: This will MOVE all College and Course data BACK to the primary database and DELETE them from the secondary database.\n\nDo you have a backup?\n\nAre you sure you want to continue?');">
                    <input type="hidden" name="action" value="cpb_reverse_migrate_to_primary_db">
                    <?php wp_nonce_field('cpb_reverse_migrate_to_primary_db'); ?>
                    <button type="submit" class="button button-secondary" <?php echo !$is_connected || $counts['secondary']['colleges'] == 0 && $counts['secondary']['courses'] == 0 ? 'disabled' : ''; ?>>
                        Move Back to Primary (Delete from Secondary)
                    </button>
                </form>
                <?php if ($counts['secondary']['colleges'] == 0 && $counts['secondary']['courses'] == 0): ?>
                    <p style="margin-top: 10px; color: #666; font-style: italic;">No data in secondary database to migrate.</p>
                <?php endif; ?>
            </div>
            
            <!-- Information Box -->
            <div class="card">
                <h2>How It Works</h2>
                <ul>
                    <li><strong>Post Types Affected:</strong> College and Course post types</li>
                    <li><strong>Data Stored:</strong> Posts, post meta, taxonomy relationships, and terms</li>
                    <li><strong>Automatic Setup:</strong> Required tables are automatically created in the secondary database</li>
                    <li><strong>Transparent Operation:</strong> All WordPress functions work normally - the database switch is handled automatically</li>
                    <li><strong>Performance:</strong> Reduces load on your primary database, especially beneficial for large datasets</li>
                    <li><strong>Data Movement:</strong> Migration permanently moves data from primary to secondary (not just a copy)</li>
                </ul>
                
                <h3>Setup Steps</h3>
                <ol>
                    <li>Create a new empty database on your MySQL server</li>
                    <li>Create a database user with full privileges on that database</li>
                    <li>Enter the credentials in the form above</li>
                    <li>Test the connection to ensure it works</li>
                    <li>Save settings (tables will be created automatically)</li>
                    <li>Enable the secondary database</li>
                    <li><strong>Backup your primary database first!</strong></li>
                    <li>Migrate existing data (moves from primary to secondary)</li>
                </ol>
            </div>
        </div>
        
        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .card h2 {
                margin-top: 0;
                font-size: 1.3em;
            }
            .card h3 {
                margin-top: 20px;
                font-size: 1.1em;
            }
            .notice.inline {
                margin: 10px 0;
                padding: 10px 15px;
            }
            .widefat th {
                font-weight: 600;
            }
            .widefat td, .widefat th {
                padding: 12px 10px;
            }
        </style>
        <?php
    }
    
    /**
     * Handle test connection request
     */
    public static function handle_test_connection() {
        check_admin_referer('cpb_test_secondary_db');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $credentials = array(
            'host' => get_option('cpb_secondary_db_host', ''),
            'name' => get_option('cpb_secondary_db_name', ''),
            'user' => get_option('cpb_secondary_db_user', ''),
            'pass' => get_option('cpb_secondary_db_pass', ''),
        );
        
        $db_manager = CPB_Secondary_DB_Manager::get_instance();
        $result = $db_manager->test_connection($credentials);
        
        if (!empty($result['success'])) {
            add_settings_error(
                'cpb_secondary_db_settings',
                'connection_success',
                'Connection test successful! The secondary database is accessible.',
                'success'
            );
        } else {
            $error_detail = !empty($result['error']) ? ' Error: ' . $result['error'] : '';
            add_settings_error(
                'cpb_secondary_db_settings',
                'connection_failed',
                'Connection test failed! Please check your credentials and ensure the database exists.' . $error_detail,
                'error'
            );
        }
        
        set_transient('settings_errors', get_settings_errors(), 30);
        
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
    
    /**
     * Handle data migration request
     */
    public static function handle_migration() {
        check_admin_referer('cpb_migrate_to_secondary_db');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $db_manager = CPB_Secondary_DB_Manager::get_instance();
        $result = $db_manager->migrate_data_to_secondary();
        
        if ($result['success']) {
            add_settings_error(
                'cpb_secondary_db_settings',
                'migration_success',
                $result['message'],
                'success'
            );
        } else {
            add_settings_error(
                'cpb_secondary_db_settings',
                'migration_failed',
                'Migration failed: ' . $result['message'],
                'error'
            );
        }
        
        set_transient('settings_errors', get_settings_errors(), 30);
        
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
    
    /**
     * Handle reverse migration request (secondary to primary)
     */
    public static function handle_reverse_migration() {
        check_admin_referer('cpb_reverse_migrate_to_primary_db');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $db_manager = CPB_Secondary_DB_Manager::get_instance();
        $result = $db_manager->reverse_migrate_to_primary();
        
        if ($result['success']) {
            add_settings_error(
                'cpb_secondary_db_settings',
                'reverse_migration_success',
                $result['message'],
                'success'
            );
        } else {
            add_settings_error(
                'cpb_secondary_db_settings',
                'reverse_migration_failed',
                'Reverse migration failed: ' . $result['message'],
                'error'
            );
        }
        
        set_transient('settings_errors', get_settings_errors(), 30);
        
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
    
    /**
     * Enqueue scripts for settings page
     */
    public static function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_cpb-secondary-db') {
            return;
        }
        
        // Add any custom JS/CSS here if needed
    }
}

// Initialize settings page
CPB_Secondary_DB_Settings::init();
