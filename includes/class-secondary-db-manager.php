<?php
/**
 * Secondary Database Manager for Colleges and Courses
 * Handles connection and query routing to a separate database
 */

if (!defined('ABSPATH')) {
    exit;
}

class CPB_Secondary_DB_Manager {
    
    private static $instance = null;
    private $secondary_db = null;
    private $is_connected = false;
    private $post_types = array('college', 'course');
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Connect to secondary database if configured
        add_action('init', array($this, 'maybe_connect_secondary_db'), 1);
        
        // Switch database early for admin post operations
        add_action('admin_init', array($this, 'maybe_switch_for_admin_post_action'), 1);
        
        // Filter queries for college and course post types
        add_filter('posts_request', array($this, 'route_query_to_secondary_db'), 10, 2);
        add_filter('posts_results', array($this, 'restore_primary_db'), 10, 2);
        
        // Hook into pre_get_posts to handle admin queries and counts
        add_action('pre_get_posts', array($this, 'switch_db_for_query'), 1);

        // Keep frontend college/course rendering on the secondary database so
        // templates, post meta, thumbnails, and related queries read a
        // consistent dataset.
        add_action('wp', array($this, 'lock_secondary_db_for_frontend'), 1);
        
        // Handle post count queries for admin screens
        add_filter('wp_count_posts', array($this, 'override_post_counts'), 10, 3);
        
        // Handle post creation and updates - BEFORE they happen
        add_filter('wp_insert_post_data', array($this, 'switch_db_before_insert'), 1, 2);
        add_action('wp_insert_post', array($this, 'handle_post_insert'), 1, 3);
        
        // Handle post saves
        add_action('save_post', array($this, 'switch_db_for_save'), 1, 3);
        add_action('save_post', array($this, 'restore_db_after_save'), 999, 3);
        
        // Handle individual post operations (get_post, wp_delete_post, wp_trash_post, etc.)
        add_action('wp_trash_post', array($this, 'switch_db_for_trash'), 1);
        add_action('untrash_post', array($this, 'switch_db_for_trash'), 1);
        add_action('delete_post', array($this, 'switch_db_for_delete'), 1);
        add_action('before_delete_post', array($this, 'switch_db_for_delete'), 1);
        
        // Restore after operations complete (these ensure cleanup)
        add_action('trashed_post', array($this, 'restore_db_after_operation'), 999);
        add_action('untrashed_post', array($this, 'restore_db_after_operation'), 999);
        add_action('deleted_post', array($this, 'restore_db_after_operation'), 999);
        
        // NOTE: Do NOT add restore on meta hooks (added/updated/deleted_post_meta)
        // or the_posts - these fire unpredictably and would interrupt admin actions
        
        // Handle post retrieval
        add_filter('the_posts', array($this, 'maybe_restore_after_query'), 999, 2);
        
        // Intercept individual post lookups by ID
        add_filter('get_post', array($this, 'check_secondary_db_for_post'), 10, 2);
        
        // Admin notices for connection status
        add_action('admin_notices', array($this, 'display_connection_notices'));
    }
    
    /**
     * Check if secondary database is enabled
     */
    public function is_enabled() {
        return get_option('cpb_secondary_db_enabled', false);
    }
    
    /**
     * Get secondary database credentials
     */
    private function get_credentials() {
        return array(
            'host' => get_option('cpb_secondary_db_host', ''),
            'name' => get_option('cpb_secondary_db_name', ''),
            'user' => get_option('cpb_secondary_db_user', ''),
            'pass' => get_option('cpb_secondary_db_pass', ''),
            'charset' => get_option('cpb_secondary_db_charset', 'utf8mb4'),
            'collate' => get_option('cpb_secondary_db_collate', ''),
        );
    }
    
    /**
     * Connect to secondary database
     */
    public function maybe_connect_secondary_db() {
        if (!$this->is_enabled()) {
            return;
        }
        
        $creds = $this->get_credentials();
        
        // Validate credentials
        if (empty($creds['host']) || empty($creds['name']) || empty($creds['user'])) {
            return;
        }
        
        try {
            $this->secondary_db = new wpdb(
                $creds['user'],
                $creds['pass'],
                $creds['name'],
                $creds['host']
            );
            
            // Only call set_charset if the connection actually succeeded.
            // On PHP 8+, passing a null $dbh to set_charset() throws a TypeError
            // which crashes the page silently (TypeError extends Error, not Exception).
            if (!empty($this->secondary_db->dbh)) {
                $this->secondary_db->set_charset($this->secondary_db->dbh, $creds['charset'], $creds['collate']);
            }
            
            // Set table prefix (same as primary for consistency)
            $this->secondary_db->set_prefix($GLOBALS['wpdb']->prefix);
            
            // Test connection
            $result = $this->secondary_db->query("SELECT 1");
            
            if ($result !== false) {
                $this->is_connected = true;
                
                // Ensure tables exist in secondary database
                $this->ensure_tables_exist();
            } else {
                // Log the actual MySQL error to help diagnose connection issues
                $db_error = !empty($this->secondary_db->last_error) ? $this->secondary_db->last_error : 'Unknown connection error';
                error_log('CPB Secondary DB Connection Error: ' . $db_error);
                $GLOBALS['cpb_secondary_db_error'] = $db_error;
            }
        } catch (\Throwable $e) {
            // Catches both Exception and Error (TypeError, etc.) — PHP 8 compatible
            error_log('CPB Secondary DB Connection Error: ' . $e->getMessage());
            $GLOBALS['cpb_secondary_db_error'] = $e->getMessage();
            $this->is_connected = false;
        }
    }
    
    /**
     * Switch database early for admin post operations (trash, edit, delete)
     * Also preloads WP object cache for bulk operations.
     * Runs before WordPress checks if post exists.
     */
    public function maybe_switch_for_admin_post_action() {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        if (!is_admin()) {
            return;
        }
        
        global $pagenow;
        
        // ── Single-post operations via post.php ──────────────────────────────
        if ($pagenow === 'post.php') {
            $post_id = 0;
            if (isset($_GET['post'])) {
                $post_id = intval($_GET['post']);
            } elseif (isset($_POST['post_ID'])) {
                $post_id = intval($_POST['post_ID']);
            }
            
            if (!$post_id) {
                return; // new post creation – skip
            }
            
            $post_type = $this->secondary_db->get_var($this->secondary_db->prepare(
                "SELECT post_type FROM {$this->secondary_db->posts} WHERE ID = %d",
                $post_id
            ));
            
            if ($post_type && in_array($post_type, $this->post_types)) {
                $this->switch_to_secondary_db();
                $GLOBALS['cpb_admin_action_lock'] = true;
                add_action('shutdown', array($this, 'restore_to_primary_db'), 1);
            }
            return;
        }
        
        // ── Bulk operations via edit.php ──────────────────────────────────────
        if ($pagenow === 'edit.php') {
            // Determine which bulk action is selected
            $action  = isset($_REQUEST['action'])  ? $_REQUEST['action']  : '-1';
            $action2 = isset($_REQUEST['action2']) ? $_REQUEST['action2'] : '-1';
            $bulk_action = ($action !== '-1') ? $action : $action2;
            
            if (!in_array($bulk_action, array('trash', 'untrash', 'delete'))) {
                return;
            }
            
            // Collect all post IDs being acted on
            $post_ids = array();
            if (!empty($_REQUEST['post'])) {
                $post_ids = array_map('intval', (array) $_REQUEST['post']);
            } elseif (!empty($_REQUEST['ids'])) {
                // "Undo" links use ids=  (comma-separated or single)
                $post_ids = array_map('intval', explode(',', $_REQUEST['ids']));
            }
            
            $post_ids = array_filter($post_ids);
            if (empty($post_ids)) {
                return;
            }
            
            // Preload secondary-DB posts into WP object cache so that
            // get_post() succeeds even before $wpdb is switched.
            $found_secondary = false;
            foreach ($post_ids as $post_id) {
                // Skip if already cached
                if (false !== wp_cache_get($post_id, 'posts')) {
                    continue;
                }
                
                $post_data = $this->secondary_db->get_row($this->secondary_db->prepare(
                    "SELECT * FROM {$this->secondary_db->posts} WHERE ID = %d",
                    $post_id
                ));
                
                if ($post_data && in_array($post_data->post_type, $this->post_types)) {
                    $post_data = sanitize_post($post_data, 'raw');
                    wp_cache_add($post_data->ID, $post_data, 'posts');
                    $found_secondary = true;
                }
            }
            
            // Also switch the global $wpdb so all bulk operations (meta reads,
            // updates, deletes) use the secondary database.
            if ($found_secondary) {
                $this->switch_to_secondary_db();
                $GLOBALS['cpb_admin_action_lock'] = true;
                // Track how many secondary posts remain so restore_db_after_operation
                // waits until ALL posts in the bulk action are processed before
                // restoring primary DB instead of doing so after each individual post.
                $GLOBALS['cpb_bulk_remaining'] = count(array_filter($post_ids));
                add_action('shutdown', array($this, 'restore_to_primary_db'), 1);
            }
        }
    }
    
    /**
     * Ensure necessary tables exist in secondary database
     */
    private function ensure_tables_exist() {
        if (!$this->is_connected) {
            return false;
        }
        
        global $wpdb;
        
        // Get table schema from primary database
        $tables_to_copy = array(
            'posts',
            'postmeta',
            'term_relationships',
            'term_taxonomy',
            'terms',
            'termmeta',
            'options',
            'comments',
            'commentmeta',
        );
        
        foreach ($tables_to_copy as $table) {
            $primary_table = $wpdb->prefix . $table;
            $secondary_table = $this->secondary_db->prefix . $table;
            
            // Check if table exists in secondary database
            $table_exists = $this->secondary_db->get_var(
                $this->secondary_db->prepare(
                    "SHOW TABLES LIKE %s",
                    $secondary_table
                )
            );
            
            if (!$table_exists) {
                // Get CREATE TABLE statement from primary database
                $create_stmt = $wpdb->get_var("SHOW CREATE TABLE `{$primary_table}`", 1);
                
                if ($create_stmt) {
                    // Execute on secondary database
                    $this->secondary_db->query($create_stmt);
                }
            }
        }
        
        return true;
    }
    
    /**
     * Check if query involves college or course post types
     */
    private function is_cpt_query($query) {
        if (!is_object($query)) {
            return false;
        }
        
        $post_type = $query->get('post_type');
        
        if (empty($post_type)) {
            return false;
        }
        
        // Handle array or string
        $post_types = is_array($post_type) ? $post_type : array($post_type);
        
        // Check if any of our CPTs are involved
        foreach ($post_types as $pt) {
            if (in_array($pt, $this->post_types)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Route queries to secondary database
     */
    public function route_query_to_secondary_db($request, $query) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return $request;
        }
        
        // Check if this is a college or course query
        if ($this->is_cpt_query($query)) {
            global $wpdb;

            if (!is_admin() && method_exists($query, 'is_main_query') && $query->is_main_query()) {
                $GLOBALS['cpb_frontend_render_lock'] = true;
            }
            
            // Backup primary database connection
            $query->cpb_primary_db = $wpdb;
            
            // Switch to secondary database
            $wpdb = $this->secondary_db;
        }
        
        return $request;
    }
    
    /**
     * Restore primary database connection after query
     */
    public function restore_primary_db($posts, $query) {
        // Restore primary database if we switched
        if (isset($query->cpb_primary_db)) {
            if (!empty($GLOBALS['cpb_frontend_render_lock']) && !is_admin() && method_exists($query, 'is_main_query') && $query->is_main_query()) {
                return $posts;
            }

            global $wpdb;
            $wpdb = $query->cpb_primary_db;
            unset($query->cpb_primary_db);
        }
        
        return $posts;
    }
    
    /**
     * Switch database for WP_Query operations (handles admin counts and lists)
     */
    public function switch_db_for_query($query) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        // Check if this query is for our CPTs
        $post_type = $query->get('post_type');
        
        if (empty($post_type)) {
            // Check if we're on a CPT admin screen
            global $pagenow;
            if ($pagenow === 'edit.php' && isset($_GET['post_type']) && in_array($_GET['post_type'], $this->post_types)) {
                $post_type = $_GET['post_type'];
                $query->set('post_type', $post_type);
            }
        }
        
        // Switch database if it's our CPT
        if (!empty($post_type)) {
            $post_types = is_array($post_type) ? $post_type : array($post_type);
            foreach ($post_types as $pt) {
                if (in_array($pt, $this->post_types)) {
                    $this->switch_to_secondary_db();
                    break;
                }
            }
        }
    }
    
    /**
     * Override post counts for college and course post types
     * This ensures the admin screen shows correct counts from secondary database
     */
    public function override_post_counts($counts, $type, $perm) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return $counts;
        }
        
        // Only override for our post types
        if (!in_array($type, $this->post_types)) {
            return $counts;
        }
        
        // Switch to secondary database to get accurate counts
        global $wpdb;
        $original_wpdb = $wpdb;
        $wpdb = $this->secondary_db;
        
        // Get counts from secondary database
        $query = "SELECT post_status, COUNT(*) AS num_posts FROM {$this->secondary_db->posts} WHERE post_type = %s";
        
        if ('readable' === $perm && is_user_logged_in()) {
            $post_type_object = get_post_type_object($type);
            if (!current_user_can($post_type_object->cap->read_private_posts)) {
                $query .= " AND (post_status != 'private' OR post_author = %d) GROUP BY post_status";
                $results = $this->secondary_db->get_results(
                    $this->secondary_db->prepare($query, $type, get_current_user_id()),
                    ARRAY_A
                );
            } else {
                $query .= " GROUP BY post_status";
                $results = $this->secondary_db->get_results(
                    $this->secondary_db->prepare($query, $type),
                    ARRAY_A
                );
            }
        } else {
            $query .= " GROUP BY post_status";
            $results = $this->secondary_db->get_results(
                $this->secondary_db->prepare($query, $type),
                ARRAY_A
            );
        }
        
        // Restore primary database
        $wpdb = $original_wpdb;
        
        // Build counts object
        $counts = array_fill_keys(get_post_stati(), 0);
        
        foreach ($results as $row) {
            $counts[$row['post_status']] = $row['num_posts'];
        }
        
        return (object) $counts;
    }
    
    /**
     * Switch database before post insert
     * This filter is called before wp_insert_post saves the post
     */
    public function switch_db_before_insert($data, $postarr) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return $data;
        }
        
        // Check post type
        $post_type = isset($data['post_type']) ? $data['post_type'] : '';
        
        // Also check from the $postarr array
        if (empty($post_type) && isset($postarr['post_type'])) {
            $post_type = $postarr['post_type'];
        }
        
        // If updating existing post, get post type from database
        if (empty($post_type) && !empty($postarr['ID'])) {
            $post_type = $this->get_post_type_from_any_db($postarr['ID']);
        }
        
        if (in_array($post_type, $this->post_types)) {
            $this->switch_to_secondary_db();
        }
        
        return $data;
    }
    
    /**
     * Handle post insert/update
     */
    public function handle_post_insert($post_id, $post, $update) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        // Check if this is our post type
        if (in_array($post->post_type, $this->post_types)) {
            // Keep secondary DB active for subsequent operations (like meta saves)
            // Will be restored after all save operations complete
        }
    }
    
    /**
     * Switch database for save operations
     */
    public function switch_db_for_save($post_id, $post, $update) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        // Check if this is our post type
        $post_type = is_object($post) ? $post->post_type : get_post_type($post_id);
        
        if (in_array($post_type, $this->post_types)) {
            $this->switch_to_secondary_db();
        }
    }
    
    /**
     * Restore database after save operations
     */
    public function restore_db_after_save($post_id, $post, $update) {
        // Keep the secondary DB active for the full admin save request so
        // late-running meta box handlers also write to the secondary tables.
        if (isset($GLOBALS['cpb_admin_action_lock']) || isset($GLOBALS['cpb_frontend_render_lock'])) {
            return;
        }

        $this->restore_to_primary_db();
    }
    
    /**
     * Switch database for delete operations
     */
    public function switch_db_for_delete($post_id) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        // Get post type - try both databases
        $post_type = $this->get_post_type_from_any_db($post_id);
        
        if (in_array($post_type, $this->post_types)) {
            $this->switch_to_secondary_db();
        }
    }
    
    /**
     * Get post type checking both databases
     */
    private function get_post_type_from_any_db($post_id) {
        global $wpdb;
        
        // First check current database
        $post_type = get_post_type($post_id);
        
        if ($post_type) {
            return $post_type;
        }
        
        // If not found and secondary is connected, check secondary database
        if ($this->is_connected) {
            $original_wpdb = $wpdb;
            $wpdb = $this->secondary_db;
            
            $post_type = $this->secondary_db->get_var($this->secondary_db->prepare(
                "SELECT post_type FROM {$this->secondary_db->posts} WHERE ID = %d",
                $post_id
            ));
            
            $wpdb = $original_wpdb;
            
            if ($post_type) {
                return $post_type;
            }
        }
        
        // Check from GET/POST parameters as fallback
        if (isset($_GET['post_type'])) {
            return $_GET['post_type'];
        }
        
        if (isset($_POST['post_type'])) {
            return $_POST['post_type'];
        }
        
        return false;
    }
    
    /**     * Check secondary database when get_post() returns null
     * This catches individual post lookups (like trash verification)
     */
    public function check_secondary_db_for_post($post, $output) {
        // If post was found in primary, return it
        if ($post !== null) {
            return $post;
        }
        
        // Don't process if not connected
        if (!$this->is_connected || !$this->is_enabled()) {
            return $post;
        }
        
        // Try to get post ID from context
        $post_id = null;
        if (isset($_GET['post'])) {
            $post_id = intval($_GET['post']);
        } elseif (isset($_POST['post_ID'])) {
            $post_id = intval($_POST['post_ID']);
        }
        
        if (!$post_id) {
            return $post;
        }
        
        // Check if post exists in secondary database
        global $wpdb;
        $original_wpdb = $wpdb;
        $wpdb = $this->secondary_db;
        
        $post_data = $this->secondary_db->get_row($this->secondary_db->prepare(
            "SELECT * FROM {$this->secondary_db->posts} WHERE ID = %d",
            $post_id
        ));
        
        $wpdb = $original_wpdb;
        
        // If found and it's one of our post types, return it
        if ($post_data && in_array($post_data->post_type, $this->post_types)) {
            // Convert to WP_Post object
            return new WP_Post($post_data);
        }
        
        return $post;
    }
    
    /**     * Switch database for trash operations
     */
    public function switch_db_for_trash($post_id) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        // Get post type checking both databases
        $post_type = $this->get_post_type_from_any_db($post_id);
        
        if (in_array($post_type, $this->post_types)) {
            $this->switch_to_secondary_db();
        }
    }
    
    /**
     * Maybe switch database for metadata operations
     */
    public function maybe_switch_for_meta($check, $object_id, $meta_key, $single) {
        if (!$this->is_connected || !$this->is_enabled()) {
            return $check;
        }
        
        // Check if this is for our post types
        $post_type = get_post_type($object_id);
        
        if ($post_type && in_array($post_type, $this->post_types)) {
            $this->switch_to_secondary_db();
        }
        
        return $check;
    }
    
    /**
     * Restore database after select query (only if not locked by an admin action)
     */
    public function maybe_restore_after_query($posts, $query = null) {
        // Don't restore if DB is locked for an admin action
        if (!isset($GLOBALS['cpb_admin_action_lock']) && !isset($GLOBALS['cpb_frontend_render_lock'])) {
            $this->restore_to_primary_db();
        }
        return $posts;
    }

    /**
     * Keep the frontend request on secondary DB for college/course views.
     */
    public function lock_secondary_db_for_frontend() {
        if (!$this->is_connected || !$this->is_enabled() || is_admin()) {
            return;
        }

        if (is_singular($this->post_types) || is_post_type_archive($this->post_types)) {
            $this->switch_to_secondary_db();
            $GLOBALS['cpb_frontend_render_lock'] = true;
            add_action('shutdown', array($this, 'restore_to_primary_db'), 1);
        }
    }
    
    /**
     * Restore database after operations (trash/delete/untrash complete)
     */
    public function restore_db_after_operation($post_id_or_posts = null, $post_or_query = null) {
        // In bulk operations, decrement the remaining counter and only restore
        // after ALL posts in the batch have been processed.
        if (isset($GLOBALS['cpb_bulk_remaining'])) {
            $GLOBALS['cpb_bulk_remaining']--;
            if ($GLOBALS['cpb_bulk_remaining'] > 0) {
                // More posts still to process – keep DB switched and lock active.
                return $post_id_or_posts;
            }
            unset($GLOBALS['cpb_bulk_remaining']);
        }

        // Clear admin action lock when the operation is done
        unset($GLOBALS['cpb_admin_action_lock']);
        $this->restore_to_primary_db();
        
        // Return first parameter if it's for a filter
        return $post_id_or_posts;
    }
    
    /**
     * Switch global $wpdb to secondary database
     */
    public function switch_to_secondary_db() {
        if (!$this->is_connected || !$this->is_enabled()) {
            return;
        }
        
        global $wpdb;
        
        // Only switch if not already switched
        if (!isset($GLOBALS['cpb_original_wpdb'])) {
            $GLOBALS['cpb_original_wpdb'] = $wpdb;
            $wpdb = $this->secondary_db;
        }
    }
    
    /**
     * Restore global $wpdb to primary database
     */
    public function restore_to_primary_db() {
        if (isset($GLOBALS['cpb_original_wpdb'])) {
            global $wpdb;
            $wpdb = $GLOBALS['cpb_original_wpdb'];
            unset($GLOBALS['cpb_original_wpdb']);
        }

        unset($GLOBALS['cpb_frontend_render_lock']);
    }
    
    /**
     * Display admin notices about connection status
     */
    public function display_connection_notices() {
        if (!$this->is_enabled()) {
            return;
        }
        
        $screen = get_current_screen();
        
        // Show only on college/course edit screens and settings page
        if (!$screen || !in_array($screen->post_type, $this->post_types) && 
            $screen->id !== 'settings_page_cpb-secondary-db') {
            return;
        }
        
        if (!$this->is_connected) {
            $extra = !empty($GLOBALS['cpb_secondary_db_error']) ? ' <em>(' . esc_html($GLOBALS['cpb_secondary_db_error']) . ')</em>' : '';
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Secondary Database Connection Failed:</strong> ';
            echo 'Unable to connect to the secondary database. College and Course data will use the primary database.' . $extra;
            echo '</p></div>';
        } else {
            if (in_array($screen->post_type, $this->post_types)) {
                echo '<div class="notice notice-info"><p>';
                echo '<strong>Secondary Database Active:</strong> ';
                echo 'You are currently working with data stored in the secondary database.';
                echo '</p></div>';
            }
        }
    }
    
    /**
     * Test secondary database connection
     */
    public function test_connection($credentials) {
        try {
            $test_db = new wpdb(
                $credentials['user'],
                $credentials['pass'],
                $credentials['name'],
                $credentials['host']
            );
            
            // Guard against PHP 8 TypeError when dbh is null (failed connection)
            if (!empty($test_db->dbh)) {
                $test_db->set_charset($test_db->dbh, 'utf8mb4', '');
            }
            
            $result = $test_db->query("SELECT 1");
            
            if ($result === false) {
                $error = !empty($test_db->last_error) ? $test_db->last_error : 'Could not connect to database';
                return array('success' => false, 'error' => $error);
            }
            return array('success' => true, 'error' => '');
        } catch (\Throwable $e) {
            // Catches both Exception and Error (TypeError, etc.) — PHP 8 compatible
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Get secondary database object
     */
    public function get_secondary_db() {
        return $this->secondary_db;
    }
    
    /**
     * Check if connected
     */
    public function is_connected() {
        return $this->is_connected;
    }
    
    /**
     * Migrate existing data from primary to secondary database
     * Moves data (copy to secondary, then delete from primary)
     */
    public function migrate_data_to_secondary() {
        if (!$this->is_connected) {
            return array('success' => false, 'message' => 'Secondary database not connected');
        }
        
        global $wpdb;
        
        $migrated = array('colleges' => 0, 'courses' => 0);
        $deleted = array('colleges' => 0, 'courses' => 0);
        $errors = array();
        
        foreach ($this->post_types as $post_type) {
            // Get all posts of this type from primary database
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            ));
            
            foreach ($posts as $post) {
                // Check if post already exists in secondary database
                $exists = $this->secondary_db->get_var($this->secondary_db->prepare(
                    "SELECT ID FROM {$this->secondary_db->posts} WHERE ID = %d",
                    $post->ID
                ));
                
                if (!$exists) {
                    // Insert post into secondary database
                    $post_inserted = $this->secondary_db->insert(
                        $this->secondary_db->posts,
                        (array) $post
                    );
                    
                    if (!$post_inserted) {
                        $errors[] = "Failed to insert post ID {$post->ID} into secondary database";
                        continue; // Skip to next post
                    }
                    
                    // Copy post meta
                    $meta = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d",
                        $post->ID
                    ));
                    
                    $meta_success = true;
                    foreach ($meta as $meta_row) {
                        $result = $this->secondary_db->insert(
                            $this->secondary_db->postmeta,
                            (array) $meta_row
                        );
                        if (!$result) {
                            $meta_success = false;
                            $errors[] = "Failed to insert postmeta for post ID {$post->ID}";
                        }
                    }
                    
                    // Copy term relationships
                    $terms = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->term_relationships} WHERE object_id = %d",
                        $post->ID
                    ));
                    
                    $terms_success = true;
                    foreach ($terms as $term_row) {
                        $result = $this->secondary_db->insert(
                            $this->secondary_db->term_relationships,
                            (array) $term_row
                        );
                        if (!$result) {
                            $terms_success = false;
                            $errors[] = "Failed to insert term relationship for post ID {$post->ID}";
                        }
                    }
                    
                    // Only delete from primary if everything was successfully copied
                    if ($post_inserted && $meta_success && $terms_success) {
                        // Delete term relationships first (foreign key dependency)
                        $wpdb->delete(
                            $wpdb->term_relationships,
                            array('object_id' => $post->ID),
                            array('%d')
                        );
                        
                        // Delete post meta
                        $wpdb->delete(
                            $wpdb->postmeta,
                            array('post_id' => $post->ID),
                            array('%d')
                        );
                        
                        // Delete the post itself
                        $wpdb->delete(
                            $wpdb->posts,
                            array('ID' => $post->ID),
                            array('%d')
                        );
                        
                        $migrated[$post_type === 'college' ? 'colleges' : 'courses']++;
                        $deleted[$post_type === 'college' ? 'colleges' : 'courses']++;
                    } else {
                        $errors[] = "Skipped deletion of post ID {$post->ID} due to copy errors";
                    }
                } else {
                    // Post already exists in secondary, safe to delete from primary
                    $wpdb->delete(
                        $wpdb->term_relationships,
                        array('object_id' => $post->ID),
                        array('%d')
                    );
                    
                    $wpdb->delete(
                        $wpdb->postmeta,
                        array('post_id' => $post->ID),
                        array('%d')
                    );
                    
                    $wpdb->delete(
                        $wpdb->posts,
                        array('ID' => $post->ID),
                        array('%d')
                    );
                    
                    $deleted[$post_type === 'college' ? 'colleges' : 'courses']++;
                }
            }
        }
        
        $message = sprintf(
            'Successfully moved %d colleges and %d courses to secondary database (deleted from primary)',
            $migrated['colleges'] + $deleted['colleges'],
            $migrated['courses'] + $deleted['courses']
        );
        
        if (!empty($errors)) {
            $message .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= sprintf(' (and %d more errors)', count($errors) - 5);
            }
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'stats' => array(
                'migrated' => $migrated,
                'deleted' => $deleted,
                'errors' => count($errors)
            )
        );
    }
    
    /**
     * Get counts of posts in both databases
     */
    public function get_data_counts() {
        global $wpdb;
        
        $counts = array(
            'primary' => array('colleges' => 0, 'courses' => 0),
            'secondary' => array('colleges' => 0, 'courses' => 0)
        );
        
        // Count in primary database
        foreach ($this->post_types as $post_type) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            ));
            $key = $post_type === 'college' ? 'colleges' : 'courses';
            $counts['primary'][$key] = (int) $count;
        }
        
        // Count in secondary database (if connected)
        if ($this->is_connected) {
            foreach ($this->post_types as $post_type) {
                $count = $this->secondary_db->get_var($this->secondary_db->prepare(
                    "SELECT COUNT(*) FROM {$this->secondary_db->posts} WHERE post_type = %s",
                    $post_type
                ));
                $key = $post_type === 'college' ? 'colleges' : 'courses';
                $counts['secondary'][$key] = (int) $count;
            }
        }
        
        return $counts;
    }
    
    /**
     * Reverse migrate: Move data from secondary back to primary database
     */
    public function reverse_migrate_to_primary() {
        if (!$this->is_connected) {
            return array('success' => false, 'message' => 'Secondary database not connected');
        }
        
        global $wpdb;
        
        $migrated = array('colleges' => 0, 'courses' => 0);
        $deleted = array('colleges' => 0, 'courses' => 0);
        $errors = array();
        
        foreach ($this->post_types as $post_type) {
            // Get all posts of this type from SECONDARY database
            $posts = $this->secondary_db->get_results($this->secondary_db->prepare(
                "SELECT * FROM {$this->secondary_db->posts} WHERE post_type = %s",
                $post_type
            ));
            
            foreach ($posts as $post) {
                // Check if post already exists in primary database
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID = %d",
                    $post->ID
                ));
                
                if (!$exists) {
                    // Insert post into primary database
                    $post_inserted = $wpdb->insert(
                        $wpdb->posts,
                        (array) $post
                    );
                    
                    if (!$post_inserted) {
                        $errors[] = "Failed to insert post ID {$post->ID} into primary database";
                        continue;
                    }
                    
                    // Copy post meta
                    $meta = $this->secondary_db->get_results($this->secondary_db->prepare(
                        "SELECT * FROM {$this->secondary_db->postmeta} WHERE post_id = %d",
                        $post->ID
                    ));
                    
                    $meta_success = true;
                    foreach ($meta as $meta_row) {
                        $result = $wpdb->insert(
                            $wpdb->postmeta,
                            (array) $meta_row
                        );
                        if (!$result) {
                            $meta_success = false;
                            $errors[] = "Failed to insert postmeta for post ID {$post->ID}";
                        }
                    }
                    
                    // Copy term relationships
                    $terms = $this->secondary_db->get_results($this->secondary_db->prepare(
                        "SELECT * FROM {$this->secondary_db->term_relationships} WHERE object_id = %d",
                        $post->ID
                    ));
                    
                    $terms_success = true;
                    foreach ($terms as $term_row) {
                        $result = $wpdb->insert(
                            $wpdb->term_relationships,
                            (array) $term_row
                        );
                        if (!$result) {
                            $terms_success = false;
                            $errors[] = "Failed to insert term relationship for post ID {$post->ID}";
                        }
                    }
                    
                    // Only delete from secondary if everything was successfully copied
                    if ($post_inserted && $meta_success && $terms_success) {
                        // Delete from secondary database
                        $this->secondary_db->delete(
                            $this->secondary_db->term_relationships,
                            array('object_id' => $post->ID),
                            array('%d')
                        );
                        
                        $this->secondary_db->delete(
                            $this->secondary_db->postmeta,
                            array('post_id' => $post->ID),
                            array('%d')
                        );
                        
                        $this->secondary_db->delete(
                            $this->secondary_db->posts,
                            array('ID' => $post->ID),
                            array('%d')
                        );
                        
                        $migrated[$post_type === 'college' ? 'colleges' : 'courses']++;
                        $deleted[$post_type === 'college' ? 'colleges' : 'courses']++;
                    } else {
                        $errors[] = "Skipped deletion of post ID {$post->ID} from secondary due to copy errors";
                    }
                } else {
                    // Post already exists in primary, safe to delete from secondary
                    $this->secondary_db->delete(
                        $this->secondary_db->term_relationships,
                        array('object_id' => $post->ID),
                        array('%d')
                    );
                    
                    $this->secondary_db->delete(
                        $this->secondary_db->postmeta,
                        array('post_id' => $post->ID),
                        array('%d')
                    );
                    
                    $this->secondary_db->delete(
                        $this->secondary_db->posts,
                        array('ID' => $post->ID),
                        array('%d')
                    );
                    
                    $deleted[$post_type === 'college' ? 'colleges' : 'courses']++;
                }
            }
        }
        
        $message = sprintf(
            'Successfully moved %d colleges and %d courses back to primary database (deleted from secondary)',
            $migrated['colleges'] + $deleted['colleges'],
            $migrated['courses'] + $deleted['courses']
        );
        
        if (!empty($errors)) {
            $message .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= sprintf(' (and %d more errors)', count($errors) - 5);
            }
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'stats' => array(
                'migrated' => $migrated,
                'deleted' => $deleted,
                'errors' => count($errors)
            )
        );
    }
}

// Initialize the manager — only when mysqli is available (safe for PHP 8.3+)
if (extension_loaded('mysqli') && !function_exists('cpb_init_secondary_db_manager')) {
    function cpb_init_secondary_db_manager() {
        return CPB_Secondary_DB_Manager::get_instance();
    }
    add_action('plugins_loaded', 'cpb_init_secondary_db_manager');
}
