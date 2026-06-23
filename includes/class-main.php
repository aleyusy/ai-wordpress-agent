<?php

defined('ABSPATH') || exit;

class AIWP_Main {
    private static $instance = null;

    public function init() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once AIWP_PATH . 'includes/class-ai.php';
        require_once AIWP_PATH . 'includes/class-tools.php';
        require_once AIWP_PATH . 'includes/class-chat.php';
        require_once AIWP_PATH . 'includes/class-admin.php';
        require_once AIWP_PATH . 'includes/class-updater.php';
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

        $updater = new AIWP_Updater(AIWP_FILE);
        $updater->init();
    }

    public function register_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
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
}
