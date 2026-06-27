<?php

defined('ABSPATH') || exit;

class AIWP_Admin {
    public static function add_menu_page() {
        add_menu_page(
            __('AI WordPress Agent', 'aiwp'),
            __('AI Agent', 'aiwp'),
            'manage_options',
            'aiwp-chat',
            [self::class, 'render_chat_page'],
            'dashicons-robot',
            30
        );

        add_submenu_page('aiwp-chat', __('AI Agent — Чат', 'aiwp'), __('Чат', 'aiwp'), 'manage_options', 'aiwp-chat', [self::class, 'render_chat_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Анализ', 'aiwp'), __('Анализ сайта', 'aiwp'), 'manage_options', 'aiwp-analysis', [self::class, 'render_analysis_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Память', 'aiwp'), __('Память', 'aiwp'), 'manage_options', 'aiwp-memory', [self::class, 'render_memory_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Скиллы', 'aiwp'), __('Скиллы', 'aiwp'), 'manage_options', 'aiwp-skills', [self::class, 'render_skills_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Файлы', 'aiwp'), __('Редактор файлов', 'aiwp'), 'manage_options', 'aiwp-files', [self::class, 'render_files_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Роли', 'aiwp'), __('Роли и права', 'aiwp'), 'manage_options', 'aiwp-roles', [self::class, 'render_roles_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Инструменты', 'aiwp'), __('Инструменты', 'aiwp'), 'manage_options', 'aiwp-tools', [self::class, 'render_tools_page']);
        add_submenu_page('aiwp-chat', __('AI Agent — Настройки', 'aiwp'), __('Настройки', 'aiwp'), 'manage_options', 'aiwp-settings', [self::class, 'render_settings_page']);
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'aiwp-') === false) return;

        wp_enqueue_style('aiwp-chat', AIWP_URL . 'assets/css/chat.css', [], AIWP_VERSION);
        wp_enqueue_style('aiwp-platform', AIWP_URL . 'assets/css/admin-platform.css', [], AIWP_VERSION);
        wp_enqueue_script('aiwp-chat', AIWP_URL . 'assets/js/chat.js', [], AIWP_VERSION, true);

        wp_localize_script('aiwp-chat', 'AIWP', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aiwp_chat_nonce'),
            'site_name' => get_bloginfo('name'),
            'debug' => WP_DEBUG,
        ]);

        if (strpos($hook, 'aiwp-files') !== false) {
            wp_enqueue_script('aiwp-file-editor', AIWP_URL . 'assets/js/file-editor.js', ['aiwp-chat'], AIWP_VERSION, true);
        }
        if (strpos($hook, 'aiwp-analysis') !== false) {
            wp_enqueue_script('aiwp-analysis', AIWP_URL . 'assets/js/analysis.js', ['aiwp-chat'], AIWP_VERSION, true);
        }
        if (strpos($hook, 'aiwp-skills') !== false) {
            wp_enqueue_script('aiwp-skills', AIWP_URL . 'assets/js/skills.js', ['aiwp-chat'], AIWP_VERSION, true);
        }
    }

    // === Chat Page ===

    public static function render_chat_page() {
        $settings = get_option('aiwp_settings', []);
        $has_api_key = !empty($settings['api_key']);
        $user_id = get_current_user_id();
        $caps = AIWP_Roles::get_user_capabilities($user_id);
        $granted = array_filter($caps, fn($c) => $c['granted']);
        ?>
        <div class="wrap aiwp-wrap">
            <div class="aiwp-header">
                <div class="aiwp-header-left">
                    <h1>🤖 <?php esc_html_e('AI WordPress Agent', 'aiwp'); ?></h1>
                    <p class="aiwp-subtitle"><?php esc_html_e('Управляй сайтом через чат с ИИ', 'aiwp'); ?></p>
                </div>
                <div class="aiwp-header-right">
                    <span class="aiwp-status <?php echo $has_api_key ? 'connected' : 'disconnected'; ?>">
                        <?php echo $has_api_key ? esc_html__('✅ API подключён', 'aiwp') : esc_html__('❌ API не настроен', 'aiwp'); ?>
                    </span>
                    <span class="aiwp-status connected" style="margin-left:8px;">
                        🔑 <?php printf(esc_html__('%d capabilities', 'aiwp'), count($granted)); ?>
                    </span>
                </div>
            </div>
            <?php if (!$has_api_key): ?>
                <div class="notice notice-warning inline aiwp-notice">
                    <p>⚠️ <?php printf(esc_html__('Настройте API ключ. %sПерейти к настройкам%s', 'aiwp'), '<a href="' . admin_url('admin.php?page=aiwp-settings') . '">', '</a>'); ?></p>
                </div>
            <?php endif; ?>
            <div class="aiwp-chat-container">
                <div class="aiwp-toolbar">
                    <button type="button" class="button aiwp-clear-btn" id="aiwp-clear">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Очистить', 'aiwp'); ?>
                    </button>
                </div>
                <div class="aiwp-messages" id="aiwp-messages">
                    <div class="aiwp-message aiwp-message-ai">
                        <div class="aiwp-avatar aiwp-avatar-ai">🤖</div>
                        <div class="aiwp-bubble">
                            <?php esc_html_e('Привет! Я AI-агент для управления WordPress. Чем могу помочь?', 'aiwp'); ?>
                            <div class="aiwp-tools-hint"><?php printf(esc_html__('Доступно %s инструментов', 'aiwp'), '<strong>' . count(aiwp()->get_tools_manager()->get_tool_names()) . '</strong>'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="aiwp-input-area">
                    <div class="aiwp-input-wrapper">
                        <textarea id="aiwp-input" class="aiwp-input" placeholder="<?php esc_attr_e('Напишите, что нужно сделать...', 'aiwp'); ?>" rows="1"></textarea>
                        <button type="button" class="aiwp-send-btn" id="aiwp-send" disabled>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                    <div class="aiwp-footer-text"><?php esc_html_e('AI выполняет действия от вашего имени. Проверяйте результаты.', 'aiwp'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    // === Settings Page ===

    public static function render_settings_page() {
        if (isset($_POST['save_aiwp_settings']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'aiwp_settings')) {
            $settings = [
                'api_key' => sanitize_text_field($_POST['api_key'] ?? ''),
                'api_url' => esc_url_raw($_POST['api_url'] ?? ''),
                'model' => sanitize_text_field($_POST['model'] ?? ''),
                'max_tokens' => min((int)($_POST['max_tokens'] ?? 4096), 128000),
                'temperature' => max(0, min(2, (float)($_POST['temperature'] ?? 0.7))),
                'github_repo' => sanitize_text_field($_POST['github_repo'] ?? ''),
                'frontend_chat' => !empty($_POST['frontend_chat']) ? 1 : 0,
                'widget_position' => in_array($_POST['widget_position'] ?? '', ['left', 'right']) ? $_POST['widget_position'] : 'right',
            ];
            update_option('aiwp_settings', $settings);
            echo '<div class="notice notice-success"><p>' . esc_html__('✅ Настройки сохранены.', 'aiwp') . '</p></div>';
        }
        $settings = get_option('aiwp_settings', []);
        $favorites = get_option('aiwp_favorite_models', []);
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Настройки', 'aiwp'); ?></h1>
            <?php if (!empty($favorites)): ?>
            <div class="aiwp-favorites" style="background:#f0f0f1;padding:15px;border-radius:8px;margin-bottom:20px;">
                <h3 style="margin-top:0;"><?php esc_html_e('⭐ Избранные модели', 'aiwp'); ?></h3>
                <p style="margin:5px 0 10px;color:#666;"><?php esc_html_e('Нажмите для быстрого выбора:', 'aiwp'); ?></p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($favorites as $fav): ?>
                    <div style="display:flex;align-items:center;background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:6px 12px;cursor:pointer;" onclick="document.getElementById('model_input').value='<?php echo esc_attr($fav['id']); ?>';">
                        <span style="margin-right:8px;"><?php echo esc_html($fav['name']); ?></span>
                        <small style="color:#999;"><?php echo esc_html($fav['id']); ?></small>
                            <button type="button" class="aiwp-del-fav" data-id="<?php echo esc_attr($fav['id']); ?>" style="margin-left:8px;background:none;border:none;color:#dc3232;cursor:pointer;font-size:14px;" title="<?php esc_attr_e('Удалить', 'aiwp'); ?>">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <form method="post" class="aiwp-settings-form">
                <?php wp_nonce_field('aiwp_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="api_url"><?php esc_html_e('API URL', 'aiwp'); ?></label></th><td><input type="url" id="api_url" name="api_url" value="<?php echo esc_attr($settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions'); ?>" class="regular-text"><p class="description"><?php esc_html_e('OpenAI-совместимый эндпоинт', 'aiwp'); ?></p></td></tr>
                    <tr><th scope="row"><label for="api_key"><?php esc_html_e('API Ключ', 'aiwp'); ?></label></th><td><input type="password" id="api_key" name="api_key" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" placeholder="sk-..."></td></tr>
                    <tr><th scope="row"><label for="model_input"><?php esc_html_e('Модель', 'aiwp'); ?></label></th><td>
                        <?php $models = AIWP_AI::fetch_available_models(); ?>
                        <input type="text" id="model_input" name="model" value="<?php echo esc_attr($settings['model'] ?? 'openai/gpt-4o-mini'); ?>" class="regular-text" list="model_list" autocomplete="off">
                        <datalist id="model_list"><?php foreach ($models as $m): ?><option value="<?php echo esc_attr($m['id']); ?>"><?php echo esc_html($m['name']); ?></option><?php endforeach; ?></datalist>
                        <button type="button" class="button button-small" id="aiwp-fetch-models">🔄 <?php esc_html_e('Обновить', 'aiwp'); ?></button>
                        <button type="button" class="button button-small" id="aiwp-add-fav">⭐ <?php esc_html_e('В избранное', 'aiwp'); ?></button>
                    </td></tr>
                    <tr><th scope="row"><label for="max_tokens"><?php esc_html_e('Max Tokens', 'aiwp'); ?></label></th><td><input type="number" id="max_tokens" name="max_tokens" min="256" max="128000" value="<?php echo esc_attr($settings['max_tokens'] ?? 4096); ?>" class="small-text"></td></tr>
                    <tr><th scope="row"><label for="temperature"><?php esc_html_e('Temperature', 'aiwp'); ?></label></th><td><input type="number" id="temperature" name="temperature" min="0" max="2" step="0.1" value="<?php echo esc_attr($settings['temperature'] ?? 0.7); ?>" class="small-text"></td></tr>
                    <tr><th scope="row"><label for="github_repo"><?php esc_html_e('GitHub репозиторий', 'aiwp'); ?></label></th><td><input type="text" id="github_repo" name="github_repo" value="<?php echo esc_attr($settings['github_repo'] ?? ''); ?>" class="regular-text" placeholder="user/repo"></td></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Чат на сайте', 'aiwp'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="frontend_chat" value="1" <?php checked(!empty($settings['frontend_chat'])); ?>>
                                <?php esc_html_e('Показывать чат-виджет на фронтенде сайта', 'aiwp'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Виджет будет виден только пользователям с правом manage_options', 'aiwp'); ?></p>
                            <div style="margin-top:8px;">
                                <label style="margin-right:16px;">
                                    <input type="radio" name="widget_position" value="right" <?php checked(($settings['widget_position'] ?? 'right') === 'right'); ?>>
                                    <?php esc_html_e('Справа', 'aiwp'); ?>
                                </label>
                                <label>
                                    <input type="radio" name="widget_position" value="left" <?php checked(($settings['widget_position'] ?? 'right') === 'left'); ?>>
                                    <?php esc_html_e('Слева', 'aiwp'); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="save_aiwp_settings" class="button button-primary"><?php esc_html_e('Сохранить', 'aiwp'); ?></button></p>
            </form>
            <hr>
            <h2><?php esc_html_e('Проверка подключения', 'aiwp'); ?></h2>
            <button type="button" class="button" id="aiwp-test-api"><?php esc_html_e('Проверить', 'aiwp'); ?></button>
            <span id="aiwp-test-result" style="margin-left:10px;"></span>
        </div>
        <script>
        (function(){
            var ajaxurl='<?php echo admin_url('admin-ajax.php'); ?>', nonce='<?php echo wp_create_nonce('aiwp_chat_nonce'); ?>';
            function el(id){return document.getElementById(id);}
            el('aiwp-test-api')?.addEventListener('click',function(){
                var result_el=el('aiwp-test-result');result_el.textContent='⏳';
                var d=new FormData();d.append('action','aiwp_test_api');d.append('nonce',nonce);
                fetch(ajaxurl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
                    r.success?result_el.textContent='✅ OK':result_el.textContent='❌ '+(r.data?.message||'Error');
                }).catch(function(){result_el.textContent='❌ Error';});
            });
            el('aiwp-fetch-models')?.addEventListener('click',function(){
                var s=el('aiwp-models-status'),i=el('model_input');s.textContent='⏳';
                var d=new FormData();d.append('action','aiwp_fetch_models');d.append('nonce',nonce);
                fetch(ajaxurl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
                    if(r.success&&r.data.models){
                        var l=document.createElement('datalist');l.id='model_list';
                        r.data.models.forEach(function(m){var o=document.createElement('option');o.value=m.id;o.textContent=m.name;l.appendChild(o);});
                        var old=document.getElementById('model_list');if(old)old.remove();
                        i.setAttribute('list','model_list');i.parentNode.insertBefore(l,i.nextSibling);
                        s.textContent='✅ '+r.data.models.length;
                    }else s.textContent='❌';
                }).catch(function(){s.textContent='❌';});
            });
            el('aiwp-add-fav')?.addEventListener('click',function(){
                var model_id=el('model_input').value;
                if(!model_id){alert('Введите модель');return;}
                var d=new FormData();d.append('action','aiwp_save_favorite_model');d.append('nonce',nonce);d.append('model_id',model_id);d.append('model_name',model_id);
                fetch(ajaxurl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
                    if(r.success)location.reload();else alert(r.data?.message||'Error');
                });
            });
            document.querySelectorAll('.aiwp-del-fav').forEach(function(btn){
                btn.addEventListener('click',function(e){
                    e.stopPropagation();
                    if(!confirm('Удалить из избранного?'))return;
                    var d=new FormData();d.append('action','aiwp_delete_favorite_model');d.append('nonce',nonce);d.append('model_id',btn.dataset.id);
                    fetch(ajaxurl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
                        if(r.success)location.reload();else alert(r.data?.message||'Error');
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // === Analysis Page ===

    public static function render_analysis_page() {
        $analysis = AIWP_Analyzer::get_analysis();
        $is_stale = AIWP_Analyzer::is_stale();
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Анализ сайта', 'aiwp'); ?></h1>
            <div class="aiwp-flex-between" style="margin-bottom:20px;">
                <p><?php echo $analysis ? sprintf(esc_html__('Последний анализ: %s', 'aiwp'), $analysis['last_analysis']) : esc_html__('Анализ ещё не проводился', 'aiwp'); ?></p>
                <button type="button" class="button button-primary" id="aiwp-run-analysis">🔍 <?php echo $analysis ? esc_html__('Переанализировать', 'aiwp') : esc_html__('Запустить анализ', 'aiwp'); ?></button>
            </div>
            <div id="aiwp-analysis-result">
            <?php if ($analysis): ?>
                <div class="aiwp-score-cards">
                    <div class="aiwp-score-card"><div class="aiwp-score-value" style="color:<?php echo ($analysis['overall_score']>=70)?'#2e7d32':(($analysis['overall_score']>=40)?'#f57f17':'#c62828'); ?>"><?php echo $analysis['overall_score']; ?></div><div class="aiwp-score-label"><?php esc_html_e('Общий балл', 'aiwp'); ?></div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['security']['score']; ?></div><div class="aiwp-score-label"><?php esc_html_e('Безопасность', 'aiwp'); ?></div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['performance']['score']; ?></div><div class="aiwp-score-label"><?php esc_html_e('Скорость', 'aiwp'); ?></div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['seo']['score']; ?></div><div class="aiwp-score-label"><?php esc_html_e('SEO', 'aiwp'); ?></div></div>
                </div>
                <h2><?php esc_html_e('Темы', 'aiwp'); ?></h2>
                <p><?php printf(esc_html__('Активная: %s', 'aiwp'), '<strong>' . esc_html($analysis['themes']['active']['name'] ?? __('N/A', 'aiwp')) . '</strong>'); ?> (v<?php echo esc_html($analysis['themes']['active']['version'] ?? ''); ?>)</p>
                <p><?php printf(esc_html__('Установлено тем: %d', 'aiwp'), $analysis['themes']['total'] ?? 0); ?></p>
                <h2><?php esc_html_e('Плагины', 'aiwp'); ?></h2>
                <p><?php printf(esc_html__('Активных: %s', 'aiwp'), '<strong>' . ($analysis['plugins']['active'] ?? 0) . '</strong>'); ?> | <?php printf(esc_html__('Неактивных: %d', 'aiwp'), $analysis['plugins']['inactive'] ?? 0); ?></p>
                <?php if (!empty($analysis['plugins']['security_plugins'])): ?><p>🔒 <?php printf(esc_html__('Безопасность: %s', 'aiwp'), implode(', ', $analysis['plugins']['security_plugins'])); ?></p><?php endif; ?>
                <?php if (!empty($analysis['plugins']['seo_plugins'])): ?><p>📈 <?php printf(esc_html__('SEO: %s', 'aiwp'), implode(', ', $analysis['plugins']['seo_plugins'])); ?></p><?php endif; ?>
                <?php if (!empty($analysis['plugins']['caching_plugins'])): ?><p>⚡ <?php printf(esc_html__('Кэширование: %s', 'aiwp'), implode(', ', $analysis['plugins']['caching_plugins'])); ?></p><?php endif; ?>
                <h2><?php esc_html_e('Проблемы и рекомендации', 'aiwp'); ?></h2>
                <?php
                $all_issues = array_merge($analysis['security']['issues'] ?? [], $analysis['performance']['issues'] ?? [], $analysis['seo']['issues'] ?? []);
                $all_recs = array_merge($analysis['security']['recommendations'] ?? [], $analysis['performance']['recommendations'] ?? [], $analysis['seo']['recommendations'] ?? []);
                if (!empty($all_issues)):
                    echo '<ul class="aiwp-issues-list">';
                    foreach ($all_issues as $issue):
                        $severity = $issue['severity'] ?? $issue['type'] ?? 'info';
                        $cls = ($severity === 'high') ? 'aiwp-severity-high' : (($severity === 'medium') ? 'aiwp-severity-medium' : 'aiwp-severity-low');
                    ?>
                        <li><span class="aiwp-severity-badge <?php echo $cls; ?>"><?php echo esc_html($severity); ?></span> <?php echo esc_html($issue['message'] ?? ''); ?></li>
                    <?php endforeach; echo '</ul>'; endif; ?>
                <?php if (!empty($all_recs)): ?>
                    <ul class="aiwp-recommendations"><?php foreach ($all_recs as $rec): ?><li>💡 <?php echo esc_html($rec); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <h2><?php esc_html_e('Контент', 'aiwp'); ?></h2>
                <p><?php printf(esc_html__('Страниц: %d (опубликовано: %d) | Постов: %d | Комментариев: %d', 'aiwp'), $analysis['content']['pages']['total'] ?? 0, $analysis['content']['pages']['published'] ?? 0, $analysis['content']['posts']['total'] ?? 0, $analysis['content']['comments']['total'] ?? 0); ?></p>
                <h2><?php esc_html_e('Пользователи', 'aiwp'); ?></h2>
                <p><?php printf(esc_html__('Всего: %d', 'aiwp'), $analysis['users']['total'] ?? 0); ?></p>
            <?php else: ?>
                <p><?php esc_html_e('Анализ ещё не проводился. Нажмите "Запустить анализ".', 'aiwp'); ?></p>
            <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var btn=document.getElementById('aiwp-run-analysis');
            if(btn) btn.addEventListener('click',function(){
                btn.disabled=true;btn.textContent='⏳ Анализ...';
                var d=new FormData();d.append('action','aiwp_run_analysis');d.append('nonce',AIWP.nonce);
                fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else{btn.disabled=false;btn.textContent='❌ Ошибка';}}).catch(function(){btn.disabled=false;btn.textContent='❌ Error';});
            });
        })();
        </script>
        <?php
    }

    // === Memory Page ===

    public static function render_memory_page() {
        $memories = AIWP_Memory::get_all_memories();
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Память', 'aiwp'); ?></h1>
            <p><?php printf(esc_html__('Долговременная память агента. Категории: %s', 'aiwp'), '<strong>' . count($memories) . '</strong>'); ?></p>
            <?php if (empty($memories)): ?>
                <p><?php esc_html_e('Память пуста. Агент начнёт заполнять её по мере работы.', 'aiwp'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php esc_html_e('Категория', 'aiwp'); ?></th><th><?php esc_html_e('Ключ', 'aiwp'); ?></th><th><?php esc_html_e('Значение', 'aiwp'); ?></th><th><?php esc_html_e('Создано', 'aiwp'); ?></th><th><?php esc_html_e('Действия', 'aiwp'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($memories as $cat => $entries): ?>
                        <?php foreach ($entries as $key => $entry): ?>
                            <tr>
                                <td><code><?php echo esc_html($cat); ?></code></td>
                                <td><code><?php echo esc_html($key); ?></code></td>
                                <td><?php echo esc_html(wp_json_encode($entry['value'])); ?></td>
                                <td><?php echo esc_html($entry['created_at'] ?? ''); ?></td>
                                <td><button class="button button-small aiwp-delete-memory" data-cat="<?php echo esc_attr($cat); ?>" data-key="<?php echo esc_attr($key); ?>">🗑</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.aiwp-delete-memory').forEach(function(btn){
                btn.addEventListener('click',function(){
                    if(!confirm('Удалить?'))return;
                    var d=new FormData();d.append('action','aiwp_delete_memory');d.append('nonce',AIWP.nonce);d.append('category',btn.dataset.cat);d.append('key',btn.dataset.key);
                    fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else alert(r.data?.message||'Error');});
                });
            });
        })();
        </script>
        <?php
    }

    // === Skills Page ===

    public static function render_skills_page() {
        $skills = AIWP_Skills::get_all();
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Скиллы', 'aiwp'); ?></h1>
            <p><?php printf(esc_html__('Сохранённые воркфлоу: %s', 'aiwp'), '<strong>' . count($skills) . '</strong>'); ?></p>
            <?php if (empty($skills)): ?>
                <p><?php esc_html_e('Скиллов пока нет. Создавайте их через чат с агентом.', 'aiwp'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php esc_html_e('Slug', 'aiwp'); ?></th><th><?php esc_html_e('Название', 'aiwp'); ?></th><th><?php esc_html_e('Описание', 'aiwp'); ?></th><th><?php esc_html_e('Категория', 'aiwp'); ?></th><th><?php esc_html_e('Шагов', 'aiwp'); ?></th><th><?php esc_html_e('Действия', 'aiwp'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($skills as $slug => $skill): ?>
                        <tr>
                            <td><code><?php echo esc_html($slug); ?></code></td>
                            <td><?php echo esc_html($skill['name'] ?? ''); ?></td>
                            <td><?php echo esc_html($skill['description'] ?? ''); ?></td>
                            <td><?php echo esc_html($skill['category'] ?? 'general'); ?></td>
                            <td><?php echo count($skill['steps'] ?? []); ?></td>
                            <td>
                                <button class="button button-small aiwp-delete-skill" data-slug="<?php echo esc_attr($slug); ?>">🗑</button>
                                <button class="button button-small aiwp-export-skill" data-slug="<?php echo esc_attr($slug); ?>">📥</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.aiwp-delete-skill').forEach(function(btn){
                btn.addEventListener('click',function(){
                    if(!confirm('Удалить скилл?'))return;
                    var d=new FormData();d.append('action','aiwp_delete_skill');d.append('nonce',AIWP.nonce);d.append('slug',btn.dataset.slug);
                    fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else alert(r.data?.message||'Error');});
                });
            });
            document.querySelectorAll('.aiwp-export-skill').forEach(function(btn){
                btn.addEventListener('click',function(){
                    var d=new FormData();d.append('action','aiwp_chat');d.append('nonce',AIWP.nonce);d.append('message','Экспортируй скилл '+btn.dataset.slug+' в JSON');
                    fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)alert(r.data.message);});
                });
            });
        })();
        </script>
        <?php
    }

    // === Files Page ===

    public static function render_files_page() {
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Редактор файлов темы', 'aiwp'); ?></h1>
            <div class="aiwp-file-editor-layout">
                <div class="aiwp-file-tree" id="aiwp-file-tree">
                    <p><?php esc_html_e('Загрузка...', 'aiwp'); ?></p>
                </div>
                <div class="aiwp-file-content">
                    <div class="aiwp-file-header" id="aiwp-file-header">
                        <span id="aiwp-file-name"><?php esc_html_e('Выберите файл', 'aiwp'); ?></span>
                        <button type="button" class="button button-primary" id="aiwp-save-file" disabled>💾 <?php esc_html_e('Сохранить', 'aiwp'); ?></button>
                    </div>
                    <textarea id="aiwp-file-editor" class="aiwp-code-editor" spellcheck="false" disabled></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    // === Roles Page ===

    public static function render_roles_page() {
        if (isset($_POST['save_aiwp_roles']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'aiwp_roles')) {
            $config = [];
            $caps = AIWP_Roles::get_capabilities();
            $roles = array_keys(AIWP_Roles::get_role_config());
            foreach ($roles as $role) {
                $config[$role] = [];
                foreach ($caps as $cap_key => $cap_desc) {
                    $config[$role][$cap_key] = !empty($_POST['caps'][$role][$cap_key]);
                }
            }
            AIWP_Roles::update_role_config($config);
            echo '<div class="notice notice-success"><p>' . esc_html__('✅ Роли сохранены.', 'aiwp') . '</p></div>';
        }
        $config = AIWP_Roles::get_role_config();
        $caps = AIWP_Roles::get_capabilities();
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Роли и права', 'aiwp'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('aiwp_roles'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php esc_html_e('Роль', 'aiwp'); ?></th><?php foreach ($caps as $cap_key => $cap_desc): ?><th title="<?php echo esc_attr($cap_desc); ?>"><?php echo esc_html(str_replace('aiwp_', '', $cap_key)); ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($config as $role => $role_caps): ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucfirst($role)); ?></strong></td>
                            <?php foreach ($caps as $cap_key => $cap_desc): ?>
                                <td><input type="checkbox" name="caps[<?php echo esc_attr($role); ?>][<?php echo esc_attr($cap_key); ?>]" value="1" <?php checked(!empty($role_caps[$cap_key])); ?>></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><button type="submit" name="save_aiwp_roles" class="button button-primary"><?php esc_html_e('Сохранить роли', 'aiwp'); ?></button></p>
            </form>
        </div>
        <?php
    }

    // === Tools Page ===

    public static function render_tools_page() {
        $tools = aiwp()->get_tools_manager()->get_tools_list();
        ?>
        <div class="wrap">
            <h1>🤖 <?php esc_html_e('AI Agent — Инструменты', 'aiwp'); ?></h1>
            <p><?php printf(esc_html__('Всего: %s', 'aiwp'), '<strong>' . count($tools) . '</strong>'); ?></p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>#</th><th><?php esc_html_e('Инструмент', 'aiwp'); ?></th><th><?php esc_html_e('Описание', 'aiwp'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($tools as $i => $tool): ?>
                    <tr><td><?php echo $i + 1; ?></td><td><code><?php echo esc_html($tool['name']); ?></code></td><td><?php echo esc_html($tool['description']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // === AJAX Handlers ===

    public static function test_api_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $settings = get_option('aiwp_settings', []);
        $api_key = $settings['api_key'] ?? '';
        if (empty($api_key)) wp_send_json_error(['message' => __('API ключ не настроен', 'aiwp')]);
        $response = wp_remote_post($settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions', [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
            'body' => json_encode(['model' => $settings['model'] ?? 'openai/gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'Say "ok"']], 'max_tokens' => 10]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) { $body = json_decode(wp_remote_retrieve_body($response), true); wp_send_json_error(['message' => $body['error']['message'] ?? "HTTP $code"]); }
        wp_send_json_success(['message' => 'OK']);
    }

    public static function fetch_models_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        AIWP_AI::clear_models_cache();
        wp_send_json_success(['models' => AIWP_AI::fetch_available_models()]);
    }

    public static function save_favorite_model_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $model_id = sanitize_text_field($_POST['model_id'] ?? '');
        $model_name = sanitize_text_field($_POST['model_name'] ?? $model_id);
        if (empty($model_id)) wp_send_json_error(['message' => __('Model ID required', 'aiwp')]);
        $favorites = get_option('aiwp_favorite_models', []);
        $favorites[$model_id] = ['id' => $model_id, 'name' => $model_name, 'saved_at' => current_time('mysql')];
        update_option('aiwp_favorite_models', $favorites);
        wp_send_json_success(['message' => __('Model saved.', 'aiwp'), 'favorites' => $favorites]);
    }

    public static function delete_favorite_model_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $model_id = sanitize_text_field($_POST['model_id'] ?? '');
        if (empty($model_id)) wp_send_json_error(['message' => __('Model ID required', 'aiwp')]);
        $favorites = get_option('aiwp_favorite_models', []);
        unset($favorites[$model_id]);
        update_option('aiwp_favorite_models', $favorites);
        wp_send_json_success(['message' => __('Model removed.', 'aiwp'), 'favorites' => $favorites]);
    }

    public static function save_role_config_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_roles')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $config = json_decode(stripslashes($_POST['config'] ?? '{}'), true);
        if (!is_array($config)) wp_send_json_error(['message' => __('Invalid config', 'aiwp')]);
        AIWP_Roles::update_role_config($config);
        wp_send_json_success(['message' => __('Roles saved.', 'aiwp')]);
    }

    public static function get_analysis_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        $analysis = AIWP_Analyzer::get_analysis();
        if (!$analysis) wp_send_json_error(['message' => __('No analysis.', 'aiwp')]);
        wp_send_json_success(['analysis' => $analysis]);
    }

    public static function run_analysis_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_analyze_site')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $analysis = AIWP_Analyzer::analyze_site();
        wp_send_json_success(['analysis' => $analysis]);
    }

    public static function save_memory_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_memory')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $result = AIWP_Memory::save_memory(sanitize_text_field($_POST['category'] ?? ''), sanitize_text_field($_POST['key'] ?? ''), $_POST['value'] ?? '', get_current_user_id());
        wp_send_json_success(['success' => $result]);
    }

    public static function delete_memory_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_memory')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $result = AIWP_Memory::delete_memory(sanitize_text_field($_POST['category'] ?? ''), sanitize_text_field($_POST['key'] ?? ''));
        wp_send_json_success(['success' => $result]);
    }

    public static function save_skill_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_skills')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $data = json_decode(stripslashes($_POST['skill_data'] ?? '{}'), true);
        $result = AIWP_Skills::save(sanitize_text_field($_POST['slug'] ?? ''), $data);
        wp_send_json_success(['success' => $result]);
    }

    public static function delete_skill_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_skills')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        $result = AIWP_Skills::delete(sanitize_text_field($_POST['slug'] ?? ''));
        wp_send_json_success(['success' => $result]);
    }

    public static function list_theme_files_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        wp_send_json_success(AIWP_FileEditor::list_files($_POST['subdir'] ?? ''));
    }

    public static function read_theme_file_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        wp_send_json_success(AIWP_FileEditor::read_file(sanitize_text_field($_POST['file_path'] ?? '')));
    }

    public static function write_theme_file_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        wp_send_json_success(AIWP_FileEditor::write_file(sanitize_text_field($_POST['file_path'] ?? ''), $_POST['content'] ?? ''));
    }
}
