<?php

defined('ABSPATH') || exit;

class AIWP_Tools {
    private $tools = [];
    private $registered = false;

    public function __construct() {
        $this->register_all();
    }

    private function register_all() {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        $this->add_wp_get_pages();
        $this->add_wp_get_posts();
        $this->add_wp_create_page();
        $this->add_wp_update_page();
        $this->add_wp_delete_page();
        $this->add_wp_create_post();
        $this->add_wp_update_post();
        $this->add_wp_delete_post();
        $this->add_wp_get_plugins();
        $this->add_wp_install_plugin();
        $this->add_wp_activate_plugin();
        $this->add_wp_deactivate_plugin();
        $this->add_wp_get_themes();
        $this->add_wp_switch_theme();
        $this->add_wp_get_theme_mods();
        $this->add_wp_set_theme_mod();
        $this->add_wp_get_menus();
        $this->add_wp_create_menu();
        $this->add_wp_add_menu_item();
        $this->add_wp_assign_menu_location();
        $this->add_wp_get_media();
        $this->add_wp_upload_media();
        $this->add_wp_get_site_info();
        $this->add_wp_get_option();
        $this->add_wp_update_option();
        $this->add_wp_set_homepage();
        $this->add_wp_get_categories();
        $this->add_wp_add_category();
        $this->add_wp_get_tags();
        $this->add_wp_add_tag();
        $this->add_wp_get_sidebars();
        $this->add_wp_add_widget();
        $this->add_wp_remove_widget();
        $this->add_wp_add_custom_css();
        $this->add_wp_get_custom_css();
        $this->add_wp_get_post_types();
        $this->add_wp_get_taxonomies();
        $this->add_wp_get_terms();
        $this->add_wp_create_term();
        $this->add_wp_update_term();
        $this->add_wp_delete_term();
        $this->add_wp_get_users();
        $this->add_wp_get_comments();
    }

    private function register($name, $description, $parameters, $callback) {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'parameters' => $parameters,
            'callback' => $callback,
        ];
    }

    public function get_for_ai(): array {
        $result = [];
        foreach ($this->tools as $tool) {
            $result[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ];
        }
        return $result;
    }

    public function execute($name, $args) {
        if (!isset($this->tools[$name])) {
            return ['success' => false, 'error' => "Tool '$name' not found."];
        }
        try {
            $result = call_user_func($this->tools[$name]['callback'], $args);
            return $result;
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function get_tool_names(): array {
        return array_keys($this->tools);
    }

    public function get_tools_list(): array {
        $list = [];
        foreach ($this->tools as $name => $tool) {
            $list[] = [
                'name' => $name,
                'description' => $tool['description'],
            ];
        }
        return $list;
    }

    private function add_wp_get_pages() {
        $this->register(
            'wp_get_pages',
            'Get a list of WordPress pages with optional filters.',
            [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'trash', 'any'], 'description' => 'Filter by status'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of pages to return (default 50)'],
                    'search' => ['type' => 'string', 'description' => 'Search term'],
                ],
            ],
            function ($args) {
                $args = wp_parse_args($args, ['status' => 'any', 'limit' => 50, 'search' => '']);
                $query_args = [
                    'post_type' => 'page',
                    'post_status' => $args['status'],
                    'posts_per_page' => min((int)$args['limit'], 100),
                    'orderby' => 'title',
                    'order' => 'ASC',
                ];
                if (!empty($args['search'])) {
                    $query_args['s'] = sanitize_text_field($args['search']);
                }
                $pages = get_posts($query_args);
                $result = [];
                foreach ($pages as $page) {
                    $result[] = [
                        'id' => $page->ID,
                        'title' => $page->post_title,
                        'slug' => $page->post_name,
                        'status' => $page->post_status,
                        'date' => $page->post_date,
                        'url' => get_permalink($page->ID),
                        'edit_url' => admin_url('post.php?post=' . $page->ID . '&action=edit'),
                    ];
                }
                return ['success' => true, 'pages' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_get_posts() {
        $this->register(
            'wp_get_posts',
            'Get a list of WordPress posts with optional filters.',
            [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'trash', 'any'], 'description' => 'Filter by status'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of posts to return (default 50)'],
                    'category' => ['type' => 'string', 'description' => 'Filter by category slug'],
                    'search' => ['type' => 'string', 'description' => 'Search term'],
                ],
            ],
            function ($args) {
                $args = wp_parse_args($args, ['status' => 'any', 'limit' => 50, 'search' => '']);
                $query_args = [
                    'post_type' => 'post',
                    'post_status' => $args['status'],
                    'posts_per_page' => min((int)$args['limit'], 100),
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];
                if (!empty($args['search'])) {
                    $query_args['s'] = sanitize_text_field($args['search']);
                }
                if (!empty($args['category'])) {
                    $query_args['category_name'] = sanitize_text_field($args['category']);
                }
                $posts = get_posts($query_args);
                $result = [];
                foreach ($posts as $post) {
                    $cats = wp_get_post_categories($post->ID, ['fields' => 'names']);
                    $result[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'slug' => $post->post_name,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                        'categories' => $cats,
                        'url' => get_permalink($post->ID),
                        'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                    ];
                }
                return ['success' => true, 'posts' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_create_page() {
        $this->register(
            'wp_create_page',
            'Create a new WordPress page with title, content, and optional settings.',
            [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Page title (required)'],
                    'content' => ['type' => 'string', 'description' => 'Page content in HTML format'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending'], 'description' => 'Publication status (default publish)'],
                    'slug' => ['type' => 'string', 'description' => 'URL slug (optional, auto-generated from title if empty)'],
                    'parent_id' => ['type' => 'integer', 'description' => 'ID of parent page for hierarchy'],
                    'template' => ['type' => 'string', 'description' => 'Page template filename (e.g., "page-templates/full-width.php")'],
                    'featured_image_url' => ['type' => 'string', 'description' => 'URL of image to set as featured image'],
                    'meta' => ['type' => 'object', 'description' => 'Custom meta fields as key-value pairs', 'additionalProperties' => ['type' => 'string']],
                ],
                'required' => ['title'],
            ],
            function ($args) {
                $page_data = [
                    'post_type' => 'page',
                    'post_title' => sanitize_text_field($args['title']),
                    'post_content' => wp_kses_post($args['content'] ?? ''),
                    'post_status' => $args['status'] ?? 'publish',
                    'post_name' => !empty($args['slug']) ? sanitize_title($args['slug']) : '',
                ];
                if (!empty($args['parent_id'])) {
                    $page_data['post_parent'] = (int)$args['parent_id'];
                }
                $page_id = wp_insert_post($page_data, true);
                if (is_wp_error($page_id)) {
                    return ['success' => false, 'error' => $page_id->get_error_message()];
                }
                if (!empty($args['template'])) {
                    update_post_meta($page_id, '_wp_page_template', sanitize_text_field($args['template']));
                }
                if (!empty($args['featured_image_url'])) {
                    $this->set_featured_image_from_url($page_id, $args['featured_image_url']);
                }
                if (!empty($args['meta']) && is_array($args['meta'])) {
                    foreach ($args['meta'] as $key => $value) {
                        update_post_meta($page_id, sanitize_key($key), sanitize_text_field($value));
                    }
                }
                return [
                    'success' => true,
                    'page_id' => $page_id,
                    'url' => get_permalink($page_id),
                    'edit_url' => admin_url('post.php?post=' . $page_id . '&action=edit'),
                    'title' => $args['title'],
                ];
            }
        );
    }

    private function add_wp_update_page() {
        $this->register(
            'wp_update_page',
            'Update an existing WordPress page by ID.',
            [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'description' => 'ID of the page to update (required)'],
                    'title' => ['type' => 'string', 'description' => 'New page title'],
                    'content' => ['type' => 'string', 'description' => 'New page content in HTML'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'trash'], 'description' => 'New publication status'],
                    'slug' => ['type' => 'string', 'description' => 'New URL slug'],
                    'template' => ['type' => 'string', 'description' => 'Page template filename'],
                    'featured_image_url' => ['type' => 'string', 'description' => 'URL of image to set as featured image'],
                ],
                'required' => ['page_id'],
            ],
            function ($args) {
                $page_id = (int)$args['page_id'];
                $page = get_post($page_id);
                if (!$page || $page->post_type !== 'page') {
                    return ['success' => false, 'error' => 'Page not found.'];
                }
                $update_data = ['ID' => $page_id];
                if (isset($args['title'])) {
                    $update_data['post_title'] = sanitize_text_field($args['title']);
                }
                if (isset($args['content'])) {
                    $update_data['post_content'] = wp_kses_post($args['content']);
                }
                if (isset($args['status'])) {
                    $update_data['post_status'] = $args['status'];
                }
                if (isset($args['slug'])) {
                    $update_data['post_name'] = sanitize_title($args['slug']);
                }
                $result = wp_update_post($update_data, true);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                if (isset($args['template'])) {
                    update_post_meta($page_id, '_wp_page_template', sanitize_text_field($args['template']));
                }
                if (!empty($args['featured_image_url'])) {
                    $this->set_featured_image_from_url($page_id, $args['featured_image_url']);
                }
                return [
                    'success' => true,
                    'page_id' => $page_id,
                    'url' => get_permalink($page_id),
                    'edit_url' => admin_url('post.php?post=' . $page_id . '&action=edit'),
                ];
            }
        );
    }

    private function add_wp_delete_page() {
        $this->register(
            'wp_delete_page',
            'Delete a WordPress page by ID (moves to trash or permanently deletes).',
            [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'description' => 'ID of the page to delete (required)'],
                    'force' => ['type' => 'boolean', 'description' => 'Permanently delete (skip trash). Default false.'],
                ],
                'required' => ['page_id'],
            ],
            function ($args) {
                $page_id = (int)$args['page_id'];
                $force = !empty($args['force']);
                $result = wp_delete_post($page_id, $force);
                if (!$result) {
                    return ['success' => false, 'error' => 'Failed to delete page.'];
                }
                return ['success' => true, 'message' => 'Page deleted.', 'permanently' => $force];
            }
        );
    }

    private function add_wp_create_post() {
        $this->register(
            'wp_create_post',
            'Create a new WordPress blog post.',
            [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Post title (required)'],
                    'content' => ['type' => 'string', 'description' => 'Post content in HTML format'],
                    'excerpt' => ['type' => 'string', 'description' => 'Post excerpt'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending'], 'description' => 'Publication status (default publish)'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names or slugs'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names'],
                    'featured_image_url' => ['type' => 'string', 'description' => 'URL of image to set as featured image'],
                ],
                'required' => ['title'],
            ],
            function ($args) {
                $post_data = [
                    'post_type' => 'post',
                    'post_title' => sanitize_text_field($args['title']),
                    'post_content' => wp_kses_post($args['content'] ?? ''),
                    'post_excerpt' => sanitize_textarea_field($args['excerpt'] ?? ''),
                    'post_status' => $args['status'] ?? 'publish',
                ];
                $post_id = wp_insert_post($post_data, true);
                if (is_wp_error($post_id)) {
                    return ['success' => false, 'error' => $post_id->get_error_message()];
                }
                if (!empty($args['categories']) && is_array($args['categories'])) {
                    $cat_ids = [];
                    foreach ($args['categories'] as $cat_name) {
                        $term = term_exists($cat_name, 'category');
                        if (!$term) {
                            $term = wp_insert_term($cat_name, 'category');
                        }
                        if (!is_wp_error($term)) {
                            $cat_ids[] = (int)$term['term_id'];
                        }
                    }
                    if (!empty($cat_ids)) {
                        wp_set_post_categories($post_id, $cat_ids);
                    }
                }
                if (!empty($args['tags']) && is_array($args['tags'])) {
                    wp_set_post_tags($post_id, array_map('sanitize_text_field', $args['tags']));
                }
                if (!empty($args['featured_image_url'])) {
                    $this->set_featured_image_from_url($post_id, $args['featured_image_url']);
                }
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'url' => get_permalink($post_id),
                    'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'title' => $args['title'],
                ];
            }
        );
    }

    private function add_wp_update_post() {
        $this->register(
            'wp_update_post',
            'Update an existing WordPress post by ID.',
            [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'ID of the post to update (required)'],
                    'title' => ['type' => 'string', 'description' => 'New post title'],
                    'content' => ['type' => 'string', 'description' => 'New post content in HTML'],
                    'excerpt' => ['type' => 'string', 'description' => 'New post excerpt'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'trash'], 'description' => 'New publication status'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names or slugs'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names'],
                ],
                'required' => ['post_id'],
            ],
            function ($args) {
                $post_id = (int)$args['post_id'];
                $post = get_post($post_id);
                if (!$post || $post->post_type !== 'post') {
                    return ['success' => false, 'error' => 'Post not found.'];
                }
                $update_data = ['ID' => $post_id];
                if (isset($args['title'])) {
                    $update_data['post_title'] = sanitize_text_field($args['title']);
                }
                if (isset($args['content'])) {
                    $update_data['post_content'] = wp_kses_post($args['content']);
                }
                if (isset($args['excerpt'])) {
                    $update_data['post_excerpt'] = sanitize_textarea_field($args['excerpt']);
                }
                if (isset($args['status'])) {
                    $update_data['post_status'] = $args['status'];
                }
                $result = wp_update_post($update_data, true);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                if (!empty($args['categories']) && is_array($args['categories'])) {
                    $cat_ids = [];
                    foreach ($args['categories'] as $cat_name) {
                        $term = term_exists($cat_name, 'category');
                        if (!$term) {
                            $term = wp_insert_term($cat_name, 'category');
                        }
                        if (!is_wp_error($term)) {
                            $cat_ids[] = (int)$term['term_id'];
                        }
                    }
                    if (!empty($cat_ids)) {
                        wp_set_post_categories($post_id, $cat_ids);
                    }
                }
                if (!empty($args['tags']) && is_array($args['tags'])) {
                    wp_set_post_tags($post_id, array_map('sanitize_text_field', $args['tags']));
                }
                return ['success' => true, 'post_id' => $post_id, 'url' => get_permalink($post_id)];
            }
        );
    }

    private function add_wp_delete_post() {
        $this->register(
            'wp_delete_post',
            'Delete a WordPress post or page by ID.',
            [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'ID of the post/page to delete (required)'],
                    'force' => ['type' => 'boolean', 'description' => 'Permanently delete. Default false (move to trash).'],
                ],
                'required' => ['post_id'],
            ],
            function ($args) {
                $post_id = (int)$args['post_id'];
                $force = !empty($args['force']);
                $result = wp_delete_post($post_id, $force);
                if (!$result) {
                    return ['success' => false, 'error' => 'Failed to delete post.'];
                }
                return ['success' => true, 'message' => 'Post deleted.', 'permanently' => $force];
            }
        );
    }

    private function add_wp_get_plugins() {
        $this->register(
            'wp_get_plugins',
            'Get a list of all installed plugins with their status.',
            [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['all', 'active', 'inactive'], 'description' => 'Filter by status (default all)'],
                ],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $all_plugins = get_plugins();
                $active_plugins = get_option('active_plugins', []);
                $result = [];
                $status_filter = $args['status'] ?? 'all';
                foreach ($all_plugins as $plugin_path => $plugin_data) {
                    $is_active = in_array($plugin_path, $active_plugins, true) || is_plugin_active_for_network($plugin_path);
                    if ($status_filter === 'active' && !$is_active) continue;
                    if ($status_filter === 'inactive' && $is_active) continue;
                    $result[] = [
                        'name' => $plugin_data['Name'],
                        'slug' => dirname($plugin_path),
                        'plugin_path' => $plugin_path,
                        'version' => $plugin_data['Version'],
                        'active' => $is_active,
                        'description' => wp_trim_words($plugin_data['Description'], 20),
                        'author' => $plugin_data['Author'],
                    ];
                }
                return ['success' => true, 'plugins' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_install_plugin() {
        $this->register(
            'wp_install_plugin',
            'Install a plugin from the WordPress.org repository by slug.',
            [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Plugin slug from WordPress.org (e.g., "contact-form-7") (required)'],
                    'activate' => ['type' => 'boolean', 'description' => 'Activate after install (default false)'],
                ],
                'required' => ['slug'],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                $slug = sanitize_text_field($args['slug']);
                $activate = !empty($args['activate']);

                $api = plugins_api('plugin_information', [
                    'slug' => $slug,
                    'fields' => ['sections' => false, 'short_description' => false],
                ]);

                if (is_wp_error($api)) {
                    return ['success' => false, 'error' => 'Plugin not found: ' . $api->get_error_message()];
                }

                $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
                $result = $upgrader->install($api->download_link);

                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => 'Install failed: ' . $result->get_error_message()];
                }

                if ($result === false) {
                    return ['success' => false, 'error' => 'Install failed. Check permissions.'];
                }

                if ($activate) {
                    $plugin_path = $upgrader->plugin_info();
                    if ($plugin_path) {
                        activate_plugin($plugin_path);
                    }
                }

                return [
                    'success' => true,
                    'plugin_name' => $api->name,
                    'plugin_slug' => $slug,
                    'activated' => $activate,
                    'message' => "Plugin '{$api->name}' installed successfully." . ($activate ? ' Activated.' : ''),
                ];
            }
        );
    }

    private function add_wp_activate_plugin() {
        $this->register(
            'wp_activate_plugin',
            'Activate an installed plugin by its path or slug.',
            [
                'type' => 'object',
                'properties' => [
                    'plugin' => ['type' => 'string', 'description' => 'Plugin slug or full plugin path (e.g., "contact-form-7/wp-contact-form-7.php") (required)'],
                ],
                'required' => ['plugin'],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $plugin = sanitize_text_field($args['plugin']);
                if (strpos($plugin, '/') === false) {
                    $all_plugins = get_plugins();
                    $found = false;
                    foreach ($all_plugins as $path => $data) {
                        if (strpos($path, $plugin . '/') === 0) {
                            $plugin = $path;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        foreach ($all_plugins as $path => $data) {
                            if (strpos($path, $plugin) !== false) {
                                $plugin = $path;
                                $found = true;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        return ['success' => false, 'error' => "Plugin '{$args['plugin']}' not found."];
                    }
                }
                $result = activate_plugin($plugin);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                return ['success' => true, 'message' => "Plugin '{$plugin}' activated."];
            }
        );
    }

    private function add_wp_deactivate_plugin() {
        $this->register(
            'wp_deactivate_plugin',
            'Deactivate an active plugin by its path or slug.',
            [
                'type' => 'object',
                'properties' => [
                    'plugin' => ['type' => 'string', 'description' => 'Plugin slug or full plugin path (required)'],
                ],
                'required' => ['plugin'],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $plugin = sanitize_text_field($args['plugin']);
                if (strpos($plugin, '/') === false) {
                    $all_plugins = get_plugins();
                    foreach ($all_plugins as $path => $data) {
                        if (strpos($path, $plugin . '/') === 0 || strpos($path, $plugin) !== false) {
                            $plugin = $path;
                            break;
                        }
                    }
                }
                deactivate_plugins($plugin);
                return ['success' => true, 'message' => "Plugin '{$plugin}' deactivated."];
            }
        );
    }

    private function add_wp_get_themes() {
        $this->register(
            'wp_get_themes',
            'Get a list of all installed themes.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $themes = wp_get_themes();
                $current = get_stylesheet();
                $result = [];
                foreach ($themes as $slug => $theme) {
                    $result[] = [
                        'name' => $theme->get('Name'),
                        'slug' => $slug,
                        'version' => $theme->get('Version'),
                        'active' => ($slug === $current),
                        'author' => $theme->get('Author'),
                        'description' => wp_trim_words($theme->get('Description'), 20),
                        'screenshot' => $theme->get_screenshot(),
                    ];
                }
                return ['success' => true, 'themes' => $result, 'current_theme' => $current];
            }
        );
    }

    private function add_wp_switch_theme() {
        $this->register(
            'wp_switch_theme',
            'Switch the active WordPress theme.',
            [
                'type' => 'object',
                'properties' => [
                    'theme' => ['type' => 'string', 'description' => 'Theme slug/stylesheet (e.g., "twentytwentyfour") (required)'],
                ],
                'required' => ['theme'],
            ],
            function ($args) {
                $theme_slug = sanitize_text_field($args['theme']);
                $theme = wp_get_theme($theme_slug);
                if (!$theme->exists()) {
                    return ['success' => false, 'error' => "Theme '{$theme_slug}' not found."];
                }
                switch_theme($theme_slug);
                return ['success' => true, 'message' => "Theme switched to '{$theme->get('Name')}'."];
            }
        );
    }

    private function add_wp_get_theme_mods() {
        $this->register(
            'wp_get_theme_mods',
            'Get current active theme modifications/settings.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $theme_mods = get_theme_mods();
                return ['success' => true, 'theme_mods' => $theme_mods ?: []];
            }
        );
    }

    private function add_wp_set_theme_mod() {
        $this->register(
            'wp_set_theme_mod',
            'Set a theme modification (customizer setting).',
            [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Theme mod key/name (required)'],
                    'value' => ['type' => 'string', 'description' => 'Value to set (required)'],
                ],
                'required' => ['key', 'value'],
            ],
            function ($args) {
                $key = sanitize_text_field($args['key']);
                $value = sanitize_text_field($args['value']);
                set_theme_mod($key, $value);
                return ['success' => true, 'message' => "Theme mod '{$key}' set to '{$value}'."];
            }
        );
    }

    private function add_wp_get_menus() {
        $this->register(
            'wp_get_menus',
            'Get a list of all navigation menus.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $menus = wp_get_nav_menus();
                $locations = get_nav_menu_locations();
                $result = [];
                foreach ($menus as $menu) {
                    $menu_locations = [];
                    foreach ($locations as $location => $menu_id) {
                        if ($menu_id === $menu->term_id) {
                            $menu_locations[] = $location;
                        }
                    }
                    $items = wp_get_nav_menu_items($menu->term_id);
                    $result[] = [
                        'id' => $menu->term_id,
                        'name' => $menu->name,
                        'slug' => $menu->slug,
                        'locations' => $menu_locations,
                        'item_count' => $items ? count($items) : 0,
                    ];
                }
                return ['success' => true, 'menus' => $result, 'registered_locations' => get_registered_nav_menus()];
            }
        );
    }

    private function add_wp_create_menu() {
        $this->register(
            'wp_create_menu',
            'Create a new navigation menu.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Menu name (required)'],
                    'location' => ['type' => 'string', 'description' => 'Theme location slug to assign this menu to (optional)'],
                ],
                'required' => ['name'],
            ],
            function ($args) {
                $menu_name = sanitize_text_field($args['name']);
                $menu_id = wp_create_nav_menu($menu_name);
                if (is_wp_error($menu_id)) {
                    return ['success' => false, 'error' => $menu_id->get_error_message()];
                }
                if (!empty($args['location'])) {
                    $locations = get_nav_menu_locations();
                    $locations[$args['location']] = $menu_id;
                    set_theme_mod('nav_menu_locations', $locations);
                }
                return [
                    'success' => true,
                    'menu_id' => $menu_id,
                    'name' => $menu_name,
                    'edit_url' => admin_url('nav-menus.php?action=edit&menu=' . $menu_id),
                ];
            }
        );
    }

    private function add_wp_add_menu_item() {
        $this->register(
            'wp_add_menu_item',
            'Add an item to a navigation menu.',
            [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer', 'description' => 'Menu ID (required)'],
                    'title' => ['type' => 'string', 'description' => 'Item title (required)'],
                    'url' => ['type' => 'string', 'description' => 'Item URL (for custom links). Specify URL or type/page_id, not both.'],
                    'type' => ['type' => 'string', 'enum' => ['custom', 'page', 'post', 'category'], 'description' => 'Item type (default custom)'],
                    'object_id' => ['type' => 'integer', 'description' => 'ID of the page/post/category to link to (used with type=page/post/category)'],
                    'parent_id' => ['type' => 'integer', 'description' => 'ID of parent menu item for sub-menus (optional)'],
                    'order' => ['type' => 'integer', 'description' => 'Menu order position (optional)'],
                ],
                'required' => ['menu_id', 'title'],
            ],
            function ($args) {
                $menu_id = (int)$args['menu_id'];
                $title = sanitize_text_field($args['title']);
                $type = $args['type'] ?? 'custom';

                $item_data = [
                    'menu-item-title' => $title,
                    'menu-item-status' => 'publish',
                ];

                if ($type === 'custom' || !empty($args['url'])) {
                    $item_data['menu-item-type'] = 'custom';
                    $item_data['menu-item-url'] = esc_url_raw($args['url'] ?? '');
                } elseif ($type === 'page' && !empty($args['object_id'])) {
                    $item_data['menu-item-type'] = 'post_type';
                    $item_data['menu-item-object'] = 'page';
                    $item_data['menu-item-object-id'] = (int)$args['object_id'];
                } elseif ($type === 'post' && !empty($args['object_id'])) {
                    $item_data['menu-item-type'] = 'post_type';
                    $item_data['menu-item-object'] = 'post';
                    $item_data['menu-item-object-id'] = (int)$args['object_id'];
                } elseif ($type === 'category' && !empty($args['object_id'])) {
                    $item_data['menu-item-type'] = 'taxonomy';
                    $item_data['menu-item-object'] = 'category';
                    $item_data['menu-item-object-id'] = (int)$args['object_id'];
                }

                if (!empty($args['parent_id'])) {
                    $item_data['menu-item-parent-id'] = (int)$args['parent_id'];
                }
                if (isset($args['order'])) {
                    $item_data['menu-item-position'] = (int)$args['order'];
                }

                $item_id = wp_update_nav_menu_item($menu_id, 0, $item_data);
                if (is_wp_error($item_id)) {
                    return ['success' => false, 'error' => $item_id->get_error_message()];
                }
                return ['success' => true, 'item_id' => $item_id, 'title' => $title, 'menu_id' => $menu_id];
            }
        );
    }

    private function add_wp_assign_menu_location() {
        $this->register(
            'wp_assign_menu_location',
            'Assign a menu to a theme location.',
            [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer', 'description' => 'Menu ID (required)'],
                    'location' => ['type' => 'string', 'description' => 'Theme location slug (required)'],
                ],
                'required' => ['menu_id', 'location'],
            ],
            function ($args) {
                $menu_id = (int)$args['menu_id'];
                $location = sanitize_text_field($args['location']);
                $locations = get_nav_menu_locations();
                $locations[$location] = $menu_id;
                set_theme_mod('nav_menu_locations', $locations);
                return ['success' => true, 'message' => "Menu assigned to location '{$location}'."];
            }
        );
    }

    private function add_wp_get_media() {
        $this->register(
            'wp_get_media',
            'Get media library items (images, videos, documents).',
            [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Maximum items to return (default 20)'],
                    'type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'document'], 'description' => 'Filter by media type'],
                ],
            ],
            function ($args) {
                $query_args = [
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'posts_per_page' => min((int)($args['limit'] ?? 20), 100),
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];
                if (!empty($args['type'])) {
                    $type_map = [
                        'image' => 'image',
                        'video' => 'video',
                        'audio' => 'audio',
                        'document' => 'application',
                    ];
                    $mime_type = $type_map[$args['type']] ?? '';
                    if ($mime_type) {
                        $query_args['post_mime_type'] = $mime_type;
                    }
                }
                $attachments = get_posts($query_args);
                $result = [];
                foreach ($attachments as $attachment) {
                    $meta = wp_get_attachment_metadata($attachment->ID);
                    $result[] = [
                        'id' => $attachment->ID,
                        'title' => $attachment->post_title,
                        'filename' => wp_basename($attachment->guid),
                        'url' => wp_get_attachment_url($attachment->ID),
                        'thumbnail' => wp_get_attachment_thumb_url($attachment->ID),
                        'type' => wp_attachment_is_image($attachment->ID) ? 'image' : 'document',
                        'width' => $meta['width'] ?? null,
                        'height' => $meta['height'] ?? null,
                        'file_size' => filesize(get_attached_file($attachment->ID)),
                        'date' => $attachment->post_date,
                    ];
                }
                return ['success' => true, 'media' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_upload_media() {
        $this->register(
            'wp_upload_media',
            'Upload media from a URL to the WordPress media library.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL of the image/file to upload (required)'],
                    'title' => ['type' => 'string', 'description' => 'Title for the media item (optional)'],
                    'alt_text' => ['type' => 'string', 'description' => 'Alt text for images (optional)'],
                    'caption' => ['type' => 'string', 'description' => 'Caption (optional)'],
                ],
                'required' => ['url'],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $url = esc_url_raw($args['url']);
                $title = sanitize_text_field($args['title'] ?? '');

                $tmp = download_url($url);
                if (is_wp_error($tmp)) {
                    return ['success' => false, 'error' => 'Download failed: ' . $tmp->get_error_message()];
                }

                $file_array = [
                    'name' => $title ? sanitize_file_name($title . '.' . pathinfo($url, PATHINFO_EXTENSION)) : wp_basename($url),
                    'tmp_name' => $tmp,
                ];

                $id = media_handle_sideload($file_array, 0);
                if (is_wp_error($id)) {
                    @unlink($file_array['tmp_name']);
                    return ['success' => false, 'error' => 'Upload failed: ' . $id->get_error_message()];
                }

                if (!empty($args['alt_text'])) {
                    update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($args['alt_text']));
                }
                if (!empty($args['caption'])) {
                    wp_update_post(['ID' => $id, 'post_excerpt' => sanitize_text_field($args['caption'])]);
                }

                return [
                    'success' => true,
                    'attachment_id' => $id,
                    'url' => wp_get_attachment_url($id),
                    'thumbnail' => wp_get_attachment_thumb_url($id),
                    'edit_url' => admin_url('post.php?post=' . $id . '&action=edit'),
                ];
            }
        );
    }

    private function add_wp_get_site_info() {
        $this->register(
            'wp_get_site_info',
            'Get comprehensive WordPress site information including name, URL, version, active theme, plugins count, and more.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $active_plugins = get_option('active_plugins', []);
                return [
                    'success' => true,
                    'info' => [
                        'site_name' => get_bloginfo('name'),
                        'site_description' => get_bloginfo('description'),
                        'site_url' => get_site_url(),
                        'admin_url' => admin_url(),
                        'wp_version' => get_bloginfo('version'),
                        'php_version' => PHP_VERSION,
                        'active_theme' => wp_get_theme()->get('Name'),
                        'active_theme_slug' => get_stylesheet(),
                        'active_plugins_count' => count($active_plugins),
                        'page_count' => wp_count_posts('page')->publish,
                        'post_count' => wp_count_posts('post')->publish,
                        'language' => get_bloginfo('language'),
                        'charset' => get_bloginfo('charset'),
                        'admin_email' => get_bloginfo('admin_email'),
                    ],
                ];
            }
        );
    }

    private function add_wp_get_option() {
        $this->register(
            'wp_get_option',
            'Get a WordPress option/setting value.',
            [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Option name (required)'],
                ],
                'required' => ['key'],
            ],
            function ($args) {
                $key = sanitize_text_field($args['key']);
                $value = get_option($key);
                return ['success' => true, 'key' => $key, 'value' => $value];
            }
        );
    }

    private function add_wp_update_option() {
        $this->register(
            'wp_update_option',
            'Update a WordPress option/setting.',
            [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Option name (required)'],
                    'value' => ['type' => 'string', 'description' => 'Option value (required)'],
                ],
                'required' => ['key', 'value'],
            ],
            function ($args) {
                $key = sanitize_text_field($args['key']);
                $value = sanitize_text_field($args['value']);
                update_option($key, $value);
                return ['success' => true, 'message' => "Option '{$key}' updated."];
            }
        );
    }

    private function add_wp_set_homepage() {
        $this->register(
            'wp_set_homepage',
            'Set the WordPress homepage and optionally the posts page.',
            [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'description' => 'ID of the page to set as homepage (required)'],
                    'posts_page_id' => ['type' => 'integer', 'description' => 'ID of the page to set as posts page (optional)'],
                ],
                'required' => ['page_id'],
            ],
            function ($args) {
                $page_id = (int)$args['page_id'];
                $page = get_post($page_id);
                if (!$page || $page->post_type !== 'page') {
                    return ['success' => false, 'error' => 'Page not found.'];
                }
                update_option('page_on_front', $page_id);
                update_option('show_on_front', 'page');
                if (!empty($args['posts_page_id'])) {
                    update_option('page_for_posts', (int)$args['posts_page_id']);
                }
                return [
                    'success' => true,
                    'message' => "Homepage set to '{$page->post_title}'.",
                    'homepage_url' => get_permalink($page_id),
                ];
            }
        );
    }

    private function add_wp_get_categories() {
        $this->register(
            'wp_get_categories',
            'Get a list of all post categories.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $categories = get_categories(['hide_empty' => false]);
                $result = [];
                foreach ($categories as $cat) {
                    $result[] = [
                        'id' => $cat->term_id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'count' => $cat->count,
                        'parent_id' => $cat->parent,
                    ];
                }
                return ['success' => true, 'categories' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_add_category() {
        $this->register(
            'wp_add_category',
            'Create a new post category.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Category name (required)'],
                    'slug' => ['type' => 'string', 'description' => 'Category slug (optional)'],
                    'description' => ['type' => 'string', 'description' => 'Category description (optional)'],
                    'parent_id' => ['type' => 'integer', 'description' => 'ID of parent category (optional)'],
                ],
                'required' => ['name'],
            ],
            function ($args) {
                $term = wp_insert_term(
                    sanitize_text_field($args['name']),
                    'category',
                    array_filter([
                        'slug' => !empty($args['slug']) ? sanitize_title($args['slug']) : null,
                        'description' => !empty($args['description']) ? sanitize_textarea_field($args['description']) : null,
                        'parent' => !empty($args['parent_id']) ? (int)$args['parent_id'] : null,
                    ])
                );
                if (is_wp_error($term)) {
                    return ['success' => false, 'error' => $term->get_error_message()];
                }
                return ['success' => true, 'term_id' => $term['term_id'], 'name' => $args['name']];
            }
        );
    }

    private function add_wp_get_tags() {
        $this->register(
            'wp_get_tags',
            'Get a list of all post tags.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $tags = get_tags(['hide_empty' => false]);
                $result = [];
                foreach ($tags as $tag) {
                    $result[] = [
                        'id' => $tag->term_id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'count' => $tag->count,
                    ];
                }
                return ['success' => true, 'tags' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_add_tag() {
        $this->register(
            'wp_add_tag',
            'Create a new post tag.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Tag name (required)'],
                    'slug' => ['type' => 'string', 'description' => 'Tag slug (optional)'],
                    'description' => ['type' => 'string', 'description' => 'Tag description (optional)'],
                ],
                'required' => ['name'],
            ],
            function ($args) {
                $term = wp_insert_term(
                    sanitize_text_field($args['name']),
                    'post_tag',
                    array_filter([
                        'slug' => !empty($args['slug']) ? sanitize_title($args['slug']) : null,
                        'description' => !empty($args['description']) ? sanitize_textarea_field($args['description']) : null,
                    ])
                );
                if (is_wp_error($term)) {
                    return ['success' => false, 'error' => $term->get_error_message()];
                }
                return ['success' => true, 'term_id' => $term['term_id'], 'name' => $args['name']];
            }
        );
    }

    private function add_wp_get_sidebars() {
        $this->register(
            'wp_get_sidebars',
            'Get a list of all registered widget sidebars and their current widgets.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                global $wp_registered_sidebars;
                $result = [];
                foreach ($wp_registered_sidebars as $id => $sidebar) {
                    $widgets = wp_get_sidebars_widgets();
                    $sidebar_widgets = $widgets[$id] ?? [];
                    $result[] = [
                        'id' => $id,
                        'name' => $sidebar['name'],
                        'description' => $sidebar['description'] ?? '',
                        'widget_count' => count($sidebar_widgets),
                        'widgets' => $sidebar_widgets,
                    ];
                }
                return ['success' => true, 'sidebars' => $result];
            }
        );
    }

    private function add_wp_add_widget() {
        $this->register(
            'wp_add_widget',
            'Add a widget to a sidebar. Common widgets: "text" (Text), "search" (Search), "nav_menu" (Navigation Menu), "recent-posts" (Recent Posts), "categories" (Categories), "tag_cloud" (Tag Cloud), "archives" (Archives), "pages" (Pages)',
            [
                'type' => 'object',
                'properties' => [
                    'sidebar_id' => ['type' => 'string', 'description' => 'Sidebar ID (e.g., "sidebar-1") (required)'],
                    'widget_type' => ['type' => 'string', 'description' => 'Widget type/base ID (e.g., "text", "search", "recent-posts") (required)'],
                    'settings' => ['type' => 'object', 'description' => 'Widget settings as key-value pairs. For text widget: title, text. For nav_menu: title, nav_menu (menu ID).'],
                ],
                'required' => ['sidebar_id', 'widget_type'],
            ],
            function ($args) {
                $sidebar_id = sanitize_text_field($args['sidebar_id']);
                $widget_type = sanitize_text_field($args['widget_type']);
                $settings = $args['settings'] ?? [];

                $sidebars_widgets = wp_get_sidebars_widgets();

                if (!isset($sidebars_widgets[$sidebar_id])) {
                    return ['success' => false, 'error' => "Sidebar '{$sidebar_id}' not found."];
                }

                $widget_instances = get_option("widget_{$widget_type}", []);
                if (!is_array($widget_instances)) {
                    $widget_instances = [];
                }

                $next_id = 1;
                if (!empty($widget_instances)) {
                    $keys = array_keys($widget_instances);
                    $numeric_keys = array_filter($keys, 'is_int');
                    $next_id = !empty($numeric_keys) ? max($numeric_keys) + 1 : 1;
                }

                $sanitized_settings = [];
                foreach ($settings as $key => $value) {
                    $sanitized_settings[sanitize_key($key)] = sanitize_text_field(strval($value));
                }

                $widget_instances[$next_id] = $sanitized_settings;
                update_option("widget_{$widget_type}", $widget_instances);

                $sidebars_widgets[$sidebar_id][] = "{$widget_type}-{$next_id}";
                wp_set_sidebars_widgets($sidebars_widgets);

                return [
                    'success' => true,
                    'message' => "Widget '{$widget_type}' added to '{$sidebar_id}'.",
                    'widget_id' => "{$widget_type}-{$next_id}",
                ];
            }
        );
    }

    private function add_wp_remove_widget() {
        $this->register(
            'wp_remove_widget',
            'Remove a widget from a sidebar by its widget ID.',
            [
                'type' => 'object',
                'properties' => [
                    'widget_id' => ['type' => 'string', 'description' => 'Widget ID (e.g., "text-3") (required)'],
                    'sidebar_id' => ['type' => 'string', 'description' => 'Sidebar ID (optional, will search all sidebars if not provided)'],
                ],
                'required' => ['widget_id'],
            ],
            function ($args) {
                $widget_id = sanitize_text_field($args['widget_id']);
                $sidebar_id = !empty($args['sidebar_id']) ? sanitize_text_field($args['sidebar_id']) : null;

                $sidebars_widgets = wp_get_sidebars_widgets();

                if ($sidebar_id) {
                    if (!isset($sidebars_widgets[$sidebar_id])) {
                        return ['success' => false, 'error' => "Sidebar '{$sidebar_id}' not found."];
                    }
                    $key = array_search($widget_id, $sidebars_widgets[$sidebar_id]);
                    if ($key === false) {
                        return ['success' => false, 'error' => "Widget '{$widget_id}' not found in sidebar '{$sidebar_id}'."];
                    }
                    array_splice($sidebars_widgets[$sidebar_id], $key, 1);
                } else {
                    $found = false;
                    foreach ($sidebars_widgets as $sb_id => $widgets) {
                        if (is_array($widgets)) {
                            $key = array_search($widget_id, $widgets);
                            if ($key !== false) {
                                array_splice($sidebars_widgets[$sb_id], $key, 1);
                                $found = true;
                                $sidebar_id = $sb_id;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        return ['success' => false, 'error' => "Widget '{$widget_id}' not found in any sidebar."];
                    }
                }

                wp_set_sidebars_widgets($sidebars_widgets);
                return ['success' => true, 'message' => "Widget '{$widget_id}' removed from sidebar."];
            }
        );
    }

    private function add_wp_add_custom_css() {
        $this->register(
            'wp_add_custom_css',
            'Add custom CSS to the site (appears in Appearance > Customize > Additional CSS).',
            [
                'type' => 'object',
                'properties' => [
                    'css' => ['type' => 'string', 'description' => 'CSS code to add (required)'],
                    'append' => ['type' => 'boolean', 'description' => 'Append to existing custom CSS (default true). If false, replaces all custom CSS.'],
                ],
                'required' => ['css'],
            ],
            function ($args) {
                $css = sanitize_textarea_field($args['css']);
                $append = isset($args['append']) ? (bool)$args['append'] : true;

                if ($append) {
                    $existing = wp_get_custom_css();
                    $css = $existing . "\n\n" . $css;
                }

                wp_update_custom_css_post($css);
                return [
                    'success' => true,
                    'message' => $append ? 'CSS appended successfully.' : 'Custom CSS set successfully.',
                    'preview_url' => admin_url('customize.php'),
                ];
            }
        );
    }

    private function add_wp_get_custom_css() {
        $this->register(
            'wp_get_custom_css',
            'Get the current custom CSS for the site.',
            [
                'type' => 'object',
                'properties' => [],
            ],
            function ($args) {
                $css = wp_get_custom_css();
                return ['success' => true, 'css' => $css ?: '', 'length' => strlen($css)];
            }
        );
    }

    private function add_wp_get_post_types() {
        $this->register(
            'wp_get_post_types',
            'Get a list of all registered post types (including custom post types).',
            [
                'type' => 'object',
                'properties' => [
                    'public_only' => ['type' => 'boolean', 'description' => 'Show only publicly visible post types (default true)'],
                ],
            ],
            function ($args) {
                $public_only = isset($args['public_only']) ? (bool)$args['public_only'] : true;
                $post_types = get_post_types(['public' => $public_only], 'objects');
                $result = [];
                foreach ($post_types as $slug => $pt) {
                    $counts = wp_count_posts($slug);
                    $result[] = [
                        'slug' => $slug,
                        'name' => $pt->label,
                        'singular_name' => $pt->labels->singular_name,
                        'public' => $pt->public,
                        'counts' => [
                            'publish' => $counts->publish ?? 0,
                            'draft' => $counts->draft ?? 0,
                            'trash' => $counts->trash ?? 0,
                        ],
                        'supports' => $pt->supports,
                    ];
                }
                return ['success' => true, 'post_types' => $result];
            }
        );
    }

    private function add_wp_get_taxonomies() {
        $this->register(
            'wp_get_taxonomies',
            'Get a list of all registered taxonomies.',
            [
                'type' => 'object',
                'properties' => [
                    'public_only' => ['type' => 'boolean', 'description' => 'Show only publicly visible taxonomies (default true)'],
                ],
            ],
            function ($args) {
                $public_only = isset($args['public_only']) ? (bool)$args['public_only'] : true;
                $taxonomies = get_taxonomies(['public' => $public_only], 'objects');
                $result = [];
                foreach ($taxonomies as $slug => $tax) {
                    $result[] = [
                        'slug' => $slug,
                        'name' => $tax->label,
                        'singular_name' => $tax->labels->singular_name,
                        'hierarchical' => $tax->hierarchical,
                        'post_types' => $tax->object_type,
                    ];
                }
                return ['success' => true, 'taxonomies' => $result];
            }
        );
    }

    private function add_wp_get_users() {
        $this->register(
            'wp_get_users',
            'Get a list of WordPress users.',
            [
                'type' => 'object',
                'properties' => [
                    'role' => ['type' => 'string', 'description' => 'Filter by role (e.g., "administrator", "editor", "author")'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum users to return (default 50)'],
                ],
            ],
            function ($args) {
                $query_args = [
                    'number' => min((int)($args['limit'] ?? 50), 100),
                    'fields' => ['ID', 'user_login', 'display_name', 'user_email', 'roles'],
                ];
                if (!empty($args['role'])) {
                    $query_args['role'] = sanitize_text_field($args['role']);
                }
                $users = get_users($query_args);
                $result = [];
                foreach ($users as $user) {
                    $result[] = [
                        'id' => $user->ID,
                        'login' => $user->user_login,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'roles' => array_values($user->roles),
                        'edit_url' => admin_url('user-edit.php?user_id=' . $user->ID),
                    ];
                }
                return ['success' => true, 'users' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_get_comments() {
        $this->register(
            'wp_get_comments',
            'Get a list of comments with optional filters.',
            [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['hold', 'approve', 'spam', 'trash'], 'description' => 'Comment status filter (default approve)'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum comments to return (default 20)'],
                    'post_id' => ['type' => 'integer', 'description' => 'Filter by post/page ID'],
                ],
            ],
            function ($args) {
                $query_args = [
                    'number' => min((int)($args['limit'] ?? 20), 100),
                    'status' => $args['status'] ?? 'approve',
                ];
                if (!empty($args['post_id'])) {
                    $query_args['post_id'] = (int)$args['post_id'];
                }
                $comments = get_comments($query_args);
                $result = [];
                foreach ($comments as $comment) {
                    $result[] = [
                        'id' => $comment->comment_ID,
                        'post_id' => $comment->comment_post_ID,
                        'author' => $comment->comment_author,
                        'email' => $comment->comment_author_email,
                        'content' => $comment->comment_content,
                        'date' => $comment->comment_date,
                        'status' => wp_get_comment_status($comment->comment_ID),
                        'edit_url' => admin_url('comment.php?action=editcomment&c=' . $comment->comment_ID),
                    ];
                }
                return ['success' => true, 'comments' => $result, 'total' => count($result)];
            }
        );
    }

    private function add_wp_get_terms() {
        $this->register(
            'wp_get_terms',
            'Get terms from any taxonomy (categories, tags, custom taxonomies like destination, product_cat, etc.).',
            [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g., "category", "post_tag", "destination", "product_cat") (required)'],
                    'hide_empty' => ['type' => 'boolean', 'description' => 'Hide terms with no posts (default false)'],
                    'search' => ['type' => 'string', 'description' => 'Search term'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum terms to return (default 100)'],
                ],
                'required' => ['taxonomy'],
            ],
            function ($args) {
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) {
                    return ['success' => false, 'error' => "Taxonomy '{$taxonomy}' not found."];
                }
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => !empty($args['hide_empty']),
                    'search' => !empty($args['search']) ? sanitize_text_field($args['search']) : '',
                    'number' => min((int)($args['limit'] ?? 100), 200),
                ]);
                if (is_wp_error($terms)) {
                    return ['success' => false, 'error' => $terms->get_error_message()];
                }
                $result = [];
                foreach ($terms as $term) {
                    $result[] = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'description' => $term->description,
                        'count' => $term->count,
                        'parent_id' => $term->parent,
                        'taxonomy' => $term->taxonomy,
                        'edit_url' => admin_url('term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $term->term_id . '&post_type='),
                    ];
                }
                return ['success' => true, 'terms' => $result, 'total' => count($result), 'taxonomy' => $taxonomy];
            }
        );
    }

    private function add_wp_create_term() {
        $this->register(
            'wp_create_term',
            'Create a new term in any taxonomy (categories, tags, custom taxonomies).',
            [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g., "category", "post_tag", "destination") (required)'],
                    'name' => ['type' => 'string', 'description' => 'Term name (required)'],
                    'slug' => ['type' => 'string', 'description' => 'Term slug (optional)'],
                    'description' => ['type' => 'string', 'description' => 'Term description (optional)'],
                    'parent_id' => ['type' => 'integer', 'description' => 'ID of parent term (optional)'],
                ],
                'required' => ['taxonomy', 'name'],
            ],
            function ($args) {
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) {
                    return ['success' => false, 'error' => "Taxonomy '{$taxonomy}' not found."];
                }
                $term = wp_insert_term(
                    sanitize_text_field($args['name']),
                    $taxonomy,
                    array_filter([
                        'slug' => !empty($args['slug']) ? sanitize_title($args['slug']) : null,
                        'description' => !empty($args['description']) ? sanitize_textarea_field($args['description']) : null,
                        'parent' => !empty($args['parent_id']) ? (int)$args['parent_id'] : null,
                    ])
                );
                if (is_wp_error($term)) {
                    return ['success' => false, 'error' => $term->get_error_message()];
                }
                return [
                    'success' => true,
                    'term_id' => $term['term_id'],
                    'name' => $args['name'],
                    'taxonomy' => $taxonomy,
                    'edit_url' => admin_url('term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $term['term_id']),
                ];
            }
        );
    }

    private function add_wp_update_term() {
        $this->register(
            'wp_update_term',
            'Update an existing term in any taxonomy (rename, change slug, description, parent).',
            [
                'type' => 'object',
                'properties' => [
                    'term_id' => ['type' => 'integer', 'description' => 'ID of the term to update (required)'],
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g., "category", "destination") (required)'],
                    'name' => ['type' => 'string', 'description' => 'New term name'],
                    'slug' => ['type' => 'string', 'description' => 'New term slug'],
                    'description' => ['type' => 'string', 'description' => 'New term description'],
                    'parent_id' => ['type' => 'integer', 'description' => 'New parent term ID'],
                ],
                'required' => ['term_id', 'taxonomy'],
            ],
            function ($args) {
                $term_id = (int)$args['term_id'];
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) {
                    return ['success' => false, 'error' => "Taxonomy '{$taxonomy}' not found."];
                }
                $update_args = [];
                if (isset($args['name'])) {
                    $update_args['name'] = sanitize_text_field($args['name']);
                }
                if (isset($args['slug'])) {
                    $update_args['slug'] = sanitize_title($args['slug']);
                }
                if (isset($args['description'])) {
                    $update_args['description'] = sanitize_textarea_field($args['description']);
                }
                if (isset($args['parent_id'])) {
                    $update_args['parent'] = (int)$args['parent_id'];
                }
                $result = wp_update_term($term_id, $taxonomy, $update_args);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                return [
                    'success' => true,
                    'term_id' => $result['term_id'],
                    'taxonomy' => $taxonomy,
                    'edit_url' => admin_url('term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $result['term_id']),
                ];
            }
        );
    }

    private function add_wp_delete_term() {
        $this->register(
            'wp_delete_term',
            'Delete a term from any taxonomy.',
            [
                'type' => 'object',
                'properties' => [
                    'term_id' => ['type' => 'integer', 'description' => 'ID of the term to delete (required)'],
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g., "category", "destination") (required)'],
                ],
                'required' => ['term_id', 'taxonomy'],
            ],
            function ($args) {
                $term_id = (int)$args['term_id'];
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) {
                    return ['success' => false, 'error' => "Taxonomy '{$taxonomy}' not found."];
                }
                $result = wp_delete_term($term_id, $taxonomy);
                if (is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }
                if (!$result) {
                    return ['success' => false, 'error' => 'Failed to delete term.'];
                }
                return ['success' => true, 'message' => 'Term deleted.', 'term_id' => $term_id, 'taxonomy' => $taxonomy];
            }
        );
    }

    private function set_featured_image_from_url($post_id, $url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url = esc_url_raw($url);
        $tmp = download_url($url);
        if (is_wp_error($tmp)) return;

        $file_array = [
            'name' => wp_basename($url),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
        @unlink($tmp);
    }
}
