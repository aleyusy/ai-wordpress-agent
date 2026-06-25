<?php
defined('ABSPATH') || exit;

class AIWP_Memory {
    const SITE_MEMORY_KEY = 'aiwp_memory_site';
    const PATTERNS_MEMORY_KEY = 'aiwp_memory_patterns';
    const SESSION_PREFIX = 'aiwp_session_';
    const HISTORY_PREFIX = 'aiwp_history_';
    const USER_PREFS_PREFIX = 'aiwp_user_prefs_';

    const MAX_HISTORY = 100;
    const MAX_SESSION_AGE = 86400;
    const MAX_MEMORY_ENTRIES = 500;

    public static function save_memory(string $category, string $key, $value, int $user_id = 0): bool {
        $memories = self::get_all_memories();
        if (!isset($memories[$category])) {
            $memories[$category] = [];
        }
        $memories[$category][$key] = [
            'value' => $value,
            'user_id' => $user_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        if (count($memories[$category]) > self::MAX_MEMORY_ENTRIES) {
            $memories[$category] = array_slice($memories[$category], -self::MAX_MEMORY_ENTRIES, null, true);
        }
        return update_option(self::SITE_MEMORY_KEY, $memories, false);
    }

    public static function get_memory(string $category, string $key = '') {
        $memories = self::get_all_memories();
        if (empty($key)) {
            return $memories[$category] ?? [];
        }
        return $memories[$category][$key] ?? null;
    }

    public static function search_memory(string $query, int $limit = 20): array {
        $memories = self::get_all_memories();
        $results = [];
        $query_lower = strtolower($query);

        foreach ($memories as $category => $entries) {
            if (!is_array($entries)) continue;
            foreach ($entries as $key => $entry) {
                $searchable = strtolower($key . ' ' . json_encode($entry['value']));
                if (strpos($searchable, $query_lower) !== false) {
                    $results[] = [
                        'category' => $category,
                        'key' => $key,
                        'value' => $entry['value'],
                        'created_at' => $entry['created_at'] ?? '',
                    ];
                    if (count($results) >= $limit) {
                        return $results;
                    }
                }
            }
        }
        return $results;
    }

    public static function delete_memory(string $category, string $key): bool {
        $memories = self::get_all_memories();
        if (isset($memories[$category][$key])) {
            unset($memories[$category][$key]);
            if (empty($memories[$category])) {
                unset($memories[$category]);
            }
            return update_option(self::SITE_MEMORY_KEY, $memories, false);
        }
        return false;
    }

    public static function get_all_memories(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $memories = get_option(self::SITE_MEMORY_KEY, []);
        $cache = is_array($memories) ? $memories : [];
        return $cache;
    }

    public static function clear_memory_cache(): void {
        static $cache = null;
        $cache = null;
    }

    public static function get_site_structure(): array {
        $site_mem = self::get_memory('site_structure', 'main');
        return !empty($site_mem['value']) ? $site_mem['value'] : [];
    }

    public static function update_site_structure(array $structure): bool {
        return self::save_memory('site_structure', 'main', $structure);
    }

    public static function get_user_preferences(): array {
        $patterns = get_option(self::PATTERNS_MEMORY_KEY, []);
        return $patterns['user_preferences'] ?? [];
    }

    public static function save_user_preference(string $key, $value): bool {
        $patterns = get_option(self::PATTERNS_MEMORY_KEY, []);
        if (!is_array($patterns)) {
            $patterns = ['version' => '1.0', 'user_preferences' => []];
        }
        if (!isset($patterns['user_preferences'])) {
            $patterns['user_preferences'] = [];
        }
        $patterns['user_preferences'][$key] = $value;
        return update_option(self::PATTERNS_MEMORY_KEY, $patterns, false);
    }

    public static function get_learned_patterns(): array {
        $patterns = get_option(self::PATTERNS_MEMORY_KEY, []);
        return $patterns;
    }

    public static function add_learned_solution(string $problem, string $solution): bool {
        $patterns = get_option(self::PATTERNS_MEMORY_KEY, []);
        if (!is_array($patterns)) {
            $patterns = ['version' => '1.0', 'user_preferences' => [], 'learned_solutions' => []];
        }
        if (!isset($patterns['learned_solutions'])) {
            $patterns['learned_solutions'] = [];
        }
        $patterns['learned_solutions'][] = [
            'problem' => $problem,
            'solution' => $solution,
            'timestamp' => current_time('mysql'),
        ];
        if (count($patterns['learned_solutions']) > 50) {
            $patterns['learned_solutions'] = array_slice($patterns['learned_solutions'], -50);
        }
        return update_option(self::PATTERNS_MEMORY_KEY, $patterns, false);
    }

    public static function add_error_history(string $tool, string $error, string $solution = ''): bool {
        $patterns = get_option(self::PATTERNS_MEMORY_KEY, []);
        if (!is_array($patterns)) {
            $patterns = ['version' => '1.0', 'user_preferences' => [], 'error_history' => []];
        }
        if (!isset($patterns['error_history'])) {
            $patterns['error_history'] = [];
        }
        $patterns['error_history'][] = [
            'tool' => $tool,
            'error' => $error,
            'solution' => $solution,
            'timestamp' => current_time('mysql'),
        ];
        if (count($patterns['error_history']) > 30) {
            $patterns['error_history'] = array_slice($patterns['error_history'], -30);
        }
        return update_option(self::PATTERNS_MEMORY_KEY, $patterns, false);
    }

    // === Session Memory ===

    public static function get_session(int $user_id): array {
        $session_id = self::get_session_id($user_id);
        $key = self::SESSION_PREFIX . $user_id . '_' . $session_id;
        $session = get_transient($key);

        if ($session === false) {
            $session = [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'started_at' => current_time('mysql'),
                'last_active' => current_time('mysql'),
                'context' => [],
                'conversation' => [],
            ];
            self::save_session($user_id, $session);
        }
        return $session;
    }

    public static function save_session(int $user_id, array $session): bool {
        $session_id = $session['session_id'] ?? self::get_session_id($user_id);
        $key = self::SESSION_PREFIX . $user_id . '_' . $session_id;
        $session['last_active'] = current_time('mysql');
        return set_transient($key, $session, self::MAX_SESSION_AGE);
    }

    public static function update_session_context(int $user_id, array $context): bool {
        $session = self::get_session($user_id);
        $session['context'] = array_merge($session['context'], $context);
        return self::save_session($user_id, $session);
    }

    public static function clear_session(int $user_id): bool {
        $session_id = self::get_session_id($user_id);
        $key = self::SESSION_PREFIX . $user_id . '_' . $session_id;
        delete_transient($key);
        unset($_SESSION['aiwp_session_id_' . $user_id]);
        return true;
    }

    private static function get_session_id(int $user_id): string {
        if (!session_id()) {
            @session_start();
        }
        $key = 'aiwp_session_id_' . $user_id;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = 'sess_' . uniqid('', true);
        }
        return $_SESSION[$key];
    }

    // === Conversation History (per-user) ===

    public static function get_history(int $user_id): array {
        $key = self::HISTORY_PREFIX . $user_id;
        $history = get_option($key, []);
        return is_array($history) ? $history : [];
    }

    public static function add_to_history(int $user_id, string $user_msg, string $assistant_msg, array $tool_calls = [], string $conversation_id = ''): bool {
        $history = self::get_history($user_id);
        $history[] = [
            'user' => $user_msg,
            'assistant' => $assistant_msg,
            'tool_calls' => $tool_calls,
            'conversation_id' => $conversation_id,
            'timestamp' => current_time('mysql'),
        ];
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }
        return update_option(self::HISTORY_PREFIX . $user_id, $history, false);
    }

    public static function clear_history(int $user_id): bool {
        return delete_option(self::HISTORY_PREFIX . $user_id);
    }

    // === Per-User Local Preferences ===

    public static function get_user_local_prefs(int $user_id): array {
        $key = self::USER_PREFS_PREFIX . $user_id;
        $prefs = get_option($key, []);
        return is_array($prefs) ? $prefs : [];
    }

    public static function save_user_local_pref(int $user_id, string $key, $value): bool {
        $prefs = self::get_user_local_prefs($user_id);
        $prefs[$key] = $value;
        return update_option(self::USER_PREFS_PREFIX . $user_id, $prefs, false);
    }
}
