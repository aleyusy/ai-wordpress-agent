<?php
/**
 * Plugin Name: AI WordPress Agent
 * Plugin URI: https://github.com/your-org/ai-wordpress-agent
 * Description: ИИ-агент для управления WordPress через чат. Создавайте страницы, настраивайте дизайн, устанавливайте плагины и многое другое через общение с AI.
 * Version: 2.0.3
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author: AIWP Team
 * License: GPL v2 or later
 * Text Domain: aiwp
 */

defined('ABSPATH') || exit;

define('AIWP_VERSION', '2.0.3');
define('AIWP_PATH', plugin_dir_path(__FILE__));
define('AIWP_URL', plugin_dir_url(__FILE__));
define('AIWP_FILE', __FILE__);

require_once AIWP_PATH . 'includes/class-main.php';

function aiwp() {
    static $instance = null;
    if (null === $instance) {
        $instance = new AIWP_Main();
    }
    return $instance;
}

register_activation_hook(__FILE__, function () {
    $defaults = [
        'api_key' => '',
        'api_url' => 'https://openrouter.ai/api/v1/chat/completions',
        'model' => 'openai/gpt-4o-mini',
        'max_tokens' => 4096,
        'temperature' => 0.7,
        'github_repo' => 'aleyusy/ai-wordpress-agent',
    ];
    if (!get_option('aiwp_settings')) {
        add_option('aiwp_settings', $defaults);
    }
});

register_deactivation_hook(__FILE__, function () {
    // Cleanup if needed
});

add_action('plugins_loaded', function () {
    aiwp()->init();
});
