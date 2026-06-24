<?php
defined('ABSPATH') || exit;

class AIWP_Roles {
    const CAPABILITIES = [
        'aiwp_manage_settings' => 'Manage AI WordPress Agent settings',
        'aiwp_use_chat' => 'Use AI chat interface',
        'aiwp_edit_code' => 'Edit theme files and custom CSS',
        'aiwp_manage_skills' => 'Create and manage AI skills',
        'aiwp_manage_memory' => 'View and manage AI memory',
        'aiwp_install_plugins' => 'Install and activate plugins via AI',
        'aiwp_analyze_site' => 'Run site analysis',
        'aiwp_manage_roles' => 'Manage AI WordPress Agent roles',
    ];

    const ROLE_CONFIG_KEY = 'aiwp_role_permissions';

    const DEFAULT_ROLE_CONFIG = [
        'administrator' => [
            'aiwp_manage_settings' => true,
            'aiwp_use_chat' => true,
            'aiwp_edit_code' => true,
            'aiwp_manage_skills' => true,
            'aiwp_manage_memory' => true,
            'aiwp_install_plugins' => true,
            'aiwp_analyze_site' => true,
            'aiwp_manage_roles' => true,
        ],
        'editor' => [
            'aiwp_manage_settings' => false,
            'aiwp_use_chat' => true,
            'aiwp_edit_code' => false,
            'aiwp_manage_skills' => false,
            'aiwp_manage_memory' => false,
            'aiwp_install_plugins' => false,
            'aiwp_analyze_site' => false,
            'aiwp_manage_roles' => false,
        ],
        'author' => [
            'aiwp_manage_settings' => false,
            'aiwp_use_chat' => false,
            'aiwp_edit_code' => false,
            'aiwp_manage_skills' => false,
            'aiwp_manage_memory' => false,
            'aiwp_install_plugins' => false,
            'aiwp_analyze_site' => false,
            'aiwp_manage_roles' => false,
        ],
        'contributor' => [
            'aiwp_manage_settings' => false,
            'aiwp_use_chat' => false,
            'aiwp_edit_code' => false,
            'aiwp_manage_skills' => false,
            'aiwp_manage_memory' => false,
            'aiwp_install_plugins' => false,
            'aiwp_analyze_site' => false,
            'aiwp_manage_roles' => false,
        ],
        'subscriber' => [
            'aiwp_manage_settings' => false,
            'aiwp_use_chat' => false,
            'aiwp_edit_code' => false,
            'aiwp_manage_skills' => false,
            'aiwp_manage_memory' => false,
            'aiwp_install_plugins' => false,
            'aiwp_analyze_site' => false,
            'aiwp_manage_roles' => false,
        ],
    ];

    const TOOL_RESTRICTIONS = [
        'wp_install_plugin' => 'aiwp_install_plugins',
        'wp_activate_plugin' => 'aiwp_install_plugins',
        'wp_deactivate_plugin' => 'aiwp_install_plugins',
        'wp_delete_page' => 'aiwp_manage_settings',
        'wp_delete_post' => 'aiwp_manage_settings',
        'wp_delete_term' => 'aiwp_manage_settings',
        'wp_update_option' => 'aiwp_manage_settings',
        'wp_switch_theme' => 'aiwp_manage_settings',
        'wp_set_theme_mod' => 'aiwp_manage_settings',
        'wp_create_menu' => 'aiwp_manage_settings',
        'wp_add_menu_item' => 'aiwp_manage_settings',
        'wp_assign_menu_location' => 'aiwp_manage_settings',
        'wp_add_widget' => 'aiwp_manage_settings',
        'wp_remove_widget' => 'aiwp_manage_settings',
        'aiwp_write_theme_file' => 'aiwp_edit_code',
        'aiwp_edit_theme_file' => 'aiwp_edit_code',
        'aiwp_save_skill' => 'aiwp_manage_skills',
        'aiwp_delete_skill' => 'aiwp_manage_skills',
        'aiwp_import_skills' => 'aiwp_manage_skills',
        'aiwp_save_memory' => 'aiwp_manage_memory',
        'aiwp_delete_memory' => 'aiwp_manage_memory',
        'aiwp_save_user_preference' => 'aiwp_manage_memory',
        'aiwp_analyze_site' => 'aiwp_analyze_site',
        'aiwp_search_plugins' => 'aiwp_install_plugins',
    ];

    public static function init(): void {
        $config = get_option(self::ROLE_CONFIG_KEY);
        if (!is_array($config)) {
            update_option(self::ROLE_CONFIG_KEY, self::DEFAULT_ROLE_CONFIG, false);
        }
    }

    public static function user_has_capability(string $capability): bool {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }
        if (in_array('administrator', (array) $user->roles, true)) {
            return true;
        }
        $config = get_option(self::ROLE_CONFIG_KEY, self::DEFAULT_ROLE_CONFIG);
        foreach ($user->roles as $role) {
            if (isset($config[$role][$capability]) && $config[$role][$capability]) {
                return true;
            }
        }
        return false;
    }

    public static function can_use_tool(string $tool_name): bool {
        if (!isset(self::TOOL_RESTRICTIONS[$tool_name])) {
            return true;
        }
        $required_cap = self::TOOL_RESTRICTIONS[$tool_name];
        return self::user_has_capability($required_cap);
    }

    public static function get_available_tools(array $all_tools): array {
        $available = [];
        foreach ($all_tools as $tool) {
            if (self::can_use_tool($tool['name'])) {
                $available[] = $tool;
            }
        }
        return $available;
    }

    public static function get_role_config(): array {
        return get_option(self::ROLE_CONFIG_KEY, self::DEFAULT_ROLE_CONFIG);
    }

    public static function update_role_config(array $config): bool {
        return update_option(self::ROLE_CONFIG_KEY, $config, false);
    }

    public static function get_capabilities(): array {
        return self::CAPABILITIES;
    }

    public static function get_user_capabilities(int $user_id): array {
        $user = get_userdata($user_id);
        if (!$user) {
            return [];
        }
        $config = self::get_role_config();
        $caps = [];
        foreach (self::CAPABILITIES as $cap_key => $cap_desc) {
            $caps[$cap_key] = [
                'description' => $cap_desc,
                'granted' => false,
            ];
            if (in_array('administrator', (array) $user->roles, true)) {
                $caps[$cap_key]['granted'] = true;
                continue;
            }
            foreach ($user->roles as $role) {
                if (isset($config[$role][$cap_key]) && $config[$role][$cap_key]) {
                    $caps[$cap_key]['granted'] = true;
                    break;
                }
            }
        }
        return $caps;
    }
}
