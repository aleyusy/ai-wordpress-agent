<?php

defined('ABSPATH') || exit;

class AIWP_Frontend {
    public static function init() {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_footer', [self::class, 'render_widget']);
        add_action('wp_ajax_aiwp_page_context', [self::class, 'page_context_ajax']);
    }

    public static function enqueue_assets() {
        if (!self::can_use_chat()) {
            return;
        }

        $settings = get_option('aiwp_settings', []);
        if (empty($settings['frontend_chat'])) {
            return;
        }

        wp_enqueue_style(
            'aiwp-widget',
            AIWP_URL . 'assets/css/chat-widget.css',
            [],
            AIWP_VERSION
        );

        wp_enqueue_script(
            'aiwp-widget',
            AIWP_URL . 'assets/js/chat-widget.js',
            [],
            AIWP_VERSION,
            true
        );

        $page_context = self::get_page_context();

        wp_localize_script('aiwp-widget', 'AIWP_WIDGET', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aiwp_chat_nonce'),
            'site_name' => get_bloginfo('name'),
            'page' => $page_context,
            'debug' => WP_DEBUG,
            'position' => $settings['widget_position'] ?? 'right',
        ]);
    }

    public static function render_widget() {
        if (!self::can_use_chat()) {
            return;
        }

        $settings = get_option('aiwp_settings', []);
        if (empty($settings['frontend_chat'])) {
            return;
        }

        $has_api_key = !empty($settings['api_key']);
        ?>
        <div id="aiwp-widget" class="aiwp-widget" style="display:none;">
            <button id="aiwp-widget-toggle" class="aiwp-widget-toggle" aria-label="AI Agent">
                <span class="aiwp-widget-icon">🤖</span>
                <span class="aiwp-widget-close">✕</span>
            </button>
            <div id="aiwp-widget-panel" class="aiwp-widget-panel">
                <div class="aiwp-widget-header">
                    <div class="aiwp-widget-header-info">
                        <span class="aiwp-widget-avatar">🤖</span>
                        <div>
                            <strong>AI Agent</strong>
                            <span class="aiwp-widget-status <?php echo $has_api_key ? 'online' : 'offline'; ?>">
                                <?php echo $has_api_key ? __('В сети', 'aiwp') : __('Не настроен', 'aiwp'); ?>
                            </span>
                        </div>
                    </div>
                    <button class="aiwp-widget-minimize" id="aiwp-widget-minimize">—</button>
                </div>
                <div class="aiwp-widget-context" id="aiwp-widget-context">
                    <span class="aiwp-widget-context-icon">📄</span>
                    <span class="aiwp-widget-context-text" id="aiwp-widget-context-text"><?php esc_html_e('Загрузка...', 'aiwp'); ?></span>
                </div>
                <div class="aiwp-widget-messages" id="aiwp-widget-messages">
                    <div class="aiwp-widget-message aiwp-widget-message-ai">
                        <div class="aiwp-widget-bubble">
                            <?php esc_html_e('Привет! Я AI-агент этого сайта. Могу помочь с управлением. Что нужно сделать?', 'aiwp'); ?>
                        </div>
                    </div>
                </div>
                <?php if (!$has_api_key): ?>
                    <div class="aiwp-widget-error">
                        <?php echo sprintf(esc_html__('⚠️ API ключ не настроен. Перейдите в %sнастройки%s.', 'aiwp'), '<a href="' . admin_url('admin.php?page=aiwp-settings') . '">', '</a>'); ?>
                    </div>
                <?php endif; ?>
                <div class="aiwp-widget-input-area">
                    <div class="aiwp-widget-input-wrap">
                        <textarea id="aiwp-widget-input" class="aiwp-widget-input"
                            placeholder="<?php esc_attr_e('Напишите что нужно сделать...', 'aiwp'); ?>"
                            rows="1"></textarea>
                        <button id="aiwp-widget-send" class="aiwp-widget-send" disabled>
                            ➤
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function page_context_ajax() {
        check_ajax_referer('aiwp_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden', 'aiwp')]);
        }
        wp_send_json_success(['page' => self::get_page_context()]);
    }

    private static function get_page_context(): array {
        $context = [
            'url' => '',
            'title' => '',
            'type' => 'frontpage',
            'id' => 0,
            'template' => '',
        ];

        if (is_front_page()) {
            $context['type'] = 'frontpage';
            $context['title'] = get_bloginfo('name');
            $context['url'] = home_url();
        } elseif (is_home()) {
            $context['type'] = 'blog';
            $context['title'] = get_the_title(get_option('page_for_posts'));
            $context['url'] = get_permalink(get_option('page_for_posts'));
        } elseif (is_singular()) {
            $post = get_queried_object();
            $context['type'] = get_post_type();
            $context['title'] = get_the_title($post);
            $context['url'] = get_permalink($post);
            $context['id'] = $post->ID;
            $context['template'] = get_page_template_slug($post->ID);
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            $context['type'] = 'taxonomy';
            $context['title'] = $term->name;
            $context['url'] = get_term_link($term);
            $context['id'] = $term->term_id;
            $context['taxonomy'] = $term->taxonomy;
        } elseif (is_search()) {
            $context['type'] = 'search';
            $context['title'] = __('Поиск: ', 'aiwp') . get_search_query();
            $context['url'] = home_url('?s=' . get_search_query());
        } elseif (is_404()) {
            $context['type'] = '404';
            $context['title'] = __('Страница не найдена (404)', 'aiwp');
        } elseif (is_author()) {
            $context['type'] = 'author';
            $context['title'] = __('Автор: ', 'aiwp') . get_the_author();
        } elseif (is_archive()) {
            $context['type'] = 'archive';
            $context['title'] = get_the_archive_title();
            $context['url'] = get_archive_link() ?: '';
        }

        if (empty($context['url'])) {
            $context['url'] = home_url(add_query_arg([]));
        }

        return $context;
    }

    private static function can_use_chat(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        return current_user_can('manage_options');
    }
}
