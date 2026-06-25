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

        $needs_tools = self::message_needs_tools($user_message);
        $active_tools = $needs_tools ? $tools_manager->get_for_ai_filtered($user_message) : [];
        $active_tools = AIWP_Roles::get_available_tools($active_tools);
        $valid_tool_names = array_map(function ($t) {
            return $t['function']['name'] ?? $t['name'] ?? '';
        }, $available_tools);

        $messages = self::build_messages($history, $user_message, $user_id);

        $tool_calls_made = [];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $has_tool_results = false;
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'tool') {
                    $has_tool_results = true;
                    break;
                }
            }
            $tools = ($i === 0 || $rerun || $has_tool_results) ? $active_tools : [];

            if ($i > 0) {
                sleep(3);
            }

            $response = $ai->chat($messages, $tools, $rerun);

            $is_api_error = !empty($response['content']) && strpos($response['content'], '⚠️ Ошибка') === 0;

            if ($is_api_error && !empty($tool_calls_made) && empty($response['tool_calls'])) {
                $summary = self::format_tool_results($tool_calls_made);
                $tool_names = array_map(fn($tc) => $tc['tool'], $tool_calls_made);
                $summary .= "\n\n⚠️ AI не смог завершить задачу из-за ограничений API. Выполнены только: " . implode(', ', $tool_names);
                $summary .= "\nПовторите запрос: \"Продолжи — " . $user_message . "\"";
                AIWP_Memory::add_to_history($user_id, $user_message, $summary, $tool_calls_made, $conversation_id);
                return [
                    'message' => $summary,
                    'tool_calls' => $tool_calls_made,
                ];
            }

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
                    $tools_hint = self::build_tools_hint($valid_tool_names);
                    $messages[] = [
                        'role' => 'system',
                        'content' => "ТЫ ДОЛЖЕН ВЫЗВАТЬ ФУНКЦИЮ. Используй ТОЛЬКО эти инструменты:\n{$tools_hint}\nНЕ придумывай имена. Вызови нужный tool call.",
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
                $hallucinated = [];
                foreach ($response['tool_calls'] as $tc) {
                    if (!in_array($tc['name'], $valid_tool_names, true)) {
                        $hallucinated[] = $tc['name'];
                    }
                }

                if (!empty($hallucinated)) {
                    $bad_names = implode(', ', $hallucinated);
                    $tools_hint = self::build_tools_hint($valid_tool_names);
                    $messages[] = [
                        'role' => 'system',
                        'content' => "ОШИБКА: Ты вызвал несуществующие инструменты: {$bad_names}.\nДоступные инструменты ТОЛЬКО:\n{$tools_hint}\nПовтори запрос, используя ПРАВИЛЬНЫЕ имена инструментов из списка выше.",
                    ];
                    $rerun = true;
                    continue;
                }

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

    private static function message_needs_tools(string $message): bool {
        $lower = mb_strtolower($message);
        $action_words = [
            'создай', 'создать', 'создании', 'создание',
            'удали', 'удалить', 'удаление',
            'обнови', 'обновить', 'обновление',
            'установи', 'установить', 'установка',
            'активируй', 'деактивируй',
            'поменяй', 'измени', 'настрой',
            'добавь', 'добавить',
            'загрузи', 'выгрузи',
            'покажи', 'показать', 'список',
            'найди', 'найти', 'поиск',
            'запусти', 'запустить',
            'вызови', 'выполнить',
            'отредактируй', 'редактировать',
            'примени', 'применить',
            'сохрани', 'сохранить',
            'включи', 'выключи',
            'сделай', 'сделать',
            'create', 'delete', 'update', 'install',
            'activate', 'deactivate', 'switch',
            'add', 'remove', 'set', 'get',
            'show', 'list', 'find', 'search',
            'run', 'execute', 'edit', 'apply',
            'save', 'enable', 'disable',
            'make', 'build', 'setup',
        ];
        foreach ($action_words as $word) {
            if (mb_strpos($lower, $word) !== false) {
                return true;
            }
        }
        return false;
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

    private static function build_tools_hint(array $valid_tool_names): string {
        $hint = '';
        foreach ($valid_tool_names as $name) {
            $hint .= "- {$name}\n";
        }
        return $hint;
    }

    private static function format_tool_results(array $tool_calls_made): string {
        $parts = [];
        foreach ($tool_calls_made as $tc) {
            $name = $tc['tool'];
            $result = $tc['result'];
            if (!empty($result['success'])) {
                $detail = '';
                $args = $tc['arguments'] ?? [];
                if ($name === 'wp_create_page' && !empty($result['page_id'])) {
                    $detail = "ID: {$result['page_id']}, URL: {$result['url']}";
                } elseif ($name === 'wp_create_post' && !empty($result['post_id'])) {
                    $detail = "ID: {$result['post_id']}, URL: {$result['url']}";
                } elseif ($name === 'wp_get_site_info' && !empty($result['info'])) {
                    $detail = "Сайт: {$result['info']['site_name']}, Тема: {$result['info']['active_theme']}";
                } elseif ($name === 'wp_create_menu' && !empty($result['menu_id'])) {
                    $detail = "ID: {$result['menu_id']}";
                } elseif ($name === 'wp_add_custom_css') {
                    $css_len = strlen($args['css'] ?? $result['css'] ?? '');
                    $detail = "Добавлено {$css_len} символов CSS. " . ($result['message'] ?? '');
                } elseif ($name === 'wp_get_custom_css') {
                    $css_len = $result['length'] ?? strlen($result['css'] ?? '');
                    $detail = $css_len > 0 ? "{$css_len} символов CSS" : "Кастомный CSS отсутствует";
                } elseif ($name === 'wp_switch_theme') {
                    $detail = $result['message'] ?? 'Тема переключена';
                } elseif ($name === 'wp_install_plugin') {
                    $detail = $result['message'] ?? 'Плагин установлен';
                } elseif ($name === 'wp_add_widget') {
                    $detail = $result['message'] ?? 'Виджет добавлен';
                } elseif ($name === 'wp_set_theme_mod') {
                    $detail = $result['message'] ?? 'Настройка темы изменена';
                } elseif ($name === 'aiwp_analyze_site') {
                    $detail = 'Анализ запущен';
                } elseif ($name === 'aiwp_save_memory') {
                    $detail = 'Сохранено в память';
                } elseif ($name === 'aiwp_save_skill') {
                    $detail = 'Скилл сохранён';
                } elseif ($name === 'wp_update_page') {
                    $detail = "Страница {$result['page_id']} обновлена";
                } elseif ($name === 'wp_update_post') {
                    $detail = "Пост {$result['post_id']} обновлён";
                } elseif (!empty($result['message'])) {
                    $detail = $result['message'];
                } elseif (!empty($result['pages'])) {
                    $detail = count($result['pages']) . ' страниц(ы)';
                } elseif (!empty($result['posts'])) {
                    $detail = count($result['posts']) . ' пост(ов)';
                } elseif (!empty($result['plugins'])) {
                    $detail = count($result['plugins']) . ' плагин(ов)';
                } elseif (!empty($result['themes'])) {
                    $detail = count($result['themes']) . ' тем(ы)';
                } elseif (!empty($result['users'])) {
                    $detail = count($result['users']) . ' пользователей';
                } elseif (!empty($result['menus'])) {
                    $detail = count($result['menus']) . ' меню';
                } elseif (!empty($result['categories'])) {
                    $detail = count($result['categories']) . ' категорий';
                } elseif (!empty($result['tags'])) {
                    $detail = count($result['tags']) . ' тег(ов)';
                } elseif (!empty($result['media'])) {
                    $detail = count($result['media']) . ' файл(ов)';
                } elseif (!empty($result['skills'])) {
                    $detail = count($result['skills']) . ' скилл(ов)';
                } elseif (!empty($result['roles'])) {
                    $detail = count($result['roles']) . ' ролей';
                } elseif (!empty($result['sidebars'])) {
                    $detail = count($result['sidebars']) . ' сайдбар(ов)';
                } elseif (!empty($result['post_types'])) {
                    $detail = count($result['post_types']) . ' типов записей';
                } elseif (!empty($result['taxonomies'])) {
                    $detail = count($result['taxonomies']) . ' таксономий';
                }
                $parts[] = "✅ **{$name}**: {$detail}";
            } else {
                $error = $result['error'] ?? 'Неизвестная ошибка';
                $parts[] = "❌ **{$name}**: {$error}";
            }
        }
        return implode("\n", $parts);
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
        $site_url = get_bloginfo('url');
        $site_description = get_bloginfo('description');

        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : 'Unknown';
        $user_roles = $user ? implode(', ', $user->roles) : 'none';

        $caps = AIWP_Roles::get_user_capabilities($user_id);
        $granted = array_filter($caps, fn($c) => $c['granted']);
        $caps_list = !empty($granted) ? implode(', ', array_keys($granted)) : 'none';

        $prompt = "Ты — AI-агент для управления WordPress на сайте \"{$site_name}\" ({$site_url}).\n";
        $prompt .= "Пользователь: {$user_name} (ID: {$user_id}), роль: {$user_roles}\n";
        $prompt .= "Его возможности: {$caps_list}\n\n";

        $analysis = AIWP_Analyzer::get_analysis();
        if ($analysis) {
            $prompt .= "## АНАЛИЗ САЙТА ({$analysis['last_analysis']})\n";
            $prompt .= "Общий балл: {$analysis['overall_score']}/100\n";
            $active_theme = $analysis['themes']['active']['name'] ?? 'unknown';
            $prompt .= "Тема: {$active_theme}\n";
            $active_count = $analysis['plugins']['active'] ?? 0;
            $inactive_count = $analysis['plugins']['inactive'] ?? 0;
            $prompt .= "Плагины: {$active_count} активных, {$inactive_count} неактивных\n";
            $sec_score = $analysis['security']['score'] ?? 0;
            $perf_score = $analysis['performance']['score'] ?? 0;
            $seo_score = $analysis['seo']['score'] ?? 0;
            $prompt .= "Безопасность: {$sec_score}/100, Скорость: {$perf_score}/100, SEO: {$seo_score}/100\n";

            $all_issues = array_merge(
                $analysis['security']['issues'] ?? [],
                $analysis['performance']['issues'] ?? [],
                $analysis['seo']['issues'] ?? []
            );
            if (!empty($all_issues)) {
                $prompt .= "Проблемы:\n";
                foreach (array_slice($all_issues, 0, 5) as $issue) {
                    $severity = $issue['severity'] ?? $issue['type'] ?? 'info';
                    $msg = $issue['message'] ?? '';
                    $prompt .= "- [{$severity}] {$msg}\n";
                }
            }
            $prompt .= "\n";
        }

        $structure = AIWP_Memory::get_site_structure();
        if (!empty($structure)) {
            $prompt .= "## СТРУКТУРА САЙТА (из памяти)\n";
            if (!empty($structure['pages'])) {
                $page_names = array_column($structure['pages'], 'title');
                $prompt .= "Страницы: " . implode(', ', array_slice($page_names, 0, 15)) . "\n";
            }
            if (!empty($structure['menus'])) {
                $prompt .= "Меню: " . implode(', ', array_keys($structure['menus'])) . "\n";
            }
            $prompt .= "\n";
        }

        $prefs = AIWP_Memory::get_user_preferences();
        if (!empty($prefs)) {
            $prompt .= "## ПРЕДПОЧТЕНИЯ\n";
            foreach ($prefs as $key => $value) {
                $prompt .= "- {$key}: {$value}\n";
            }
            $prompt .= "\n";
        }

        $skills = AIWP_Skills::list_skills();
        if (!empty($skills)) {
            $prompt .= "## ДОСТУПНЫЕ СКИЛЛЫ\n";
            foreach ($skills as $slug => $skill) {
                $prompt .= "- {$slug}: {$skill['description']} (категория: {$skill['category']})\n";
            }
            $prompt .= "\n";
        }

        $session = AIWP_Memory::get_session($user_id);
        if (!empty($session['context'])) {
            $prompt .= "## КОНТЕКСТ СЕССИИ\n";
            if (!empty($session['context']['working_on'])) {
                $prompt .= "Текущая задача: {$session['context']['working_on']}\n";
            }
            if (!empty($session['context']['current_page'])) {
                $prompt .= "Текущая страница ID: {$session['context']['current_page']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## КРИТИЧЕСКИ ВАЖНОЕ ПРАВИЛО\n";
        $prompt .= "ТЫ НЕ ИМЕЕШЬ ПРАВА писать 'я сделал это'. Любое действие — через tool_call.\n";
        $prompt .= "Если не вызвал функцию — действие не выполнено.\n\n";

        $prompt .= "## АЛГОРИТМ\n";
        $prompt .= "1. Получить запрос\n";
        $prompt .= "2. Вызвать инструменты (tool calls)\n";
        $prompt .= "3. Дождаться результата\n";
        $prompt .= "4. Сообщить результат со ссылками и ID\n\n";

        $prompt .= "## ПРАВИЛА\n";
        $prompt .= "1. Только через инструменты\n";
        $prompt .= "2. Объясняй результат\n";
        $prompt .= "3. Проверяй перед удалением\n";
        $prompt .= "4. Показывай URL созданных объектов\n";
        $prompt .= "5. Используй скиллы когда они подходят\n";
        $prompt .= "6. Обновляй память сайта при значительных изменениях\n";
        $prompt .= "7. Проверяй capabilities пользователя перед ограничеными действиями\n";
        $prompt .= "8. CSS ТОЛЬКО через wp_add_custom_css. Контент страниц — ТОЛЬКО HTML без <style>.\n";
        $prompt .= "9. JavaScript — через <script> теги в контенте (они сохраняются).\n";
        $prompt .= "10. При создании страницы с дизайном: сначала wp_create_page с HTML, потом wp_add_custom_css со стилями.\n\n";

        $prompt .= "## ФОРМАТ ОТВЕТА\n";
        $prompt .= "- Дружелюбный тон\n";
        $prompt .= "- ID и ссылки на созданные объекты\n";
        $prompt .= "- При ошибке — объясни причину\n";
        $prompt .= "- После цепочки действий — краткий итог\n";

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
