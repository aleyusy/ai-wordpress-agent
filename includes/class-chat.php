<?php

defined('ABSPATH') || exit;

class AIWP_Chat {
    const SESSION_KEY = 'aiwp_chat_history';
    const MAX_HISTORY = 100;
    const MAX_ITERATIONS = 15;

    public static function handle_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        $message = isset($_POST['message']) ? wp_kses_post($_POST['message']) : '';
        $reset = !empty($_POST['reset']);

        if (empty($message) && !$reset) {
            wp_send_json_error(['message' => 'Empty message']);
        }

        if ($reset) {
            self::clear_history();
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

        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        $history = self::get_history();
        wp_send_json_success(['history' => $history]);
    }

    public static function clear_history_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        self::clear_history();
        wp_send_json_success(['message' => 'History cleared']);
    }

    private static function process_message(string $user_message): array {
        $ai = aiwp()->get_ai();
        $tools_manager = aiwp()->get_tools_manager();
        $rerun = false;

        $history = self::get_history();
        $conversation_id = self::get_conversation_id();

        $messages = self::build_messages($history, $user_message);

        $tool_calls_made = [];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $tools = ($i === 0 || $rerun) ? $tools_manager->get_for_ai() : [];

            $response = $ai->chat($messages, $tools);

            if (!empty($response['content'])) {
                if ($rerun) {
                    $rerun = false;
                }
                if (empty($response['tool_calls']) && !empty($tool_calls_made)) {
                    self::save_to_history($user_message, $response['content'], $tool_calls_made, $conversation_id);
                    return [
                        'message' => $response['content'],
                        'tool_calls' => $tool_calls_made,
                    ];
                }
                if (empty($response['tool_calls']) && empty($tool_calls_made) && self::looks_like_hallucination($response['content'])) {
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Ты ответил текстом, но не использовал ни один инструмент. Если пользователь попросил выполнить действие — ОБЯЗАТЕЛЬНО вызови соответствующий инструмент. Не пиши что "сделал" — реально вызови инструмент. Используй доступные tools.',
                    ];
                    $rerun = true;
                    continue;
                }
                self::save_to_history($user_message, $response['content'], $tool_calls_made, $conversation_id);
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
                    $result = $tools_manager->execute($tc['name'], $tc['arguments']);

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
                self::save_to_history($user_message, $text, $tool_calls_made, $conversation_id);
                return [
                    'message' => $text,
                    'tool_calls' => $tool_calls_made,
                ];
            }
        }

        self::save_to_history($user_message, '⚠️ Достигнут лимит итераций. Попробуйте упростить запрос.', $tool_calls_made, $conversation_id);
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
        $text_lower = mb_strtolower($text);
        foreach ($action_words as $word) {
            if (mb_strpos($text_lower, mb_strtolower($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function build_messages(array $history, string $user_message): array {
        $system_prompt = self::get_system_prompt();
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
        ];

        foreach ($history as $entry) {
            $messages[] = ['role' => 'user', 'content' => $entry['user']];
            $messages[] = ['role' => 'assistant', 'content' => $entry['assistant']];
        }

        $messages[] = ['role' => 'user', 'content' => $user_message];

        return $messages;
    }

    private static function get_system_prompt(): string {
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $site_description = get_bloginfo('description');
        $tools_list = aiwp()->get_tools_manager()->get_tools_list();
        $tools_desc = '';
        foreach ($tools_list as $tool) {
            $tools_desc .= "- **{$tool['name']}**: {$tool['description']}\n";
        }

        return <<<PROMPT
Ты — AI-агент для управления WordPress. Твоя задача — выполнять действия на сайте через вызов инструментов (functions/tools).

## ИНФОРМАЦИЯ О САЙТЕ
- Название: {$site_name}
- URL: {$site_url}
- Описание: {$site_description}

## ДОСТУПНЫЕ ИНСТРУМЕНТЫ
Ты имеешь полный доступ к управлению WordPress через функции (tool calls):
{$tools_desc}

## КРИТИЧЕСКИ ВАЖНОЕ ПРАВИЛО
Ты НЕ ИМЕЕШЬ ПРАВА просто написать "я сделал это". Любое действие должно быть выполнено через вызов соответствующей функции (tool_call). Если ты не вызвал функцию — действие не выполнено.

Правильный алгоритм работы:
1. Получить запрос от пользователя
2. Вызвать нужные инструменты (один или несколько)
3. Дождаться результата выполнения
4. Сообщить пользователю о результате со ссылками и ID

НЕПРАВИЛЬНО (так НЕЛЬЗЯ):
❌ "Я создал страницу" — без вызова wp_create_page
❌ "Я установил плагин" — без вызова wp_install_plugin
❌ Добавил, обновил, удалил, настроил — без вызова соответствующих функций

ПРАВИЛЬНО (только так):
✅ Вызвать wp_create_page с параметрами → получить результат → сообщить пользователю
✅ Вызвать wp_install_plugin → получить результат → сообщить пользователю

## ПРАВИЛА РАБОТЫ
1. **Только через инструменты** — ни одного действия без вызова функции
2. **Объясняй результат** — после выполнения покажи что получилось
3. **Проверяй перед удалением** — спрашивай подтверждение
4. **Ссылки** — всегда показывай URL созданных страниц/постов
5. **Группировка** — несколько действий можно выполнить за один раз

## ФОРМАТ ОТВЕТА
- Дружелюбный тон
- Показывай ID и ссылки на созданные объекты
- Если ошибка — объясни причину
- После цепочки действий подведи краткий итог

## ПРИМЕРЫ ЗАПРОСОВ
- "Создай страницу 'О нас' с описанием компании"
- "Установи плагин Contact Form 7 и активируй его"
- "Поменяй тему на Twenty Twenty-Four"
- "Добавь виджет поиска в сайдбар"
- "Создай меню 'Главное' и добавь в него страницы"
- "Сделай страницу 'Главная' домашней страницей"
- "Переименуй термин с ID 14 в таксономии destination на 'Грунина'"

Помни: ТЫ ДОЛЖЕН ВЫЗВАТЬ ФУНКЦИЮ чтобы выполнить действие. Просто написать "я сделал" недостаточно.
PROMPT;
    }

    private static function get_history(): array {
        $history = get_option(self::SESSION_KEY, []);
        return is_array($history) ? $history : [];
    }

    private static function save_to_history(string $user, string $assistant, array $tool_calls, string $conversation_id) {
        $history = self::get_history();
        $history[] = [
            'user' => $user,
            'assistant' => $assistant,
            'tool_calls' => $tool_calls,
            'conversation_id' => $conversation_id,
            'timestamp' => current_time('mysql'),
        ];
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }
        update_option(self::SESSION_KEY, $history);
    }

    public static function clear_history() {
        delete_option(self::SESSION_KEY);
    }

    private static function get_conversation_id(): string {
        if (!session_id()) {
            session_start();
        }
        if (empty($_SESSION['aiwp_conversation_id'])) {
            $_SESSION['aiwp_conversation_id'] = uniqid('conv_', true);
        }
        return $_SESSION['aiwp_conversation_id'];
    }
}
