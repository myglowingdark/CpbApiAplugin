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
            return array(
                'type' => 'array',
                'items' => array(
                    'type' => isset($schema['items']) ? $schema['items'] : 'string',
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
                ?>
                <div class="wrap">
                        <h1>CPB CPT API Sync Documentation</h1>
                        <p>This plugin exposes REST helpers for Colleges, Courses, Exams, and Streams. Use Application Passwords for auth.</p>
                <p>
                    <a class="button button-primary" href="<?php echo $json_template_url; ?>" download>Download JSON Templates</a>
                    <a class="button" href="<?php echo $js_helper_url; ?>" download>Download Playwright Helper</a>
                </p>

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

                        <h2>College Request Template</h2>
                        <pre style="white-space: pre-wrap; background:#fff; padding:12px; border:1px solid #ccd0d4;">{
    "items": [
        {
            "post_type": "college",
            "slug": "abc-college",
            "title": "ABC College",
            "content": "&lt;p&gt;...&lt;/p&gt;",
            "excerpt": "Short summary",
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
                "_college_address_line": "Street, Area"
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
            return 0;
        }

        $file_array = array(
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
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
            $sanitized = array();
            foreach ($value as $item) {
                $sanitized[] = ($item_type === 'integer') ? absint($item) : sanitize_text_field($item);
            }
            return array_values(array_unique($sanitized));
        }

        return sanitize_text_field($value);
    }
}

CPB_CPT_API_Sync::init();
