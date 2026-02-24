<?php
/**
 * Plugin Name: CPB CPT API Sync
 * Description: REST helpers to manage Colleges, Courses, and Exams via API with meta + relationship sync.
 * Version: 1.0.0
 * Author: CPB
 */

if (!defined('ABSPATH')) {
    exit;
}

class CPB_CPT_API_Sync {
    const ALLOWED_TYPES = array('college', 'course', 'exam', 'stream');

    const ATTACHMENT_META_KEYS = array(
        'college' => array('_college_logo', '_college_gallery'),
    );

    const META_MAP = array(
        'college' => array(
            '_college_logo' => array('type' => 'integer', 'single' => true),
            '_college_website_url' => array('type' => 'string', 'single' => true),
            '_college_established_year' => array('type' => 'integer', 'single' => true),
            '_college_gallery' => array('type' => 'array', 'single' => true, 'items' => 'integer'),
            '_college_fee_min' => array('type' => 'string', 'single' => true),
            '_college_fee_max' => array('type' => 'string', 'single' => true),
            '_college_location' => array('type' => 'string', 'single' => true),
            '_college_pincode' => array('type' => 'string', 'single' => true),
            '_college_state' => array('type' => 'string', 'single' => true),
            '_college_country' => array('type' => 'string', 'single' => true),
            '_college_address_line' => array('type' => 'string', 'single' => true),
            '_college_fees_info' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_admission_info' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_placement_info' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_description' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_boys_hostel' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_girls_hostel' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_medical_hospital' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_gym' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_library' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_sports' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_it_infrastructure' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_cafeteria' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_auditorium' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_transport_facility' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_alumni_associations' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_wifi' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_laboratories' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_guest_room' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_facility_training_placement_cell' => array('type' => 'string', 'html' => true, 'single' => true),
            '_college_is_university' => array('type' => 'string', 'single' => true),
            '_college_university_departments' => array('type' => 'array', 'single' => true, 'items' => 'object'),
            '_linked_courses' => array('type' => 'array', 'single' => true, 'items' => 'integer'),
        ),
        'course' => array(
            '_linked_colleges' => array('type' => 'array', 'single' => true, 'items' => 'integer'),
        ),
        'exam' => array(
            '_linked_streams' => array('type' => 'array', 'single' => true, 'items' => 'integer'),
        ),
        'stream' => array(
            '_linked_exams' => array('type' => 'array', 'single' => true, 'items' => 'integer'),
        ),
    );

    public static function init() {
        add_action('init', array(__CLASS__, 'register_meta'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'register_docs_page'));
        add_filter('wp_is_application_passwords_available', array(__CLASS__, 'allow_app_passwords_on_local'));
    }

    public static function allow_app_passwords_on_local($available) {
        return true;
    }

    public static function register_meta() {
        foreach (self::META_MAP as $post_type => $meta_keys) {
            foreach ($meta_keys as $meta_key => $schema) {
                register_post_meta(
                    $post_type,
                    $meta_key,
                    array(
                        'type' => $schema['type'],
                        'single' => $schema['single'],
                        'show_in_rest' => array(
                            'schema' => self::build_rest_schema($schema),
                        ),
                        'sanitize_callback' => function($value) use ($schema) {
                            return CPB_CPT_API_Sync::sanitize_meta_value($value, $schema);
                        },
                        'auth_callback' => function() {
                            return current_user_can('edit_posts');
                        },
                    )
                );
            }
        }
    }

    private static function build_rest_schema($schema) {
        if ($schema['type'] === 'array') {
            $items_type = isset($schema['items']) ? $schema['items'] : 'string';
            
            if ($items_type === 'object') {
                return array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                    ),
                );
            }
            
            return array(
                'type' => 'array',
                'items' => array(
                    'type' => $items_type,
                ),
            );
        }

        return array(
            'type' => $schema['type'],
        );
    }

    public static function register_routes() {
        register_rest_route('cpb/v1', '/sync/export', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_export'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'args' => array(
                'types' => array('type' => 'string', 'required' => false),
                'status' => array('type' => 'string', 'required' => false),
                'page' => array('type' => 'integer', 'required' => false),
                'per_page' => array('type' => 'integer', 'required' => false),
                'since' => array('type' => 'string', 'required' => false),
            ),
        ));

        register_rest_route('cpb/v1', '/sync/import', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_import'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

        public static function register_docs_page() {
                add_submenu_page(
                        'tools.php',
                        'CPB CPT API Sync Docs',
                        'CPB CPT API Sync',
                        'manage_options',
                        'cpb-cpt-api-sync-docs',
                        array(__CLASS__, 'render_docs_page')
                );
        }

        public static function render_docs_page() {
                if (!current_user_can('manage_options')) {
                        return;
                }

                $site_url = esc_url(site_url());
            $plugin_url = plugin_dir_url(__FILE__);
            $json_template_url = esc_url($plugin_url . 'templates/cpb-playwright-templates.json');
            $js_helper_url = esc_url($plugin_url . 'templates/cpb-playwright-helper.js');
            $quickstart_url = esc_url($plugin_url . 'templates/PLAYWRIGHT-QUICK-START.md');
                ?>
                <div class="wrap">
                        <h1>CPB CPT API Sync Documentation</h1>
                        <p>This plugin exposes REST helpers for Colleges, Courses, Exams, and Streams. Use Application Passwords for auth.</p>
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;">
                    <h3 style="margin-top: 0;">📥 Download Resources</h3>
                    <p style="margin-bottom: 15px;">Get everything you need to start importing colleges with Playwright:</p>
                    <p>
                        <a class="button button-primary" href="<?php echo $json_template_url; ?>" download>📄 Download JSON Templates</a>
                        <a class="button button-primary" href="<?php echo $js_helper_url; ?>" download>⚙️ Download Playwright Helper</a>
                        <a class="button button-primary" href="<?php echo $quickstart_url; ?>" download>🚀 Download Quick Start Guide</a>
                    </p>
                    <p style="font-size: 13px; color: #646970; margin: 10px 0 0 0;">
                        <strong>New!</strong> All college CPT fields now supported including facilities, admission info, placement info, and university departments.
                    </p>
                </div>

                        <h2>Authentication</h2>
                        <p><strong>Header:</strong> <code>Authorization: Basic base64(username:application_password)</code></p>

                        <h2>Export</h2>
                        <p><code>GET <?php echo $site_url; ?>/wp-json/cpb/v1/sync/export?types=college,course,exam,stream</code></p>
                        <h3>Export Response</h3>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "types": ["college","course","exam","stream"],
    "page": 1,
    "per_page": 100,
    "items": { "college": [], "course": [], "exam": [], "stream": [] },
    "totals": { "college": 0, "course": 0, "exam": 0, "stream": 0 }
}</pre>

                        <h2>Import (Upsert)</h2>
                        <p><code>POST <?php echo $site_url; ?>/wp-json/cpb/v1/sync/import</code></p>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "college",
            "slug": "abc-college",
            "title": "ABC College",
            "content": "&lt;p&gt;...&lt;/p&gt;",
            "status": "publish",
            "featured_media_url": "https://example.com/wp-content/uploads/hero.jpg",
            "media": {
                "_college_logo": { "url": "https://example.com/wp-content/uploads/logo.png" },
                "_college_gallery": [
                    { "url": "https://example.com/wp-content/uploads/g1.jpg" },
                    { "url": "https://example.com/wp-content/uploads/g2.jpg" }
                ]
            },
            "relations": {
                "linked_courses": [
                    { "slug": "btech", "title": "B.Tech", "status": "publish" },
                    { "slug": "mba", "title": "MBA", "status": "publish" }
                ]
            },
            "relations_create_missing": true
        }
    ]
}</pre>
                        <h3>Import Response</h3>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "created": 0,
    "updated": 0,
    "deleted": 0,
    "skipped": 0,
    "errors": [],
    "items": [
        { "action": "created|updated|deleted", "post_type": "college|course|exam|stream", "slug": "...", "id": 123 }
    ]
}</pre>

                        <h2>College Request Template (Complete)</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "college",
            "slug": "abc-college",
            "title": "ABC College",
            "content": "&lt;p&gt;Full college description...&lt;/p&gt;",
            "excerpt": "Short summary of the college",
            "status": "publish",
            "featured_media_url": "https://example.com/wp-content/uploads/hero.jpg",
            "media": {
                "_college_logo": { "url": "https://example.com/wp-content/uploads/logo.png" },
                "_college_gallery": [
                    { "url": "https://example.com/wp-content/uploads/g1.jpg" },
                    { "url": "https://example.com/wp-content/uploads/g2.jpg" }
                ]
            },
            "meta": {
                "_college_website_url": "https://abc.edu",
                "_college_established_year": 1998,
                "_college_fee_min": "100000",
                "_college_fee_max": "250000",
                "_college_location": "Mumbai",
                "_college_pincode": "400001",
                "_college_state": "Maharashtra",
                "_college_country": "India",
                "_college_address_line": "123 Main Street, Andheri",
                "_college_fees_info": "&lt;p&gt;Detailed fee structure...&lt;/p&gt;",
                "_college_admission_info": "&lt;p&gt;Admission process details...&lt;/p&gt;",
                "_college_placement_info": "&lt;p&gt;Placement statistics...&lt;/p&gt;",
                "_college_facility_description": "&lt;p&gt;Overview of all facilities...&lt;/p&gt;",
                "_college_facility_boys_hostel": "&lt;p&gt;Boys hostel details...&lt;/p&gt;",
                "_college_facility_girls_hostel": "&lt;p&gt;Girls hostel details...&lt;/p&gt;",
                "_college_facility_medical_hospital": "&lt;p&gt;Medical facility details...&lt;/p&gt;",
                "_college_facility_gym": "&lt;p&gt;Gym facility details...&lt;/p&gt;",
                "_college_facility_library": "&lt;p&gt;Library details...&lt;/p&gt;",
                "_college_facility_sports": "&lt;p&gt;Sports facilities...&lt;/p&gt;",
                "_college_facility_it_infrastructure": "&lt;p&gt;IT infrastructure...&lt;/p&gt;",
                "_college_facility_cafeteria": "&lt;p&gt;Cafeteria details...&lt;/p&gt;",
                "_college_facility_auditorium": "&lt;p&gt;Auditorium details...&lt;/p&gt;",
                "_college_facility_transport_facility": "&lt;p&gt;Transport details...&lt;/p&gt;",
                "_college_facility_alumni_associations": "&lt;p&gt;Alumni network...&lt;/p&gt;",
                "_college_facility_wifi": "&lt;p&gt;WiFi availability...&lt;/p&gt;",
                "_college_facility_laboratories": "&lt;p&gt;Lab facilities...&lt;/p&gt;",
                "_college_facility_guest_room": "&lt;p&gt;Guest room details...&lt;/p&gt;",
                "_college_facility_training_placement_cell": "&lt;p&gt;Training & placement...&lt;/p&gt;",
                "_college_is_university": "1",
                "_college_university_departments": [
                    {"name": "College of Engineering", "ownership": "Private", "courses": 15},
                    {"name": "College of Arts", "ownership": "Government", "courses": 8}
                ]
            },
            "terms": {
                "college_stream": ["engineering","medical"]
            },
            "relations": {
                "linked_courses": [
                    { "slug": "btech", "title": "B.Tech", "status": "publish" },
                    { "slug": "mba", "title": "MBA", "status": "publish" }
                ]
            },
            "relations_create_missing": true
        }
    ]
}</pre>
                        <p><strong>Note:</strong> All facility fields are optional. Only include facilities that are available at the college. HTML content is supported in text fields.</p>

                        <h2>College Request Template (Minimal - Required Fields Only)</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "college",
            "slug": "abc-college",
            "title": "ABC College",
            "content": "&lt;p&gt;Full description of the college...&lt;/p&gt;",
            "status": "publish",
            "meta": {
                "_college_website_url": "https://abc.edu",
                "_college_location": "Mumbai",
                "_college_state": "Maharashtra",
                "_college_country": "India"
            }
        }
    ]
}</pre>
                        <p><strong>Minimal Template Usage:</strong> Use this template when you only have basic information. You can add more fields from the complete template as needed.</p>

                        <h2>Available College Meta Fields Reference</h2>
                        <div style="background:#f9f9f9; padding:15px; border:1px solid #ddd; margin-bottom:20px;">
                            <h4 style="margin-top:0;">Basic Information</h4>
                            <ul style="margin:5px 0; column-count:2; column-gap:20px;">
                                <li><code>_college_website_url</code> (string)</li>
                                <li><code>_college_established_year</code> (integer)</li>
                            </ul>
                            
                            <h4>Location Details</h4>
                            <ul style="margin:5px 0; column-count:2; column-gap:20px;">
                                <li><code>_college_location</code> (string) - City/District</li>
                                <li><code>_college_pincode</code> (string)</li>
                                <li><code>_college_state</code> (string)</li>
                                <li><code>_college_country</code> (string)</li>
                                <li><code>_college_address_line</code> (string)</li>
                            </ul>
                            
                            <h4>Fee Information</h4>
                            <ul style="margin:5px 0;">
                                <li><code>_college_fee_min</code> (string) - Minimum fee in ₹</li>
                                <li><code>_college_fee_max</code> (string) - Maximum fee in ₹</li>
                                <li><code>_college_fees_info</code> (HTML string) - Detailed fee structure</li>
                            </ul>
                            
                            <h4>Additional Information (HTML Content)</h4>
                            <ul style="margin:5px 0;">
                                <li><code>_college_admission_info</code> (HTML string)</li>
                                <li><code>_college_placement_info</code> (HTML string)</li>
                            </ul>
                            
                            <h4>Facilities (All HTML Content)</h4>
                            <ul style="margin:5px 0; column-count:2; column-gap:20px; font-size:12px;">
                                <li><code>_college_facility_description</code></li>
                                <li><code>_college_facility_boys_hostel</code></li>
                                <li><code>_college_facility_girls_hostel</code></li>
                                <li><code>_college_facility_medical_hospital</code></li>
                                <li><code>_college_facility_gym</code></li>
                                <li><code>_college_facility_library</code></li>
                                <li><code>_college_facility_sports</code></li>
                                <li><code>_college_facility_it_infrastructure</code></li>
                                <li><code>_college_facility_cafeteria</code></li>
                                <li><code>_college_facility_auditorium</code></li>
                                <li><code>_college_facility_transport_facility</code></li>
                                <li><code>_college_facility_alumni_associations</code></li>
                                <li><code>_college_facility_wifi</code></li>
                                <li><code>_college_facility_laboratories</code></li>
                                <li><code>_college_facility_guest_room</code></li>
                                <li><code>_college_facility_training_placement_cell</code></li>
                            </ul>
                            
                            <h4>University Information</h4>
                            <ul style="margin:5px 0;">
                                <li><code>_college_is_university</code> (string: "1" or empty)</li>
                                <li><code>_college_university_departments</code> (array of objects)
                                    <br><small>Each object: {"name": "...", "ownership": "Private|Government|Semi Government", "courses": 0}</small>
                                </li>
                            </ul>
                            
                            <h4>Media</h4>
                            <ul style="margin:5px 0;">
                                <li><code>featured_media_url</code> - Main hero image</li>
                                <li><code>media._college_logo</code> - College logo (in media object)</li>
                                <li><code>media._college_gallery</code> - Array of gallery images (in media object)</li>
                            </ul>
                        </div>

                        <h2>Course Request Template</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "course",
            "slug": "btech",
            "title": "B.Tech",
            "content": "&lt;p&gt;...&lt;/p&gt;",
            "excerpt": "Short summary",
            "status": "publish",
            "meta": {},
            "relations": {
                "linked_colleges": [
                    { "slug": "abc-college", "title": "ABC College", "status": "publish" }
                ]
            },
            "relations_create_missing": true
        }
    ]
}</pre>

                        <h2>Exam Request Template</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "exam",
            "slug": "jee-main",
            "title": "JEE Main",
            "content": "&lt;p&gt;...&lt;/p&gt;",
            "excerpt": "Short summary",
            "status": "publish",
            "meta": {},
            "relations": {
                "linked_streams": [
                    { "slug": "engineering", "title": "Engineering", "status": "publish" }
                ]
            },
            "relations_create_missing": true
        }
    ]
}</pre>

                        <h2>Stream Request Template</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "stream",
            "slug": "engineering",
            "title": "Engineering",
            "content": "&lt;p&gt;...&lt;/p&gt;",
            "excerpt": "Short summary",
            "status": "publish",
            "meta": {}
        }
    ]
}</pre>

                        <h2>Relations</h2>
                        <ul>
                            <li><code>college</code> → <code>linked_courses</code> (course slugs or objects)</li>
                            <li><code>course</code> → <code>linked_colleges</code> (college slugs or objects)</li>
                            <li><code>exam</code> → <code>linked_streams</code> (stream slugs or objects)</li>
                        </ul>
                        <p>Set <code>relations_create_missing: true</code> to auto-create missing related posts, otherwise only existing posts will be linked.</p>

                        <h2>Delete</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        { "post_type": "course", "slug": "obsolete-course", "delete": true }
    ]
}</pre>
                </div>
                <?php
        }

    public static function can_manage() {
        return current_user_can('edit_posts');
    }

    private static function get_allowed_types_from_request($request) {
        $types_param = $request->get_param('types');
        if ($types_param) {
            $types = array_filter(array_map('trim', explode(',', $types_param)));
        } else {
            $types = array('college', 'course', 'exam');
        }

        return array_values(array_intersect(self::ALLOWED_TYPES, $types));
    }

    public static function handle_export(WP_REST_Request $request) {
        $types = self::get_allowed_types_from_request($request);
        if (empty($types)) {
            return new WP_Error('cpb_no_types', 'No valid post types provided.', array('status' => 400));
        }

        $status = $request->get_param('status') ? sanitize_key($request->get_param('status')) : 'publish';
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page = min(200, max(1, (int) ($request->get_param('per_page') ?: 100)));
        $since = $request->get_param('since');

        $response = array(
            'types' => $types,
            'page' => $page,
            'per_page' => $per_page,
            'items' => array(),
            'totals' => array(),
        );

        foreach ($types as $type) {
            $query_args = array(
                'post_type' => $type,
                'post_status' => $status,
                'posts_per_page' => $per_page,
                'paged' => $page,
                'orderby' => 'modified',
                'order' => 'DESC',
            );

            if ($since) {
                $query_args['date_query'] = array(
                    array(
                        'column' => 'post_modified_gmt',
                        'after' => $since,
                        'inclusive' => false,
                    ),
                );
            }

            $query = new WP_Query($query_args);
            $items = array();

            foreach ($query->posts as $post) {
                $items[] = self::build_export_item($post);
            }

            $response['items'][$type] = $items;
            $response['totals'][$type] = (int) $query->found_posts;
        }

        return rest_ensure_response($response);
    }

    private static function build_export_item($post) {
        $post_type = $post->post_type;
        $meta = array();

        if (isset(self::META_MAP[$post_type])) {
            foreach (self::META_MAP[$post_type] as $meta_key => $schema) {
                $meta[$meta_key] = get_post_meta($post->ID, $meta_key, true);
            }
        }

        $item = array(
            'post_type' => $post_type,
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'date_gmt' => get_post_time('c', true, $post),
            'modified_gmt' => get_post_modified_time('c', true, $post),
            'featured_media' => (int) get_post_thumbnail_id($post->ID),
            'featured_media_url' => '',
            'meta' => $meta,
            'media' => array(),
            'terms' => array(),
        );

        if (!empty($item['featured_media'])) {
            $item['featured_media_url'] = wp_get_attachment_url($item['featured_media']);
        }

        if (isset(self::ATTACHMENT_META_KEYS[$post_type])) {
            foreach (self::ATTACHMENT_META_KEYS[$post_type] as $attachment_key) {
                $value = get_post_meta($post->ID, $attachment_key, true);
                if ($attachment_key === '_college_gallery' && is_array($value)) {
                    $gallery = array();
                    foreach ($value as $attachment_id) {
                        $gallery[] = array(
                            'id' => (int) $attachment_id,
                            'url' => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
                        );
                    }
                    $item['media'][$attachment_key] = $gallery;
                } else {
                    $item['media'][$attachment_key] = array(
                        'id' => (int) $value,
                        'url' => $value ? wp_get_attachment_url($value) : '',
                    );
                }
            }
        }

        if ($post_type === 'college') {
            $terms = wp_get_object_terms($post->ID, 'college_stream', array('fields' => 'slugs'));
            if (!is_wp_error($terms)) {
                $item['terms']['college_stream'] = $terms;
            }
        }

        return $item;
    }

    public static function handle_import(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('cpb_invalid_payload', 'Invalid JSON payload.', array('status' => 400));
        }

        $dry_run = !empty($payload['dry_run']);
        $items = self::normalize_import_items($payload);

        if (empty($items)) {
            return new WP_Error('cpb_no_items', 'No items provided for import.', array('status' => 400));
        }

        $results = array(
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => array(),
            'items' => array(),
        );

        foreach ($items as $item) {
            $result = self::import_single_item($item, $dry_run);

            if (is_wp_error($result)) {
                $results['errors'][] = $result->get_error_message();
                $results['skipped']++;
                continue;
            }

            if (!empty($result['action'])) {
                $results[$result['action']]++;
            }

            $results['items'][] = $result;
        }

        return rest_ensure_response($results);
    }

    private static function normalize_import_items($payload) {
        $items = array();

        if (!empty($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (!empty($payload['types']) && is_array($payload['types'])) {
            foreach ($payload['types'] as $post_type => $type_items) {
                if (!is_array($type_items)) {
                    continue;
                }
                foreach ($type_items as $item) {
                    if (is_array($item)) {
                        $item['post_type'] = $post_type;
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    private static function import_single_item($item, $dry_run) {
        $post_type = isset($item['post_type']) ? sanitize_key($item['post_type']) : '';
        if (!in_array($post_type, self::ALLOWED_TYPES, true)) {
            return new WP_Error('cpb_invalid_type', 'Invalid or unsupported post type.');
        }

        $slug = isset($item['slug']) ? sanitize_title($item['slug']) : '';
        $title = isset($item['title']) ? sanitize_text_field($item['title']) : '';
        $content = isset($item['content']) ? wp_kses_post($item['content']) : '';
        $excerpt = isset($item['excerpt']) ? wp_kses_post($item['excerpt']) : '';
        $status = isset($item['status']) ? sanitize_key($item['status']) : 'publish';

        if (empty($slug) && !empty($title)) {
            $slug = sanitize_title($title);
        }

        if (empty($slug)) {
            return new WP_Error('cpb_missing_slug', 'Item is missing a slug or title.');
        }

        $existing = get_page_by_path($slug, OBJECT, $post_type);
        $action = $existing ? 'updated' : 'created';
        $post_id = $existing ? $existing->ID : 0;

        if (!empty($item['delete'])) {
            if ($existing && !$dry_run) {
                self::detach_relationships_on_delete($existing->ID, $post_type);
                wp_delete_post($existing->ID, true);
                return array('action' => 'deleted', 'post_type' => $post_type, 'slug' => $slug, 'id' => $existing->ID);
            }
            return array('action' => 'deleted', 'post_type' => $post_type, 'slug' => $slug, 'id' => $post_id);
        }

        if (!$dry_run) {
            $post_data = array(
                'ID' => $post_id,
                'post_type' => $post_type,
                'post_name' => $slug,
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => $status,
            );

            $saved_id = $existing ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
            if (is_wp_error($saved_id)) {
                return $saved_id;
            }

            $post_id = $saved_id;

            self::apply_meta($post_type, $post_id, isset($item['meta']) ? $item['meta'] : array());
            self::apply_media($post_type, $post_id, $item);
            self::apply_terms($post_type, $post_id, isset($item['terms']) ? $item['terms'] : array());
            self::apply_relations(
                $post_type,
                $post_id,
                isset($item['relations']) ? $item['relations'] : array(),
                !empty($item['relations_create_missing'])
            );
        }

        return array(
            'action' => $action,
            'post_type' => $post_type,
            'slug' => $slug,
            'id' => $post_id,
        );
    }

    private static function apply_meta($post_type, $post_id, $meta) {
        if (!is_array($meta) || empty(self::META_MAP[$post_type])) {
            return;
        }

        foreach (self::META_MAP[$post_type] as $meta_key => $schema) {
            if (!array_key_exists($meta_key, $meta)) {
                continue;
            }
            if (self::is_attachment_meta_key($post_type, $meta_key)) {
                $handled = self::handle_attachment_meta($post_id, $meta_key, $meta[$meta_key]);
                if ($handled !== null) {
                    update_post_meta($post_id, $meta_key, $handled);
                }
                continue;
            }

            $sanitized = self::sanitize_meta_value($meta[$meta_key], $schema);
            update_post_meta($post_id, $meta_key, $sanitized);
        }
    }

    private static function apply_media($post_type, $post_id, $item) {
        $media = isset($item['media']) && is_array($item['media']) ? $item['media'] : array();
        
        if (!empty($item['featured_media_url'])) {
            $featured_id = self::sideload_media($item['featured_media_url'], $post_id);
            if ($featured_id) {
                set_post_thumbnail($post_id, $featured_id);
            }
        } elseif (!empty($item['featured_media'])) {
            set_post_thumbnail($post_id, absint($item['featured_media']));
        } elseif (!empty($media['featured_media_url'])) {
            $featured_id = self::sideload_media($media['featured_media_url'], $post_id);
            if ($featured_id) {
                set_post_thumbnail($post_id, $featured_id);
            }
        }

        if (empty(self::ATTACHMENT_META_KEYS[$post_type])) {
            return;
        }

        foreach (self::ATTACHMENT_META_KEYS[$post_type] as $meta_key) {
            if (!array_key_exists($meta_key, $media)) {
                continue;
            }

            $handled = self::handle_attachment_meta($post_id, $meta_key, $media[$meta_key]);
            if ($handled !== null) {
                update_post_meta($post_id, $meta_key, $handled);
            }
        }
    }

    private static function apply_terms($post_type, $post_id, $terms) {
        if ($post_type !== 'college' || empty($terms['college_stream']) || !is_array($terms['college_stream'])) {
            return;
        }

        $term_ids = array();
        foreach ($terms['college_stream'] as $term_value) {
            $term_value = sanitize_text_field($term_value);
            if (empty($term_value)) {
                continue;
            }

            $term = get_term_by('slug', sanitize_title($term_value), 'college_stream');
            if (!$term) {
                $term = get_term_by('name', $term_value, 'college_stream');
            }
            if (!$term) {
                $created = wp_insert_term($term_value, 'college_stream');
                if (!is_wp_error($created)) {
                    $term_ids[] = (int) $created['term_id'];
                }
                continue;
            }
            $term_ids[] = (int) $term->term_id;
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'college_stream', false);
        }
    }

    private static function apply_relations($post_type, $post_id, $relations, $create_missing) {
        if (!is_array($relations)) {
            return;
        }

        if ($post_type === 'college') {
            $items = array();
            if (!empty($relations['linked_courses'])) {
                $items = $relations['linked_courses'];
            } elseif (!empty($relations['linked_courses_slugs'])) {
                $items = $relations['linked_courses_slugs'];
            }

            if (!empty($items)) {
                $course_ids = self::resolve_related_items($items, 'course', $create_missing);
                self::sync_college_courses($post_id, $course_ids);
            }
        }

        if ($post_type === 'course') {
            $items = array();
            if (!empty($relations['linked_colleges'])) {
                $items = $relations['linked_colleges'];
            } elseif (!empty($relations['linked_colleges_slugs'])) {
                $items = $relations['linked_colleges_slugs'];
            }

            if (!empty($items)) {
                $college_ids = self::resolve_related_items($items, 'college', $create_missing);
                self::sync_course_colleges($post_id, $college_ids);
            }
        }

        if ($post_type === 'exam') {
            $items = array();
            if (!empty($relations['linked_streams'])) {
                $items = $relations['linked_streams'];
            } elseif (!empty($relations['linked_streams_slugs'])) {
                $items = $relations['linked_streams_slugs'];
            }

            if (!empty($items)) {
                $stream_ids = self::resolve_related_items($items, 'stream', $create_missing);
                self::sync_exam_streams($post_id, $stream_ids);
            }
        }
    }

    private static function resolve_related_items($items, $post_type, $create_missing) {
        $ids = array();

        if (!is_array($items)) {
            return $ids;
        }

        foreach ($items as $item) {
            $slug = '';
            $title = '';
            $status = 'publish';
            $content = '';
            $excerpt = '';

            if (is_string($item)) {
                $slug = sanitize_title($item);
            } elseif (is_array($item)) {
                $slug = !empty($item['slug']) ? sanitize_title($item['slug']) : '';
                $title = !empty($item['title']) ? sanitize_text_field($item['title']) : '';
                $status = !empty($item['status']) ? sanitize_key($item['status']) : 'publish';
                $content = !empty($item['content']) ? wp_kses_post($item['content']) : '';
                $excerpt = !empty($item['excerpt']) ? wp_kses_post($item['excerpt']) : '';
            }

            if (!$slug && $title) {
                $slug = sanitize_title($title);
            }

            if (!$slug) {
                continue;
            }

            $post = get_page_by_path($slug, OBJECT, $post_type);
            if ($post) {
                $ids[] = (int) $post->ID;
                continue;
            }

            if ($create_missing) {
                $created_id = wp_insert_post(
                    array(
                        'post_type' => $post_type,
                        'post_name' => $slug,
                        'post_title' => $title ? $title : $slug,
                        'post_content' => $content,
                        'post_excerpt' => $excerpt,
                        'post_status' => $status,
                    ),
                    true
                );

                if (!is_wp_error($created_id) && $created_id) {
                    $ids[] = (int) $created_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function detach_relationships_on_delete($post_id, $post_type) {
        if ($post_type === 'college') {
            $linked_courses = get_post_meta($post_id, '_linked_courses', true);
            $linked_courses = is_array($linked_courses) ? $linked_courses : array();
            foreach ($linked_courses as $course_id) {
                $course_colleges = get_post_meta($course_id, '_linked_colleges', true);
                $course_colleges = is_array($course_colleges) ? $course_colleges : array();
                $course_colleges = array_values(array_diff($course_colleges, array($post_id)));
                update_post_meta($course_id, '_linked_colleges', $course_colleges);
            }
            delete_post_meta($post_id, '_linked_courses');
            return;
        }

        if ($post_type === 'course') {
            $linked_colleges = get_post_meta($post_id, '_linked_colleges', true);
            $linked_colleges = is_array($linked_colleges) ? $linked_colleges : array();
            foreach ($linked_colleges as $college_id) {
                $college_courses = get_post_meta($college_id, '_linked_courses', true);
                $college_courses = is_array($college_courses) ? $college_courses : array();
                $college_courses = array_values(array_diff($college_courses, array($post_id)));
                update_post_meta($college_id, '_linked_courses', $college_courses);
            }
            delete_post_meta($post_id, '_linked_colleges');
            return;
        }

        if ($post_type === 'exam') {
            $linked_streams = get_post_meta($post_id, '_linked_streams', true);
            $linked_streams = is_array($linked_streams) ? $linked_streams : array();
            foreach ($linked_streams as $stream_id) {
                $stream_exams = get_post_meta($stream_id, '_linked_exams', true);
                $stream_exams = is_array($stream_exams) ? $stream_exams : array();
                $stream_exams = array_values(array_diff($stream_exams, array($post_id)));
                update_post_meta($stream_id, '_linked_exams', $stream_exams);
            }
            delete_post_meta($post_id, '_linked_streams');
            return;
        }

        if ($post_type === 'stream') {
            $linked_exams = get_post_meta($post_id, '_linked_exams', true);
            $linked_exams = is_array($linked_exams) ? $linked_exams : array();
            foreach ($linked_exams as $exam_id) {
                $exam_streams = get_post_meta($exam_id, '_linked_streams', true);
                $exam_streams = is_array($exam_streams) ? $exam_streams : array();
                $exam_streams = array_values(array_diff($exam_streams, array($post_id)));
                update_post_meta($exam_id, '_linked_streams', $exam_streams);
            }
            delete_post_meta($post_id, '_linked_exams');
        }
    }

    private static function is_attachment_meta_key($post_type, $meta_key) {
        return !empty(self::ATTACHMENT_META_KEYS[$post_type]) && in_array($meta_key, self::ATTACHMENT_META_KEYS[$post_type], true);
    }

    private static function handle_attachment_meta($post_id, $meta_key, $value) {
        if ($meta_key === '_college_gallery') {
            $gallery_ids = array();

            if (is_array($value)) {
                foreach ($value as $entry) {
                    $gallery_ids[] = self::resolve_media_entry($entry, $post_id);
                }
            } else {
                $gallery_ids[] = self::resolve_media_entry($value, $post_id);
            }

            $gallery_ids = array_filter(array_map('intval', $gallery_ids));
            return array_values(array_unique($gallery_ids));
        }

        return self::resolve_media_entry($value, $post_id);
    }

    private static function resolve_media_entry($entry, $post_id) {
        if (is_array($entry)) {
            if (!empty($entry['id'])) {
                return absint($entry['id']);
            }
            if (!empty($entry['url'])) {
                return self::sideload_media($entry['url'], $post_id);
            }
            return 0;
        }

        if (is_numeric($entry)) {
            return absint($entry);
        }

        if (is_string($entry) && filter_var($entry, FILTER_VALIDATE_URL)) {
            return self::sideload_media($entry, $post_id);
        }

        return 0;
    }

    private static function sideload_media($url, $post_id) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            error_log('[CPB Media] Download failed for ' . $url . ': ' . $tmp->get_error_message());
            return 0;
        }

        // Detect the MIME type from the downloaded file
        $file_type = wp_check_filetype_and_ext($tmp, basename($tmp));
        
        // Generate a proper filename with extension
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (empty($file_type['ext']) && !empty($file_type['type'])) {
            // Get extension from MIME type
            $mime_to_ext = array(
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            );
            if (isset($mime_to_ext[$file_type['type']])) {
                $filename = 'image-' . time() . '-' . rand(1000, 9999) . '.' . $mime_to_ext[$file_type['type']];
            }
        }
        
        // If still no extension, add .jpg as default for images
        if (strpos($filename, '.') === false) {
            $filename .= '.jpg';
        }

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );
        
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            error_log('[CPB Media] Sideload failed for ' . $url . ': ' . $attachment_id->get_error_message());
            @unlink($file_array['tmp_name']);
            return 0;
        }

        return (int) $attachment_id;
    }

    private static function resolve_slugs_to_ids($slugs, $post_type) {
        $ids = array();
        if (!is_array($slugs)) {
            return $ids;
        }

        foreach ($slugs as $slug) {
            $slug = sanitize_title($slug);
            if (!$slug) {
                continue;
            }
            $post = get_page_by_path($slug, OBJECT, $post_type);
            if ($post) {
                $ids[] = (int) $post->ID;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function sync_college_courses($college_id, $course_ids) {
        $current = get_post_meta($college_id, '_linked_courses', true);
        $current = is_array($current) ? $current : array();
        $course_ids = array_values(array_unique(array_map('intval', $course_ids)));

        update_post_meta($college_id, '_linked_courses', $course_ids);

        $removed = array_diff($current, $course_ids);
        foreach ($removed as $course_id) {
            $linked = get_post_meta($course_id, '_linked_colleges', true);
            $linked = is_array($linked) ? $linked : array();
            $linked = array_values(array_diff($linked, array($college_id)));
            update_post_meta($course_id, '_linked_colleges', $linked);
        }

        $added = array_diff($course_ids, $current);
        foreach ($added as $course_id) {
            $linked = get_post_meta($course_id, '_linked_colleges', true);
            $linked = is_array($linked) ? $linked : array();
            if (!in_array($college_id, $linked, true)) {
                $linked[] = $college_id;
                update_post_meta($course_id, '_linked_colleges', $linked);
            }
        }
    }

    private static function sync_course_colleges($course_id, $college_ids) {
        $current = get_post_meta($course_id, '_linked_colleges', true);
        $current = is_array($current) ? $current : array();
        $college_ids = array_values(array_unique(array_map('intval', $college_ids)));

        update_post_meta($course_id, '_linked_colleges', $college_ids);

        $removed = array_diff($current, $college_ids);
        foreach ($removed as $college_id) {
            $linked = get_post_meta($college_id, '_linked_courses', true);
            $linked = is_array($linked) ? $linked : array();
            $linked = array_values(array_diff($linked, array($course_id)));
            update_post_meta($college_id, '_linked_courses', $linked);
        }

        $added = array_diff($college_ids, $current);
        foreach ($added as $college_id) {
            $linked = get_post_meta($college_id, '_linked_courses', true);
            $linked = is_array($linked) ? $linked : array();
            if (!in_array($course_id, $linked, true)) {
                $linked[] = $course_id;
                update_post_meta($college_id, '_linked_courses', $linked);
            }
        }
    }

    private static function sync_exam_streams($exam_id, $stream_ids) {
        $current = get_post_meta($exam_id, '_linked_streams', true);
        $current = is_array($current) ? $current : array();
        $stream_ids = array_values(array_unique(array_map('intval', $stream_ids)));

        update_post_meta($exam_id, '_linked_streams', $stream_ids);

        $removed = array_diff($current, $stream_ids);
        foreach ($removed as $stream_id) {
            $linked = get_post_meta($stream_id, '_linked_exams', true);
            $linked = is_array($linked) ? $linked : array();
            $linked = array_values(array_diff($linked, array($exam_id)));
            update_post_meta($stream_id, '_linked_exams', $linked);
        }

        $added = array_diff($stream_ids, $current);
        foreach ($added as $stream_id) {
            $linked = get_post_meta($stream_id, '_linked_exams', true);
            $linked = is_array($linked) ? $linked : array();
            if (!in_array($exam_id, $linked, true)) {
                $linked[] = $exam_id;
                update_post_meta($stream_id, '_linked_exams', $linked);
            }
        }
    }

    private static function sanitize_meta_value($value, $schema) {
        $type = isset($schema['type']) ? $schema['type'] : 'string';

        if ($type === 'integer') {
            return absint($value);
        }

        if ($type === 'array') {
            if (!is_array($value)) {
                return array();
            }
            $item_type = isset($schema['items']) ? $schema['items'] : 'string';
            
            if ($item_type === 'object') {
                // For university departments array of objects
                $sanitized = array();
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $sanitized[] = array(
                            'name' => isset($item['name']) ? sanitize_text_field($item['name']) : '',
                            'ownership' => isset($item['ownership']) ? sanitize_text_field($item['ownership']) : '',
                            'courses' => isset($item['courses']) ? absint($item['courses']) : 0,
                        );
                    }
                }
                return $sanitized;
            }
            
            $sanitized = array();
            foreach ($value as $item) {
                $sanitized[] = ($item_type === 'integer') ? absint($item) : sanitize_text_field($item);
            }
            return array_values(array_unique($sanitized));
        }

        // Use wp_kses_post for HTML-enabled fields to preserve formatting
        if (!empty($schema['html'])) {
            return wp_kses_post($value);
        }

        return sanitize_text_field($value);
    }
}

CPB_CPT_API_Sync::init();
