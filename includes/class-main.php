<?php

defined('ABSPATH') || exit;

class AIWP_Main {
    private static $instance = null;

    public function init() {
        $this->load_dependencies();
        $this->init_hooks();
        $this->run_migrations();
    }

    private function load_dependencies() {
        require_once AIWP_PATH . 'includes/class-roles.php';
        require_once AIWP_PATH . 'includes/class-memory.php';
        require_once AIWP_PATH . 'includes/class-ai.php';
        require_once AIWP_PATH . 'includes/class-tools.php';
        require_once AIWP_PATH . 'includes/class-chat.php';
        require_once AIWP_PATH . 'includes/class-admin.php';
        require_once AIWP_PATH . 'includes/class-analyzer.php';
        require_once AIWP_PATH . 'includes/class-plugin-search.php';
        require_once AIWP_PATH . 'includes/class-skills.php';
        require_once AIWP_PATH . 'includes/class-file-editor.php';
        require_once AIWP_PATH . 'includes/class-updater.php';
        require_once AIWP_PATH . 'includes/class-frontend.php';
    }

    private function init_hooks() {
        add_action('init', [$this, 'register_session']);
        add_action('admin_menu', [AIWP_Admin::class, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [AIWP_Admin::class, 'enqueue_assets']);

        add_action('wp_ajax_aiwp_chat', [AIWP_Chat::class, 'handle_ajax']);
        add_action('wp_ajax_aiwp_history', [AIWP_Chat::class, 'get_history_ajax']);
        add_action('wp_ajax_aiwp_clear_history', [AIWP_Chat::class, 'clear_history_ajax']);
        add_action('wp_ajax_aiwp_test_api', [AIWP_Admin::class, 'test_api_ajax']);
        add_action('wp_ajax_aiwp_fetch_models', [AIWP_Admin::class, 'fetch_models_ajax']);
        add_action('wp_ajax_aiwp_save_favorite_model', [AIWP_Admin::class, 'save_favorite_model_ajax']);
        add_action('wp_ajax_aiwp_delete_favorite_model', [AIWP_Admin::class, 'delete_favorite_model_ajax']);

        add_action('wp_ajax_aiwp_save_role_config', [AIWP_Admin::class, 'save_role_config_ajax']);
        add_action('wp_ajax_aiwp_get_analysis', [AIWP_Admin::class, 'get_analysis_ajax']);
        add_action('wp_ajax_aiwp_run_analysis', [AIWP_Admin::class, 'run_analysis_ajax']);
        add_action('wp_ajax_aiwp_save_memory', [AIWP_Admin::class, 'save_memory_ajax']);
        add_action('wp_ajax_aiwp_delete_memory', [AIWP_Admin::class, 'delete_memory_ajax']);
        add_action('wp_ajax_aiwp_save_skill', [AIWP_Admin::class, 'save_skill_ajax']);
        add_action('wp_ajax_aiwp_delete_skill', [AIWP_Admin::class, 'delete_skill_ajax']);
        add_action('wp_ajax_aiwp_list_theme_files', [AIWP_Admin::class, 'list_theme_files_ajax']);
        add_action('wp_ajax_aiwp_read_theme_file', [AIWP_Admin::class, 'read_theme_file_ajax']);
        add_action('wp_ajax_aiwp_write_theme_file', [AIWP_Admin::class, 'write_theme_file_ajax']);

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        AIWP_Roles::init();
        AIWP_Frontend::init();

        $updater = new AIWP_Updater(AIWP_FILE);
        $updater->init();
    }

    public function register_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
    }

    public function register_rest_routes() {
        register_rest_route('aiwp/v1', '/tools', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_tools'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('aiwp/v1', '/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_execute_tool'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('aiwp/v1', '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_chat'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('aiwp/v1', '/site-info', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_site_info'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function rest_get_tools($request) {
        $tools_manager = self::get_tools_manager();
        $tools = $tools_manager->get_tools_list();
        return rest_ensure_response(['tools' => $tools, 'total' => count($tools)]);
    }

    public function rest_execute_tool($request) {
        $name = $request->get_param('name');
        $args = $request->get_param('args') ?? [];

        if (empty($name)) {
            return new WP_Error('missing_name', 'Tool name required', ['status' => 400]);
        }

        $tools_manager = self::get_tools_manager();
        $result = $tools_manager->execute($name, $args);
        return rest_ensure_response($result);
    }

    public function rest_chat($request) {
        $message = $request->get_param('message');
        if (empty($message)) {
            return new WP_Error('missing_message', 'Message required', ['status' => 400]);
        }

        $user_id = get_current_user_id();
        wp_set_current_user($user_id);

        try {
            $response = AIWP_Chat::handle_ajax();
            return rest_ensure_response($response);
        } catch (\Throwable $e) {
            return new WP_Error('chat_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function rest_site_info($request) {
        $tools_manager = self::get_tools_manager();
        $result = $tools_manager->execute('wp_get_site_info', []);
        return rest_ensure_response($result);
    }

    public static function get_tools_manager() {
        static $tools = null;
        if (null === $tools) {
            $tools = new AIWP_Tools();
        }
        return $tools;
    }

    public static function get_ai() {
        static $ai = null;
        if (null === $ai) {
            $ai = new AIWP_AI();
        }
        return $ai;
    }

    public static function get_chat() {
        static $chat = null;
        if (null === $chat) {
            $chat = new AIWP_Chat();
        }
        return $chat;
    }

    private function run_migrations() {
        $current_version = get_option('aiwp_version', '1.0.0');
        if (version_compare($current_version, AIWP_VERSION, '<')) {
            $this->migrate_from($current_version);
            update_option('aiwp_version', AIWP_VERSION);
        }
    }

    private function migrate_from(string $from_version) {
        if (version_compare($from_version, '2.0.0', '<')) {
            $old_history = get_option('aiwp_chat_history', []);
            if (!empty($old_history) && is_array($old_history)) {
                $admin = get_userdata(1);
                if ($admin) {
                    update_option('aiwp_history_' . $admin->ID, $old_history);
                }
            }

            AIWP_Roles::init();

            if (!get_option(AIWP_Memory::SITE_MEMORY_KEY)) {
                update_option(AIWP_Memory::SITE_MEMORY_KEY, [
                    'version' => '1.0',
                    'site_structure' => [],
                    'plugins_knowledge' => [],
                    'themes_knowledge' => [],
                ]);
            }

            if (!get_option(AIWP_Memory::PATTERNS_MEMORY_KEY)) {
                update_option(AIWP_Memory::PATTERNS_MEMORY_KEY, [
                    'version' => '1.0',
                    'user_preferences' => [
                        'default_status' => 'draft',
                        'content_language' => get_bloginfo('language'),
                    ],
                    'common_actions' => [],
                    'learned_solutions' => [],
                    'error_history' => [],
                ]);
            }

            if (!get_option(AIWP_Skills::SKILLS_KEY)) {
                update_option(AIWP_Skills::SKILLS_KEY, [
                    'version' => '1.0',
                    'skills' => self::get_default_skills(),
                ]);
            }

            if (AIWP_Analyzer::is_stale()) {
                AIWP_Analyzer::analyze_site();
            }
        }
    }

    private static function get_default_skills(): array {
        return [
            'create_landing_page' => [
                'name' => 'Create Landing Page',
                'description' => 'Creates a landing page with customizable content',
                'category' => 'pages',
                'tags' => ['landing', 'page'],
                'steps' => [
                    [
                        'id' => 1,
                        'tool' => 'wp_create_page',
                        'args' => [
                            'title' => '{{title}}',
                            'content' => '{{content}}',
                            'status' => '{{status|draft}}',
                        ],
                        'description' => 'Create the landing page',
                        'optional' => false,
                    ],
                ],
                'parameters' => [
                    'title' => ['type' => 'string', 'description' => 'Page title', 'required' => true],
                    'content' => ['type' => 'string', 'description' => 'Page HTML content'],
                    'status' => ['type' => 'string', 'description' => 'publish or draft', 'default' => 'draft'],
                ],
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
                'created_by' => 0,
            ],
            'setup_seo' => [
                'name' => 'Setup SEO',
                'description' => 'Install and activate SEO plugin',
                'category' => 'setup',
                'tags' => ['seo', 'setup'],
                'steps' => [
                    [
                        'id' => 1,
                        'tool' => 'wp_install_plugin',
                        'args' => ['slug' => '{{plugin_slug|wordpress-seo}}', 'activate' => true],
                        'description' => 'Install SEO plugin',
                        'optional' => false,
                    ],
                ],
                'parameters' => [
                    'plugin_slug' => ['type' => 'string', 'description' => 'SEO plugin slug', 'default' => 'wordpress-seo'],
                ],
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
                'created_by' => 0,
            ],
            'setup_security' => [
                'name' => 'Setup Security',
                'description' => 'Install and activate security plugin',
                'category' => 'setup',
                'tags' => ['security', 'setup'],
                'steps' => [
                    [
                        'id' => 1,
                        'tool' => 'wp_install_plugin',
                        'args' => ['slug' => '{{plugin_slug|wordfence-security}}', 'activate' => true],
                        'description' => 'Install security plugin',
                        'optional' => false,
                    ],
                ],
                'parameters' => [
                    'plugin_slug' => ['type' => 'string', 'description' => 'Security plugin slug', 'default' => 'wordfence-security'],
                ],
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
                'created_by' => 0,
            ],
        ];
    }
}
