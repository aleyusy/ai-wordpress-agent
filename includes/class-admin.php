<?php

defined('ABSPATH') || exit;

class AIWP_Admin {
    public static function add_menu_page() {
        add_menu_page(
            'AI WordPress Agent',
            'AI Agent',
            'manage_options',
            'aiwp-chat',
            [self::class, 'render_chat_page'],
            'dashicons-robot',
            30
        );

        add_submenu_page(
            'aiwp-chat',
            'AI Agent - Чат',
            'Чат',
            'manage_options',
            'aiwp-chat',
            [self::class, 'render_chat_page']
        );

        add_submenu_page(
            'aiwp-chat',
            'AI Agent - Настройки',
            'Настройки',
            'manage_options',
            'aiwp-settings',
            [self::class, 'render_settings_page']
        );

        add_submenu_page(
            'aiwp-chat',
            'AI Agent - Инструменты',
            'Инструменты',
            'manage_options',
            'aiwp-tools',
            [self::class, 'render_tools_page']
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'aiwp-') === false) {
            return;
        }

        wp_enqueue_style(
            'aiwp-chat',
            AIWP_URL . 'assets/css/chat.css',
            [],
            AIWP_VERSION
        );

        wp_enqueue_script(
            'aiwp-chat',
            AIWP_URL . 'assets/js/chat.js',
            [],
            AIWP_VERSION,
            true
        );

        wp_localize_script('aiwp-chat', 'AIWP', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aiwp_chat_nonce'),
            'site_name' => get_bloginfo('name'),
            'debug' => WP_DEBUG,
        ]);

        wp_enqueue_editor();
    }

    public static function render_chat_page() {
        $settings = get_option('aiwp_settings', []);
        $has_api_key = !empty($settings['api_key']);
        ?>
        <div class="wrap aiwp-wrap">
            <div class="aiwp-header">
                <div class="aiwp-header-left">
                    <h1>🤖 AI WordPress Agent</h1>
                    <p class="aiwp-subtitle">Управляй сайтом через чат с искусственным интеллектом</p>
                </div>
                <div class="aiwp-header-right">
                    <span class="aiwp-status <?php echo $has_api_key ? 'connected' : 'disconnected'; ?>">
                        <?php echo $has_api_key ? '✅ API подключён' : '❌ API не настроен'; ?>
                    </span>
                </div>
            </div>

            <?php if (!$has_api_key): ?>
                <div class="notice notice-warning inline aiwp-notice">
                    <p>
                        ⚠️ Для работы плагина необходимо настроить API ключ.
                        <a href="<?php echo admin_url('admin.php?page=aiwp-settings'); ?>">Перейти к настройкам</a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="aiwp-chat-container">
                <div class="aiwp-toolbar">
                    <button type="button" class="button aiwp-clear-btn" id="aiwp-clear">
                        <span class="dashicons dashicons-trash"></span> Очистить историю
                    </button>
                </div>
                <div class="aiwp-messages" id="aiwp-messages">
                    <div class="aiwp-message aiwp-message-ai">
                        <div class="aiwp-avatar aiwp-avatar-ai">🤖</div>
                        <div class="aiwp-bubble">
                            Привет! Я AI-агент для управления WordPress. 
                            Я могу создавать страницы, посты, меню, устанавливать плагины, 
                            настраивать дизайн и многое другое. Чем могу помочь?
                            <div class="aiwp-tools-hint">
                                Доступно <strong><?php echo count(aiwp()->get_tools_manager()->get_tool_names()); ?></strong> инструментов
                            </div>
                        </div>
                    </div>
                </div>
                <div class="aiwp-input-area">
                    <div class="aiwp-input-wrapper">
                        <textarea
                            id="aiwp-input"
                            class="aiwp-input"
                            placeholder="Напишите, что нужно сделать..."
                            rows="1"
                        ></textarea>
                        <button type="button" class="aiwp-send-btn" id="aiwp-send" disabled>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                    <div class="aiwp-footer-text">
                        AI может выполнять действия от вашего имени. Проверяйте результаты.
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_settings_page() {
        if (isset($_POST['save_aiwp_settings']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'aiwp_settings')) {
            $settings = [
                'api_key' => sanitize_text_field($_POST['api_key'] ?? ''),
                'api_url' => esc_url_raw($_POST['api_url'] ?? ''),
                'model' => sanitize_text_field($_POST['model'] ?? ''),
                'max_tokens' => min((int)($_POST['max_tokens'] ?? 4096), 128000),
                'temperature' => max(0, min(2, (float)($_POST['temperature'] ?? 0.7))),
                'github_repo' => sanitize_text_field($_POST['github_repo'] ?? ''),
            ];
            update_option('aiwp_settings', $settings);
            echo '<div class="notice notice-success"><p>✅ Настройки сохранены.</p></div>';
        }

        $settings = get_option('aiwp_settings', []);
        ?>
        <div class="wrap">
            <h1>🤖 AI Agent — Настройки</h1>
            <form method="post" class="aiwp-settings-form">
                <?php wp_nonce_field('aiwp_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_url">API URL</label></th>
                        <td>
                            <input type="url" id="api_url" name="api_url"
                                value="<?php echo esc_attr($settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions'); ?>"
                                class="regular-text" placeholder="https://openrouter.ai/api/v1/chat/completions">
                            <p class="description">OpenAI-совместимый эндпоинт (OpenRouter, OpenAI, Anthropic и др.)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="api_key">API Ключ</label></th>
                        <td>
                            <input type="password" id="api_key" name="api_key"
                                value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>"
                                class="regular-text" placeholder="sk-...">
                            <p class="description">API ключ от OpenRouter, OpenAI или другого провайдера</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="model_select">Модель</label></th>
                        <td>
                            <?php
                            $current_model = $settings['model'] ?? 'openai/gpt-4o-mini';
                            $models = AIWP_AI::fetch_available_models();
                            $is_custom = true;
                            foreach ($models as $m) {
                                if ($m['id'] === $current_model) {
                                    $is_custom = false;
                                    break;
                                }
                            }
                            ?>
                            <select id="model_select" class="regular-text" style="margin-bottom:6px;">
                                <?php foreach ($models as $m): ?>
                                    <option value="<?php echo esc_attr($m['id']); ?>" <?php selected($m['id'], $current_model); ?>>
                                        <?php echo esc_html($m['name'] . ' (' . $m['id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?php echo $is_custom ? 'selected' : ''; ?>>Другая модель...</option>
                            </select>
                            <div id="aiwp-custom-model-wrap" style="<?php echo $is_custom ? '' : 'display:none;'; ?>margin-top:4px;">
                                <input type="text" id="model_custom" name="model"
                                    value="<?php echo $is_custom ? esc_attr($current_model) : ''; ?>"
                                    class="regular-text" placeholder="Введите название модели вручную">
                            </div>
                            <input type="hidden" id="model_hidden" name="model" value="<?php echo esc_attr($current_model); ?>">
                            <p class="description">
                                Выберите модель или укажите свою. 
                                <button type="button" class="button button-small" id="aiwp-fetch-models">🔄 Обновить список</button>
                                <span id="aiwp-models-status" style="margin-left:6px;font-size:12px;"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_tokens">Max Tokens</label></th>
                        <td>
                            <input type="number" id="max_tokens" name="max_tokens" min="256" max="128000" step="1"
                                value="<?php echo esc_attr($settings['max_tokens'] ?? 4096); ?>"
                                class="small-text">
                            <p class="description">Максимальное количество токенов в ответе (по умолчанию 4096)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="temperature">Temperature</label></th>
                        <td>
                            <input type="number" id="temperature" name="temperature" min="0" max="2" step="0.1"
                                value="<?php echo esc_attr($settings['temperature'] ?? 0.7); ?>"
                                class="small-text">
                            <p class="description">Температура генерации (0.0 — точность, 1.0 — креативность)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="github_repo">GitHub репозиторий</label></th>
                        <td>
                            <input type="text" id="github_repo" name="github_repo"
                                value="<?php echo esc_attr($settings['github_repo'] ?? ''); ?>"
                                class="regular-text" placeholder="username/ai-wordpress-agent">
                            <p class="description">
                                Для авто-обновлений через GitHub Releases. Укажите <code>пользователь/репозиторий</code>.
                                Релизы должны иметь тег <code>v1.0.0</code> и содержать ZIP-архив плагина.
                                <?php if (!empty($settings['github_repo'])): ?>
                                    <br><span style="color:#666;">Текущий статус: 
                                    <?php
                                    $updater = new AIWP_Updater(AIWP_FILE);
                                    $transient = get_site_transient('update_plugins');
                                    $basename = plugin_basename(AIWP_FILE);
                                    if (isset($transient->response[$basename])) {
                                        echo '✅ Доступно обновление ' . $transient->response[$basename]->new_version;
                                    } else {
                                        echo 'ℹ️ Версия ' . AIWP_VERSION . ' — актуальна или проверка не прошла';
                                    }
                                    ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="save_aiwp_settings" class="button button-primary">
                        Сохранить настройки
                    </button>
                </p>
            </form>

            <hr>
            <h2>🔍 Проверка подключения</h2>
            <p>После сохранения настроек можно проверить подключение к API:</p>
            <button type="button" class="button" id="aiwp-test-api">Проверить подключение</button>
            <span id="aiwp-test-result" style="margin-left: 10px;"></span>
        </div>
        <script>
        (function() {
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo wp_create_nonce('aiwp_chat_nonce'); ?>';

            function el(id) { return document.getElementById(id); }

            // --- Test API ---
            el('aiwp-test-api')?.addEventListener('click', function() {
                var resultEl = el('aiwp-test-result');
                resultEl.textContent = '⏳ Проверка...';
                var data = new FormData();
                data.append('action', 'aiwp_test_api');
                data.append('nonce', nonce);
                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        resultEl.textContent = r.success ? '✅ Подключение работает!' : '❌ ' + (r.data?.message || 'Ошибка');
                    })
                    .catch(function() { resultEl.textContent = '❌ Ошибка запроса'; });
            });

            // --- Model dropdown logic ---
            var selectEl = el('model_select');
            var customWrap = el('aiwp-custom-model-wrap');
            var customInput = el('model_custom');
            var hiddenInput = el('model_hidden');

            if (selectEl && customWrap && customInput && hiddenInput) {
                function syncModel() {
                    if (selectEl.value === '__custom__') {
                        customWrap.style.display = '';
                        hiddenInput.value = customInput.value;
                    } else {
                        customWrap.style.display = 'none';
                        hiddenInput.value = selectEl.value;
                    }
                }

                selectEl.addEventListener('change', syncModel);
                customInput.addEventListener('input', function() {
                    hiddenInput.value = customInput.value;
                });
                syncModel();

                // --- Fetch models ---
                el('aiwp-fetch-models')?.addEventListener('click', function() {
                    var statusEl = el('aiwp-models-status');
                    statusEl.textContent = '⏳ Загрузка...';
                    var data = new FormData();
                    data.append('action', 'aiwp_fetch_models');
                    data.append('nonce', nonce);
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(r) {
                            if (r.success && r.data.models) {
                                var currentVal = hiddenInput.value;
                                selectEl.innerHTML = '';
                                r.data.models.forEach(function(m) {
                                    var opt = document.createElement('option');
                                    opt.value = m.id;
                                    opt.textContent = m.name + ' (' + m.id + ')';
                                    if (m.id === currentVal) opt.selected = true;
                                    selectEl.appendChild(opt);
                                });
                                var customOpt = document.createElement('option');
                                customOpt.value = '__custom__';
                                customOpt.textContent = 'Другая модель...';
                                selectEl.appendChild(customOpt);
                                syncModel();
                                statusEl.textContent = '✅ Загружено ' + r.data.models.length + ' моделей';
                            } else {
                                statusEl.textContent = '❌ ' + (r.data?.message || 'Ошибка загрузки');
                            }
                        })
                        .catch(function() { statusEl.textContent = '❌ Ошибка запроса'; });
                });
            }
        })();
        </script>
        <?php
    }

    public static function test_api_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden']);
        }

        $settings = get_option('aiwp_settings', []);
        $api_key = $settings['api_key'] ?? '';
        $api_url = $settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions';
        $model = $settings['model'] ?? 'openai/gpt-4o-mini';

        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API ключ не настроен']);
        }

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Say "ok"']],
                'max_tokens' => 10,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err = $body['error']['message'] ?? "HTTP $code";
            wp_send_json_error(['message' => $err]);
        }

        wp_send_json_success(['message' => 'OK']);
    }

    public static function fetch_models_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden']);
        }

        AIWP_AI::clear_models_cache();
        $models = AIWP_AI::fetch_available_models();
        wp_send_json_success(['models' => $models]);
    }

    public static function render_tools_page() {
        $tools = aiwp()->get_tools_manager()->get_tools_list();
        ?>
        <div class="wrap">
            <h1>🤖 AI Agent — Доступные инструменты</h1>
            <p>Всего инструментов: <strong><?php echo count($tools); ?></strong></p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Инструмент</th>
                        <th>Описание</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $i => $tool): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><code><?php echo esc_html($tool['name']); ?></code></td>
                            <td><?php echo esc_html($tool['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
