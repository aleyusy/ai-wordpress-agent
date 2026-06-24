<?php

defined('ABSPATH') || exit;

class AIWP_Chat {
    const MAX_HISTORY = 100;
    const MAX_ITERATIONS = 15;

    public static function handle_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die('Forbidden', 403);
        }

        $message = isset($_POST['message']) ? wp_kses_post($_POST['message']) : '';
        $reset = !empty($_POST['reset']);

        if (empty($message) && !$reset) {
            wp_send_json_error(['message' => 'Empty message']);
        }

        if ($reset) {
            $user_id = get_current_user_id();
            AIWP_Memory::clear_history($user_id);
            AIWP_Memory::clear_session($user_id);
            wp_send_json_success(['message' => 'History cleared. How can I help you?', 'reset' => true]);
        }

        try {
            $response = self::process_message($message);
            wp_send_json_success($response);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public static function get_history_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die('Forbidden', 403);
        }

        $user_id = get_current_user_id();
        $history = AIWP_Memory::get_history($user_id);
        wp_send_json_success(['history' => $history]);
    }

    public static function clear_history_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die('Forbidden', 403);
        }

        $user_id = get_current_user_id();
        AIWP_Memory::clear_history($user_id);
        AIWP_Memory::clear_session($user_id);
        wp_send_json_success(['message' => 'History cleared']);
    }

    private static function process_message(string $user_message): array {
        $ai = aiwp()->get_ai();
        $tools_manager = aiwp()->get_tools_manager();
        $user_id = get_current_user_id();
        $rerun = false;

        $history = AIWP_Memory::get_history($user_id);
        $conversation_id = self::get_conversation_id();

        $all_tools = $tools_manager->get_for_ai();
        $available_tools = AIWP_Roles::get_available_tools($all_tools);

        $messages = self::build_messages($history, $user_message, $user_id);

        $tool_calls_made = [];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $tools = ($i === 0 || $rerun) ? $available_tools : [];

            $response = $ai->chat($messages, $tools, $rerun);

            if (!empty($response['content'])) {
                if ($rerun) {
                    $rerun = false;
                }
                if (empty($response['tool_calls']) && !empty($tool_calls_made)) {
                    AIWP_Memory::add_to_history($user_id, $user_message, $response['content'], $tool_calls_made, $conversation_id);
                    return [
                        'message' => $response['content'],
                        'tool_calls' => $tool_calls_made,
                    ];
                }
                if (empty($response['tool_calls']) && empty($tool_calls_made) && self::looks_like_hallucination($response['content'])) {
                    $tools_names = array_map(function ($t) { return $t['name']; }, $available_tools);
                    $tools_hint = 'Доступные инструменты: ' . implode(', ', array_slice($tools_names, 0, 10)) . '...';
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'ТЫ ДОЛЖЕН ВЫЗВАТЬ ФУНКЦИЮ. Пользователь просит ВЫПОЛНИТЬ действие. Вызови нужный tool call. НЕ пиши инструкции. ' . $tools_hint,
                    ];
                    $rerun = true;
                    continue;
                }
                AIWP_Memory::add_to_history($user_id, $user_message, $response['content'], $tool_calls_made, $conversation_id);
                return [
                    'message' => $response['content'],
                    'tool_calls' => $tool_calls_made,
                ];
            }

            if (!empty($response['tool_calls'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => array_map(function ($tc) {
                        return [
                            'id' => $tc['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'],
                                'arguments' => json_encode($tc['arguments']),
                            ],
                        ];
                    }, $response['tool_calls']),
                ];

                foreach ($response['tool_calls'] as $tc) {
                    if (!AIWP_Roles::can_use_tool($tc['name'])) {
                        $result = ['success' => false, 'error' => 'Permission denied for this tool.'];
                    } else {
                        $result = $tools_manager->execute($tc['name'], $tc['arguments']);
                    }

                    $tool_calls_made[] = [
                        'tool' => $tc['name'],
                        'arguments' => $tc['arguments'],
                        'result' => $result,
                    ];

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($result),
                    ];
                }
            } else {
                $text = $response['content'] ?? 'No response generated.';
                AIWP_Memory::add_to_history($user_id, $user_message, $text, $tool_calls_made, $conversation_id);
                return [
                    'message' => $text,
                    'tool_calls' => $tool_calls_made,
                ];
            }
        }

        AIWP_Memory::add_to_history($user_id, $user_message, '⚠️ Max iterations reached.', $tool_calls_made, $conversation_id);
        return [
            'message' => '⚠️ Достигнут лимит итераций. Попробуйте упростить запрос.',
            'tool_calls' => $tool_calls_made,
        ];
    }

    private static function looks_like_hallucination(string $text): bool {
        $action_words = [
            'создал', 'создана', 'создан', 'создано',
            'обновил', 'обновлён', 'обновлена', 'обновлено',
            'установил', 'установлен', 'установлена',
            'добавил', 'добавлен', 'добавлена', 'добавлено',
            'удалил', 'удалён', 'удалена',
            'изменил', 'изменён', 'изменена',
            'активировал', 'активирован', 'деактивировал',
            'настроил', 'настроен',
            'поменял', 'заменил',
            'загрузил', 'загружен',
            'сделал', 'выполнил',
            'created', 'updated', 'installed', 'added', 'deleted',
            'activated', 'deactivated', 'changed', 'modified',
        ];
        $instructional = [
            'перейдите', 'нажмите', 'введите', 'в поле', 'откройте',
            'зайдите', 'пропишите', 'сохраните', 'выберите',
            'go to', 'click', 'navigate', 'open your browser',
            'откройте браузер', 'в консоли',
            'административную страницу', 'admin page',
        ];
        $text_lower = mb_strtolower($text);
        foreach ($action_words as $word) {
            if (mb_strpos($text_lower, mb_strtolower($word)) !== false) {
                return true;
            }
        }
        foreach ($instructional as $word) {
            if (mb_strpos($text_lower, mb_strtolower($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function build_messages(array $history, string $user_message, int $user_id): array {
        $system_prompt = self::get_system_prompt($user_id);
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
        ];

        $recent = array_slice($history, -20);
        foreach ($recent as $entry) {
            $messages[] = ['role' => 'user', 'content' => $entry['user']];
            $messages[] = ['role' => 'assistant', 'content' => $entry['assistant']];
        }

        $messages[] = ['role' => 'user', 'content' => $user_message];

        return $messages;
    }

    private static function get_system_prompt(int $user_id): string {
        $site_name = get_bloginfo('name');
        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : 'Unknown';
        $user_roles = $user ? implode(', ', $user->roles) : 'none';

        $prompt = "AI agent for WordPress site \"{$site_name}\". User: {$user_name} ({$user_roles}).\n";
        $prompt .= "RULES: Only use tool_calls for actions. Never claim done without calling function. Show IDs/URLs in results.\n";

        $analysis = AIWP_Analyzer::get_analysis();
        if ($analysis) {
            $theme = $analysis['themes']['active']['name'] ?? '';
            $sec = $analysis['security']['score'] ?? 0;
            $perf = $analysis['performance']['score'] ?? 0;
            $seo = $analysis['seo']['score'] ?? 0;
            $prompt .= "Site: theme={$theme}, security={$sec}/100, perf={$perf}/100, seo={$seo}/100. ";
        }

        $prefs = AIWP_Memory::get_user_preferences();
        if (!empty($prefs)) {
            $prompt .= "Prefs: " . implode(', ', array_map(fn($k,$v) => "$k=$v", array_keys($prefs), $prefs)) . ". ";
        }

        $skills = AIWP_Skills::list_skills();
        if (!empty($skills)) {
            $prompt .= "Skills: " . implode(', ', array_keys($skills)) . ". ";
        }

        return $prompt;
    }

    private static function get_conversation_id(): string {
        if (!session_id()) {
            @session_start();
        }
        if (empty($_SESSION['aiwp_conversation_id'])) {
            $_SESSION['aiwp_conversation_id'] = uniqid('conv_', true);
        }
        return $_SESSION['aiwp_conversation_id'];
    }
}
