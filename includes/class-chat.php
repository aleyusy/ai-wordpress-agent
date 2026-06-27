<?php

defined('ABSPATH') || exit;

class AIWP_Chat {
    const MAX_HISTORY = 100;
    const MAX_ITERATIONS = 15;

    public static function handle_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die(__('Forbidden', 'aiwp'), 403);
        }

        $message = isset($_POST['message']) ? wp_kses_post($_POST['message']) : '';
        $reset = !empty($_POST['reset']);

        if (empty($message) && !$reset) {
            wp_send_json_error(['message' => __('Empty message', 'aiwp')]);
        }

        if ($reset) {
            $user_id = get_current_user_id();
            AIWP_Memory::clear_history($user_id);
            AIWP_Memory::clear_session($user_id);
            wp_send_json_success(['message' => __('History cleared. How can I help you?', 'aiwp'), 'reset' => true]);
        }

        try {
            $response = self::process_message($message);
            wp_send_json_success($response);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => __('Error: ', 'aiwp') . $e->getMessage()]);
        }
    }

    public static function get_history_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die(__('Forbidden', 'aiwp'), 403);
        }

        $user_id = get_current_user_id();
        $history = AIWP_Memory::get_history($user_id);
        wp_send_json_success(['history' => $history]);
    }

    public static function clear_history_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!AIWP_Roles::user_has_capability('aiwp_use_chat')) {
            wp_die(__('Forbidden', 'aiwp'), 403);
        }

        $user_id = get_current_user_id();
        AIWP_Memory::clear_history($user_id);
        AIWP_Memory::clear_session($user_id);
        wp_send_json_success(['message' => __('History cleared', 'aiwp')]);
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
                $summary .= "\n\n" . sprintf(__('⚠️ AI не смог завершить задачу из-за ограничений API. Выполнены только: %s', 'aiwp'), implode(', ', $tool_names));
                $summary .= "\n" . sprintf(__('Повторите запрос: "Продолжи — %s"', 'aiwp'), $user_message);
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
                        $result = ['success' => false, 'error' => __('Permission denied for this tool.', 'aiwp')];
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
                $text = $response['content'] ?? __('No response generated.', 'aiwp');
                AIWP_Memory::add_to_history($user_id, $user_message, $text, $tool_calls_made, $conversation_id);
                return [
                    'message' => $text,
                    'tool_calls' => $tool_calls_made,
                ];
            }
        }

        AIWP_Memory::add_to_history($user_id, $user_message, __('⚠️ Max iterations reached.', 'aiwp'), $tool_calls_made, $conversation_id);
        return [
            'message' => __('⚠️ Достигнут лимит итераций. Попробуйте упростить запрос.', 'aiwp'),
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
                    $detail = sprintf(__('ID: %s, URL: %s', 'aiwp'), $result['page_id'], $result['url']);
                } elseif ($name === 'wp_create_post' && !empty($result['post_id'])) {
                    $detail = sprintf(__('ID: %s, URL: %s', 'aiwp'), $result['post_id'], $result['url']);
                } elseif ($name === 'wp_get_site_info' && !empty($result['info'])) {
                    $detail = sprintf(__('Сайт: %s, Тема: %s', 'aiwp'), $result['info']['site_name'], $result['info']['active_theme']);
                } elseif ($name === 'wp_create_menu' && !empty($result['menu_id'])) {
                    $detail = sprintf(__('ID: %s', 'aiwp'), $result['menu_id']);
                } elseif ($name === 'wp_add_custom_css') {
                    $css_len = strlen($args['css'] ?? $result['css'] ?? '');
                    $detail = sprintf(__('Добавлено %d символов CSS.', 'aiwp'), $css_len) . ' ' . ($result['message'] ?? '');
                } elseif ($name === 'wp_get_custom_css') {
                    $css_len = $result['length'] ?? strlen($result['css'] ?? '');
                    $detail = $css_len > 0 ? sprintf(__('%d символов CSS', 'aiwp'), $css_len) : __('Кастомный CSS отсутствует', 'aiwp');
                } elseif ($name === 'wp_switch_theme') {
                    $detail = $result['message'] ?? __('Тема переключена', 'aiwp');
                } elseif ($name === 'wp_install_plugin') {
                    $detail = $result['message'] ?? __('Плагин установлен', 'aiwp');
                } elseif ($name === 'wp_add_widget') {
                    $detail = $result['message'] ?? __('Виджет добавлен', 'aiwp');
                } elseif ($name === 'wp_set_theme_mod') {
                    $detail = $result['message'] ?? __('Настройка темы изменена', 'aiwp');
                } elseif ($name === 'aiwp_analyze_site') {
                    $detail = __('Анализ запущен', 'aiwp');
                } elseif ($name === 'aiwp_save_memory') {
                    $detail = __('Сохранено в память', 'aiwp');
                } elseif ($name === 'aiwp_save_skill') {
                    $detail = __('Скилл сохранён', 'aiwp');
                } elseif ($name === 'wp_update_page') {
                    $detail = sprintf(__('Страница %s обновлена', 'aiwp'), $result['page_id']);
                } elseif ($name === 'wp_update_post') {
                    $detail = sprintf(__('Пост %s обновлён', 'aiwp'), $result['post_id']);
                } elseif (!empty($result['message'])) {
                    $detail = $result['message'];
                } elseif (!empty($result['pages'])) {
                    $detail = sprintf(__('%d страниц(ы)', 'aiwp'), count($result['pages']));
                } elseif (!empty($result['posts'])) {
                    $detail = sprintf(__('%d пост(ов)', 'aiwp'), count($result['posts']));
                } elseif (!empty($result['plugins'])) {
                    $detail = sprintf(__('%d плагин(ов)', 'aiwp'), count($result['plugins']));
                } elseif (!empty($result['themes'])) {
                    $detail = sprintf(__('%d тем(ы)', 'aiwp'), count($result['themes']));
                } elseif (!empty($result['users'])) {
                    $detail = sprintf(__('%d пользователей', 'aiwp'), count($result['users']));
                } elseif (!empty($result['menus'])) {
                    $detail = sprintf(__('%d меню', 'aiwp'), count($result['menus']));
                } elseif (!empty($result['categories'])) {
                    $detail = sprintf(__('%d категорий', 'aiwp'), count($result['categories']));
                } elseif (!empty($result['tags'])) {
                    $detail = sprintf(__('%d тег(ов)', 'aiwp'), count($result['tags']));
                } elseif (!empty($result['media'])) {
                    $detail = sprintf(__('%d файл(ов)', 'aiwp'), count($result['media']));
                } elseif (!empty($result['skills'])) {
                    $detail = sprintf(__('%d скилл(ов)', 'aiwp'), count($result['skills']));
                } elseif (!empty($result['roles'])) {
                    $detail = sprintf(__('%d ролей', 'aiwp'), count($result['roles']));
                } elseif (!empty($result['sidebars'])) {
                    $detail = sprintf(__('%d сайдбар(ов)', 'aiwp'), count($result['sidebars']));
                } elseif (!empty($result['post_types'])) {
                    $detail = sprintf(__('%d типов записей', 'aiwp'), count($result['post_types']));
                } elseif (!empty($result['taxonomies'])) {
                    $detail = sprintf(__('%d таксономий', 'aiwp'), count($result['taxonomies']));
                }
                $parts[] = sprintf(__('✅ **%s**: %s', 'aiwp'), $name, $detail);
            } else {
                $error = $result['error'] ?? __('Неизвестная ошибка', 'aiwp');
                $parts[] = sprintf(__('❌ **%s**: %s', 'aiwp'), $name, $error);
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
        $prompt .= "9. JavaScript — ОБЯЗАТЕЛЬНО в <script> тегах в контенте (они сохраняются).\n";
        $prompt .= "10. При создании страницы с дизайном: сначала wp_create_page с HTML, потом wp_add_custom_css со стилями.\n\n";

        $prompt .= "## СТРОГИЕ ПРАВИЛА HTML В КОНТЕНТЕ СТРАНИЦ\n";
        $prompt .= "Контент передаётся через параметр content инструментов wp_create_page / wp_update_page.\n\n";
        $prompt .= "ЗАПРЕЩЕНО в content:\n";
        $prompt .= "- Тег <title>, <head>, <html>, <body> — они НЕ входят в контент страницы\n";
        $prompt .= "- Стили в <style> тегах — используй wp_add_custom_css отдельно\n";
        $prompt .= "- Атрибуты style=\"...\" на тегах — стили через wp_add_custom_css\n\n";
        $prompt .= "ДОПУСТИМЫЕ теги в content:\n";
        $prompt .= "- Структурные: <section>, <div>, <article>, <header>, <footer>, <nav>, <main>, <aside>\n";
        $prompt .= "- Текстовые: <h1>-<h6>, <p>, <span>, <strong>, <em>, <a>, <br>, <hr>\n";
        $prompt .= "- Списки: <ul>, <ol>, <li>\n";
        $prompt .= "- Медиа: <img>, <video>, <audio>, <iframe>\n";
        $prompt .= "- Таблицы: <table>, <thead>, <tbody>, <tr>, <th>, <td>\n";
        $prompt .= "- Формы: <form>, <input>, <textarea>, <select>, <button>, <label>\n";
        $prompt .= "- CSS-классы: class=\"my-class\" — применяй кастомный CSS через wp_add_custom_css\n";
        $prompt .= "- Data-атрибуты: data-* для JS-обработки\n";
        $prompt .= "- JS: <script>теги — JavaScript выполняется после рендеринга страницы\n\n";
        $prompt .= "ПРИМЕР правильного контента страницы:\n";
        $prompt .= "<!-- HTML контент -->\n";
        $prompt .= "<section class=\"hero\">\n";
        $prompt .= "  <h1 class=\"hero-title\">Заголовок</h1>\n";
        $prompt .= "  <p class=\"hero-text\">Описание</p>\n";
        $prompt .= "  <a href=\"/page\" class=\"btn\">Кнопка</a>\n";
        $prompt .= "</section>\n";
        $prompt .= "<section class=\"features\">\n";
        $prompt .= "  <div class=\"feature-card\">\n";
        $prompt .= "    <h3>Фича 1</h3>\n";
        $prompt .= "    <p>Описание</p>\n";
        $prompt .= "  </div>\n";
        $prompt .= "</section>\n";
        $prompt .= "<script>\n";
        $prompt .= "document.addEventListener('DOMContentLoaded', function() {\n";
        $prompt .= "  // Ваш JavaScript\n";
        $prompt .= "});\n";
        $prompt .= "</script>\n\n";
        $prompt .= "Затем отдельно: wp_add_custom_css с классами .hero, .btn, .feature-card и т.д.\n\n";

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
