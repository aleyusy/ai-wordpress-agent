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

        add_submenu_page('aiwp-chat', 'AI Agent — Чат', 'Чат', 'manage_options', 'aiwp-chat', [self::class, 'render_chat_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Анализ', 'Анализ сайта', 'manage_options', 'aiwp-analysis', [self::class, 'render_analysis_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Память', 'Память', 'manage_options', 'aiwp-memory', [self::class, 'render_memory_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Скиллы', 'Скиллы', 'manage_options', 'aiwp-skills', [self::class, 'render_skills_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Файлы', 'Редактор файлов', 'manage_options', 'aiwp-files', [self::class, 'render_files_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Роли', 'Роли и права', 'manage_options', 'aiwp-roles', [self::class, 'render_roles_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Инструменты', 'Инструменты', 'manage_options', 'aiwp-tools', [self::class, 'render_tools_page']);
        add_submenu_page('aiwp-chat', 'AI Agent — Настройки', 'Настройки', 'manage_options', 'aiwp-settings', [self::class, 'render_settings_page']);
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
                    <h1>🤖 AI WordPress Agent</h1>
                    <p class="aiwp-subtitle">Управляй сайтом через чат с ИИ</p>
                </div>
                <div class="aiwp-header-right">
                    <span class="aiwp-status <?php echo $has_api_key ? 'connected' : 'disconnected'; ?>">
                        <?php echo $has_api_key ? '✅ API подключён' : '❌ API не настроен'; ?>
                    </span>
                    <span class="aiwp-status connected" style="margin-left:8px;">
                        🔑 <?php echo count($granted); ?> capabilities
                    </span>
                </div>
            </div>
            <?php if (!$has_api_key): ?>
                <div class="notice notice-warning inline aiwp-notice">
                    <p>⚠️ Настройте API ключ. <a href="<?php echo admin_url('admin.php?page=aiwp-settings'); ?>">Перейти к настройкам</a></p>
                </div>
            <?php endif; ?>
            <div class="aiwp-chat-container">
                <div class="aiwp-toolbar">
                    <button type="button" class="button aiwp-clear-btn" id="aiwp-clear">
                        <span class="dashicons dashicons-trash"></span> Очистить
                    </button>
                </div>
                <div class="aiwp-messages" id="aiwp-messages">
                    <div class="aiwp-message aiwp-message-ai">
                        <div class="aiwp-avatar aiwp-avatar-ai">🤖</div>
                        <div class="aiwp-bubble">
                            Привет! Я AI-агент для управления WordPress. Чем могу помочь?
                            <div class="aiwp-tools-hint">Доступно <strong><?php echo count(aiwp()->get_tools_manager()->get_tool_names()); ?></strong> инструментов</div>
                        </div>
                    </div>
                </div>
                <div class="aiwp-input-area">
                    <div class="aiwp-input-wrapper">
                        <textarea id="aiwp-input" class="aiwp-input" placeholder="Напишите, что нужно сделать..." rows="1"></textarea>
                        <button type="button" class="aiwp-send-btn" id="aiwp-send" disabled>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                    <div class="aiwp-footer-text">AI выполняет действия от вашего имени. Проверяйте результаты.</div>
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
                    <tr><th scope="row"><label for="api_url">API URL</label></th><td><input type="url" id="api_url" name="api_url" value="<?php echo esc_attr($settings['api_url'] ?? 'https://openrouter.ai/api/v1/chat/completions'); ?>" class="regular-text"><p class="description">OpenAI-совместимый эндпоинт</p></td></tr>
                    <tr><th scope="row"><label for="api_key">API Ключ</label></th><td><input type="password" id="api_key" name="api_key" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" placeholder="sk-..."></td></tr>
                    <tr><th scope="row"><label for="model_input">Модель</label></th><td>
                        <?php $models = AIWP_AI::fetch_available_models(); ?>
                        <input type="text" id="model_input" name="model" value="<?php echo esc_attr($settings['model'] ?? 'openai/gpt-4o-mini'); ?>" class="regular-text" list="model_list" autocomplete="off">
                        <datalist id="model_list"><?php foreach ($models as $m): ?><option value="<?php echo esc_attr($m['id']); ?>"><?php echo esc_html($m['name']); ?></option><?php endforeach; ?></datalist>
                        <button type="button" class="button button-small" id="aiwp-fetch-models">🔄 Обновить</button>
                    </td></tr>
                    <tr><th scope="row"><label for="max_tokens">Max Tokens</label></th><td><input type="number" id="max_tokens" name="max_tokens" min="256" max="128000" value="<?php echo esc_attr($settings['max_tokens'] ?? 4096); ?>" class="small-text"></td></tr>
                    <tr><th scope="row"><label for="temperature">Temperature</label></th><td><input type="number" id="temperature" name="temperature" min="0" max="2" step="0.1" value="<?php echo esc_attr($settings['temperature'] ?? 0.7); ?>" class="small-text"></td></tr>
                    <tr><th scope="row"><label for="github_repo">GitHub репозиторий</label></th><td><input type="text" id="github_repo" name="github_repo" value="<?php echo esc_attr($settings['github_repo'] ?? ''); ?>" class="regular-text" placeholder="user/repo"></td></tr>
                </table>
                <p class="submit"><button type="submit" name="save_aiwp_settings" class="button button-primary">Сохранить</button></p>
            </form>
            <hr>
            <h2>Проверка подключения</h2>
            <button type="button" class="button" id="aiwp-test-api">Проверить</button>
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
            <h1>🤖 AI Agent — Анализ сайта</h1>
            <div class="aiwp-flex-between" style="margin-bottom:20px;">
                <p><?php echo $analysis ? 'Последний анализ: ' . $analysis['last_analysis'] : 'Анализ ещё не проводился'; ?></p>
                <button type="button" class="button button-primary" id="aiwp-run-analysis">🔍 <?php echo $analysis ? 'Переанализировать' : 'Запустить анализ'; ?></button>
            </div>
            <div id="aiwp-analysis-result">
            <?php if ($analysis): ?>
                <div class="aiwp-score-cards">
                    <div class="aiwp-score-card"><div class="aiwp-score-value" style="color:<?php echo ($analysis['overall_score']>=70)?'#2e7d32':(($analysis['overall_score']>=40)?'#f57f17':'#c62828'); ?>"><?php echo $analysis['overall_score']; ?></div><div class="aiwp-score-label">Общий балл</div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['security']['score']; ?></div><div class="aiwp-score-label">Безопасность</div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['performance']['score']; ?></div><div class="aiwp-score-label">Скорость</div></div>
                    <div class="aiwp-score-card"><div class="aiwp-score-value"><?php echo $analysis['seo']['score']; ?></div><div class="aiwp-score-label">SEO</div></div>
                </div>
                <h2>Темы</h2>
                <p>Активная: <strong><?php echo esc_html($analysis['themes']['active']['name'] ?? 'N/A'); ?></strong> (v<?php echo esc_html($analysis['themes']['active']['version'] ?? ''); ?>)</p>
                <p>Установлено тем: <?php echo $analysis['themes']['total'] ?? 0; ?></p>
                <h2>Плагины</h2>
                <p>Активных: <strong><?php echo $analysis['plugins']['active'] ?? 0; ?></strong> | Неактивных: <?php echo $analysis['plugins']['inactive'] ?? 0; ?></p>
                <?php if (!empty($analysis['plugins']['security_plugins'])): ?><p>🔒 Безопасность: <?php echo implode(', ', $analysis['plugins']['security_plugins']); ?></p><?php endif; ?>
                <?php if (!empty($analysis['plugins']['seo_plugins'])): ?><p>📈 SEO: <?php echo implode(', ', $analysis['plugins']['seo_plugins']); ?></p><?php endif; ?>
                <?php if (!empty($analysis['plugins']['caching_plugins'])): ?><p>⚡ Кэширование: <?php echo implode(', ', $analysis['plugins']['caching_plugins']); ?></p><?php endif; ?>
                <h2>Проблемы и рекомендации</h2>
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
                <h2>Контент</h2>
                <p>Страниц: <?php echo $analysis['content']['pages']['total'] ?? 0; ?> (опубликовано: <?php echo $analysis['content']['pages']['published'] ?? 0; ?>) | Постов: <?php echo $analysis['content']['posts']['total'] ?? 0; ?> | Комментариев: <?php echo $analysis['content']['comments']['total'] ?? 0; ?></p>
                <h2>Пользователи</h2>
                <p>Всего: <?php echo $analysis['users']['total'] ?? 0; ?></p>
            <?php else: ?>
                <p>Анализ ещё не проводился. Нажмите "Запустить анализ".</p>
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
            <h1>🤖 AI Agent — Память</h1>
            <p>Долговременная память агента. Категории: <strong><?php echo count($memories); ?></strong></p>
            <?php if (empty($memories)): ?>
                <p>Память пуста. Агент начнёт заполнять её по мере работы.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Категория</th><th>Ключ</th><th>Значение</th><th>Создано</th><th>Действия</th></tr></thead>
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
            <h1>🤖 AI Agent — Скиллы</h1>
            <p>Сохранённые воркфлоу: <strong><?php echo count($skills); ?></strong></p>
            <?php if (empty($skills)): ?>
                <p>Скиллов пока нет. Создавайте их через чат с агентом.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Slug</th><th>Название</th><th>Описание</th><th>Категория</th><th>Шагов</th><th>Действия</th></tr></thead>
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
            <h1>🤖 AI Agent — Редактор файлов темы</h1>
            <div class="aiwp-file-editor-layout">
                <div class="aiwp-file-tree" id="aiwp-file-tree">
                    <p>Загрузка...</p>
                </div>
                <div class="aiwp-file-content">
                    <div class="aiwp-file-header" id="aiwp-file-header">
                        <span id="aiwp-file-name">Выберите файл</span>
                        <button type="button" class="button button-primary" id="aiwp-save-file" disabled>💾 Сохранить</button>
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
            echo '<div class="notice notice-success"><p>✅ Роли сохранены.</p></div>';
        }
        $config = AIWP_Roles::get_role_config();
        $caps = AIWP_Roles::get_capabilities();
        ?>
        <div class="wrap">
            <h1>🤖 AI Agent — Роли и права</h1>
            <form method="post">
                <?php wp_nonce_field('aiwp_roles'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Роль</th><?php foreach ($caps as $cap_key => $cap_desc): ?><th title="<?php echo esc_attr($cap_desc); ?>"><?php echo esc_html(str_replace('aiwp_', '', $cap_key)); ?></th><?php endforeach; ?></tr></thead>
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
                <p class="submit"><button type="submit" name="save_aiwp_roles" class="button button-primary">Сохранить роли</button></p>
            </form>
        </div>
        <?php
    }

    // === Tools Page ===

    public static function render_tools_page() {
        $tools = aiwp()->get_tools_manager()->get_tools_list();
        ?>
        <div class="wrap">
            <h1>🤖 AI Agent — Инструменты</h1>
            <p>Всего: <strong><?php echo count($tools); ?></strong></p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>#</th><th>Инструмент</th><th>Описание</th></tr></thead>
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
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => 'Forbidden']);
        $settings = get_option('aiwp_settings', []);
        $api_key = $settings['api_key'] ?? '';
        if (empty($api_key)) wp_send_json_error(['message' => 'API ключ не настроен']);
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
        if (!AIWP_Roles::user_has_capability('aiwp_manage_settings')) wp_send_json_error(['message' => 'Forbidden']);
        AIWP_AI::clear_models_cache();
        wp_send_json_success(['models' => AIWP_AI::fetch_available_models()]);
    }

    public static function save_role_config_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_roles')) wp_send_json_error(['message' => 'Forbidden']);
        $config = json_decode(stripslashes($_POST['config'] ?? '{}'), true);
        if (!is_array($config)) wp_send_json_error(['message' => 'Invalid config']);
        AIWP_Roles::update_role_config($config);
        wp_send_json_success(['message' => 'Roles saved.']);
    }

    public static function get_analysis_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        $analysis = AIWP_Analyzer::get_analysis();
        if (!$analysis) wp_send_json_error(['message' => 'No analysis.']);
        wp_send_json_success(['analysis' => $analysis]);
    }

    public static function run_analysis_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_analyze_site')) wp_send_json_error(['message' => 'Forbidden']);
        $analysis = AIWP_Analyzer::analyze_site();
        wp_send_json_success(['analysis' => $analysis]);
    }

    public static function save_memory_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_memory')) wp_send_json_error(['message' => 'Forbidden']);
        $result = AIWP_Memory::save_memory(sanitize_text_field($_POST['category'] ?? ''), sanitize_text_field($_POST['key'] ?? ''), $_POST['value'] ?? '', get_current_user_id());
        wp_send_json_success(['success' => $result]);
    }

    public static function delete_memory_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_memory')) wp_send_json_error(['message' => 'Forbidden']);
        $result = AIWP_Memory::delete_memory(sanitize_text_field($_POST['category'] ?? ''), sanitize_text_field($_POST['key'] ?? ''));
        wp_send_json_success(['success' => $result]);
    }

    public static function save_skill_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_skills')) wp_send_json_error(['message' => 'Forbidden']);
        $data = json_decode(stripslashes($_POST['skill_data'] ?? '{}'), true);
        $result = AIWP_Skills::save(sanitize_text_field($_POST['slug'] ?? ''), $data);
        wp_send_json_success(['success' => $result]);
    }

    public static function delete_skill_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_manage_skills')) wp_send_json_error(['message' => 'Forbidden']);
        $result = AIWP_Skills::delete(sanitize_text_field($_POST['slug'] ?? ''));
        wp_send_json_success(['success' => $result]);
    }

    public static function list_theme_files_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => 'Forbidden']);
        wp_send_json_success(AIWP_FileEditor::list_files($_POST['subdir'] ?? ''));
    }

    public static function read_theme_file_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => 'Forbidden']);
        wp_send_json_success(AIWP_FileEditor::read_file(sanitize_text_field($_POST['file_path'] ?? '')));
    }

    public static function write_theme_file_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!AIWP_Roles::user_has_capability('aiwp_edit_code')) wp_send_json_error(['message' => 'Forbidden']);
        wp_send_json_success(AIWP_FileEditor::write_file(sanitize_text_field($_POST['file_path'] ?? ''), $_POST['content'] ?? ''));
    }
}
