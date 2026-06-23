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

        $history = self::get_history();
        $conversation_id = self::get_conversation_id();

        $messages = self::build_messages($history, $user_message);

        $tool_calls_made = [];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $tools = ($i === 0) ? $tools_manager->get_for_ai() : [];

            $response = $ai->chat($messages, $tools);

            if (!empty($response['content'])) {
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
Ты — AI-агент для управления WordPress. Твоя задача — помогать пользователю управлять сайтом через чат.

## ИНФОРМАЦИЯ О САЙТЕ
- Название: {$site_name}
- URL: {$site_url}
- Описание: {$site_description}

## ДОСТУПНЫЕ ИНСТРУМЕНТЫ
Ты имеешь полный доступ к управлению WordPress через инструменты (tools).
{$tools_desc}

## ПРАВИЛА РАБОТЫ
1. **Анализируй запрос** — пойми, что именно нужно пользователю.
2. **Используй инструменты** — для выполнения действий всегда используй соответствующие инструменты, а не просто обещай сделать.
3. **Объясняй результаты** — после выполнения каждого действия кратко сообщай, что сделано.
4. **Проверяй перед удалением** — перед удалением страниц/постов спрашивай подтверждение.
5. **HTML-форматирование** — используй HTML для форматирования контента (h1-h6, p, ul, ol, li, strong, em, a, img).
6. **Активность** — не жди лишних указаний. Если просят "сделай красиво" — используй инструменты для настройки темы, добавления CSS, создания страниц.
7. **Ссылки** — после создания страниц/постов всегда показывай ссылки на них.

## ФОРМАТ ОТВЕТА
- Используй понятный, дружелюбный тон.
- Сообщай ID созданных/изменённых объектов.
- Если что-то пошло не так, объясни причину и предложи решение.
- После выполнения цепочки действий подведи итог.

## ПРИМЕРЫ ЗАПРОСОВ
- "Создай страницу 'О нас' с описанием компании"
- "Установи плагин Contact Form 7 и активируй его"
- "Поменяй тему на Twenty Twenty-Four"
- "Добавь виджет поиска в сайдбар"
- "Создай меню 'Главное' и добавь в него страницы"
- "Сделай страницу 'Главная' домашней страницей"
- "Добавь кастомный CSS: body { background: #f0f0f0; }"
- "Создай категорию 'Новости' и добавь туда пост о запуске сайта"

Будь полезным и делай работу максимально эффективно!
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
