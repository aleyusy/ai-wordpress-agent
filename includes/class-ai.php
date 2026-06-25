<?php

defined('ABSPATH') || exit;

class AIWP_AI {
    private $api_key;
    private $model;
    private $api_url;
    private $max_tokens;
    private $temperature;

    public function __construct() {
        $settings = get_option('aiwp_settings', []);
        $this->api_key = $settings['api_key'] ?? '';
        $this->model = $settings['model'] ?? 'openai/gpt-4o-mini';
        $this->api_url = $settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions';
        $this->max_tokens = (int)($settings['max_tokens'] ?? 4096);
        $this->temperature = (float)($settings['temperature'] ?? 0.7);

        if (empty($this->api_url)) {
            $this->api_url = 'https://openrouter.ai/api/v1/chat/completions';
        }
    }

    public function chat(array $messages, array $tools = [], bool $force_tool = false): array {
        if (empty($this->api_key)) {
            return ['content' => '⚠️ API ключ не настроен.', 'tool_calls' => []];
        }

        $messages = self::trim_messages($messages);
        $tools = self::trim_tools($tools);

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $force_tool ? 'required' : 'auto';
        }

        $response = $this->call_api($payload);
        $retries = 0;
        $max_retries = 5;

        while ($retries < $max_retries) {
            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                if (strpos($err, '429') !== false || strpos($err, 'timeout') !== false || strpos($err, '503') !== false) {
                    $retries++;
                    $delay = min($retries * 15, 60);
                    error_log("[AIWP] Retry {$retries}/{$max_retries} after {$delay}s (HTTP error)");
                    sleep($delay);
                    $response = $this->call_api($payload);
                    continue;
                }
                break;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['error'])) {
                $error_msg = $body['error']['message'] ?? '';
                $error_code = $body['error']['code'] ?? 0;
                if (preg_match('/rate.?limit|provider returned/i', $error_msg) || $error_code === 429) {
                    $retries++;
                    $delay = min($retries * 15, 60);
                    error_log("[AIWP] Retry {$retries}/{$max_retries} after {$delay}s ({$error_msg})");
                    sleep($delay);
                    $response = $this->call_api($payload);
                    continue;
                }
            }
            break;
        }

        if (is_wp_error($response)) {
            return ['content' => '⚠️ Ошибка API: ' . $response->get_error_message(), 'tool_calls' => []];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['error'])) {
            $error_msg = $body['error']['message'] ?? json_encode($body['error']);
            $error_code = $body['error']['code'] ?? 0;
            error_log('[AIWP] API Error (code=' . $error_code . '): ' . $error_msg . ' | Model: ' . $this->model . ' | Tools: ' . count($tools) . ' | Messages: ' . count($messages));
            if (preg_match('/(rate.?limit|429|too many|quota)/i', $error_msg) || $error_code === 429) {
                $trimmed = self::trim_messages($messages, true);
                $payload['messages'] = $trimmed;
                $response = $this->call_api($payload);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (empty($body['error'])) {
                        return self::parse_response($body);
                    }
                }
            }
            return ['content' => '⚠️ Ошибка AI: ' . $error_msg, 'tool_calls' => []];
        }

        return self::parse_response($body);
    }

    private static function parse_response(array $body): array {
        $choice = $body['choices'][0] ?? [];
        $message_data = $choice['message'] ?? [];
        $result = ['content' => $message_data['content'] ?? '', 'tool_calls' => []];
        if (!empty($message_data['tool_calls'])) {
            foreach ($message_data['tool_calls'] as $tc) {
                $result['tool_calls'][] = [
                    'id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                ];
            }
        }
        return $result;
    }

    private static function trim_messages(array $messages, bool $aggressive = false): array {
        if (count($messages) <= 10) return $messages;

        $system = $messages[0];
        $rest = array_slice($messages, 1);
        $max = $aggressive ? 6 : 30;

        if (count($rest) > $max) {
            $rest = array_slice($rest, -$max);
        }

        return array_values(array_merge([$system], $rest));
    }

    private static function trim_tools(array $tools): array {
        return $tools;
    }

    private function call_api(array $payload) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];

        if (strpos($this->api_url, 'openrouter.ai') !== false) {
            $headers['HTTP-Referer'] = $site_url;
            $headers['X-Title'] = 'AI WordPress Agent - ' . $site_name;
        }

        return wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 120,
            'data_format' => 'body',
        ]);
    }

    public static function fetch_available_models(string $api_key = ''): array {
        if (empty($api_key)) {
            $settings = get_option('aiwp_settings', []);
            $api_key = $settings['api_key'] ?? '';
        }
        if (empty($api_key)) {
            return self::default_models();
        }

        $cached = get_transient('aiwp_models_cache');
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get('https://openrouter.ai/api/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::default_models();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $models_data = $body['data'] ?? [];

        if (empty($models_data)) {
            return self::default_models();
        }

        $models = [];
        foreach ($models_data as $m) {
            $id = $m['id'] ?? '';
            $name = $m['name'] ?? $id;
            if (!empty($id)) {
                $models[] = [
                    'id' => $id,
                    'name' => $name,
                ];
            }
        }

        usort($models, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        set_transient('aiwp_models_cache', $models, HOUR_IN_SECONDS);

        return $models;
    }

    public static function default_models(): array {
        return [
            ['id' => 'openai/gpt-4o', 'name' => 'OpenAI GPT-4o'],
            ['id' => 'openai/gpt-4o-mini', 'name' => 'OpenAI GPT-4o Mini'],
            ['id' => 'openai/gpt-4-turbo', 'name' => 'OpenAI GPT-4 Turbo'],
            ['id' => 'openai/o1-mini', 'name' => 'OpenAI o1 Mini'],
            ['id' => 'openai/o1-preview', 'name' => 'OpenAI o1 Preview'],
            ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Anthropic Claude 3.5 Sonnet'],
            ['id' => 'anthropic/claude-3-haiku', 'name' => 'Anthropic Claude 3 Haiku'],
            ['id' => 'anthropic/claude-3-opus', 'name' => 'Anthropic Claude 3 Opus'],
            ['id' => 'google/gemini-pro-1.5', 'name' => 'Google Gemini Pro 1.5'],
            ['id' => 'google/gemini-flash-1.5', 'name' => 'Google Gemini Flash 1.5'],
            ['id' => 'mistral/mistral-large', 'name' => 'Mistral Large'],
            ['id' => 'meta-llama/llama-3.1-70b-instruct', 'name' => 'Meta Llama 3.1 70B'],
            ['id' => 'meta-llama/llama-3.1-8b-instruct', 'name' => 'Meta Llama 3.1 8B'],
            ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek Chat'],
            ['id' => 'cohere/command-r-plus', 'name' => 'Cohere Command R+'],
            ['id' => 'qwen/qwen-2.5-72b-instruct', 'name' => 'Qwen 2.5 72B'],
        ];
    }

    public static function clear_models_cache() {
        delete_transient('aiwp_models_cache');
    }
}
