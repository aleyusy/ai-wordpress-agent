<?php
defined('ABSPATH') || exit;

class AIWP_Plugin_Search {
    const WP_API_BASE = 'https://api.wordpress.org/plugins/info/1.2/';
    const CACHE_TTL = 3600;

    public static function search(string $query, int $page = 1, int $per_page = 10): array {
        $cache_key = 'aiwp_plugin_search_' . md5($query . $page . $per_page);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get(self::WP_API_BASE, [
            'body' => [
                'action' => 'query_plugins',
                'search' => sanitize_text_field($query),
                'page' => $page,
                'per_page' => $per_page,
                'fields' => ['short_description' => true, 'sections' => false, 'tags' => false, 'ratings' => true, 'active_installs' => true],
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['plugins'])) return ['success' => false, 'error' => 'No results found.'];

        $plugins = [];
        foreach ($body['plugins'] as $plugin) {
            $plugins[] = [
                'slug' => $plugin['slug'],
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'short_description' => $plugin['short_description'] ?? '',
                'author' => $plugin['author'] ?? '',
                'active_installs' => $plugin['active_installs'] ?? 0,
                'rating' => $plugin['rating'] ?? 0,
                'num_ratings' => $plugin['num_ratings'] ?? 0,
                'last_updated' => $plugin['last_updated'] ?? '',
                'requires_wp' => $plugin['requires'] ?? '',
                'tested' => $plugin['tested'] ?? '',
            ];
        }

        $result = [
            'success' => true,
            'plugins' => $plugins,
            'total' => $body['info']['results'] ?? 0,
            'page' => $page,
            'pages' => $body['info']['pages'] ?? 1,
        ];

        set_transient($cache_key, $result, self::CACHE_TTL);
        return $result;
    }

    public static function get_plugin_info(string $slug): array {
        $cache_key = 'aiwp_plugin_info_' . $slug;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get(self::WP_API_BASE, [
            'body' => [
                'action' => 'plugin_information',
                'slug' => sanitize_text_field($slug),
                'fields' => ['short_description' => true, 'sections' => true, 'tags' => true, 'ratings' => true, 'active_installs' => true],
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $plugin = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($plugin['slug'])) return ['success' => false, 'error' => 'Plugin not found.'];

        $result = [
            'success' => true,
            'slug' => $plugin['slug'],
            'name' => $plugin['name'],
            'version' => $plugin['version'],
            'author' => $plugin['author'] ?? '',
            'short_description' => $plugin['short_description'] ?? '',
            'description' => $plugin['sections']['description'] ?? '',
            'installation' => $plugin['sections']['installation'] ?? '',
            'faq' => $plugin['sections']['faq'] ?? '',
            'changelog' => $plugin['sections']['changelog'] ?? '',
            'active_installs' => $plugin['active_installs'] ?? 0,
            'rating' => $plugin['rating'] ?? 0,
            'num_ratings' => $plugin['num_ratings'] ?? 0,
            'last_updated' => $plugin['last_updated'] ?? '',
            'requires_wp' => $plugin['requires'] ?? '',
            'tested' => $plugin['tested'] ?? '',
            'requires_php' => $plugin['requires_php'] ?? '',
            'tags' => $plugin['tags'] ?? [],
            'homepage' => $plugin['homepage'] ?? '',
        ];

        set_transient($cache_key, $result, self::CACHE_TTL);
        return $result;
    }

    public static function analyze_compatibility(string $slug): array {
        $info = self::get_plugin_info($slug);
        if (!$info['success']) return $info;

        $issues = [];
        $warnings = [];

        if (!empty($info['requires_wp'])) {
            $current = get_bloginfo('version');
            if (version_compare($current, $info['requires_wp'], '<')) {
                $issues[] = "Requires WordPress {$info['requires_wp']} (current: {$current})";
            }
        }
        if (!empty($info['requires_php'])) {
            if (version_compare(PHP_VERSION, $info['requires_php'], '<')) {
                $issues[] = "Requires PHP {$info['requires_php']} (current: " . PHP_VERSION . ")";
            }
        }
        if (!empty($info['tested'])) {
            $current = get_bloginfo('version');
            if (version_compare($current, $info['tested'], '>')) {
                $warnings[] = "Last tested with WordPress {$info['tested']} (current: {$current})";
            }
        }
        if (!empty($info['last_updated'])) {
            $months_old = (time() - strtotime($info['last_updated'])) / (30 * 86400);
            if ($months_old > 12) {
                $warnings[] = "Not updated in " . round($months_old) . " months";
            }
        }
        if ($info['rating'] > 0 && $info['num_ratings'] > 10 && $info['rating'] < 3) {
            $warnings[] = "Low rating: {$info['rating']}/5 ({$info['num_ratings']} ratings)";
        }

        $conflicts = self::get_known_conflicts($slug);
        $analysis = AIWP_Analyzer::get_analysis();
        if ($analysis && !empty($analysis['plugins'])) {
            $active = array_column($analysis['plugins']['active_list'] ?? [], 'slug');
            foreach ($conflicts as $conflict) {
                if (in_array($conflict, $active)) {
                    $issues[] = "May conflict with: {$conflict}";
                }
            }
        }

        $recs = [];
        if (empty($issues) && empty($warnings)) $recs[] = "Plugin appears compatible.";
        if (($info['active_installs'] ?? 0) > 100000) $recs[] = "Widely used ({$info['active_installs']}+ installs).";
        if (($info['rating'] ?? 0) >= 4 && ($info['num_ratings'] ?? 0) > 50) $recs[] = "Highly rated ({$info['rating']}/5).";

        return [
            'success' => true,
            'slug' => $slug,
            'name' => $info['name'],
            'compatible' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => $recs,
        ];
    }

    private static function get_known_conflicts(string $slug): array {
        $conflicts = [
            'akismet' => ['anti-spam-bee'],
            'wordfence' => ['sucuri-security'],
            'wp-super-cache' => ['w3-total-cache', 'wp-rocket'],
            'jetpack' => ['wp-super-cache', 'w3-total-cache'],
            'wordpress-seo' => ['all-in-one-seo-pack', 'rank-math'],
        ];
        return $conflicts[$slug] ?? [];
    }

    public static function get_trending(string $tag = '', int $per_page = 10): array {
        $cache_key = 'aiwp_plugin_trending_' . $tag;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $args = ['action' => 'query_plugins', 'browse' => 'popular', 'per_page' => $per_page];
        if (!empty($tag)) $args['tag'] = sanitize_text_field($tag);

        $response = wp_remote_get(self::WP_API_BASE, ['body' => $args, 'timeout' => 15]);
        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $plugins = [];
        foreach ($body['plugins'] ?? [] as $plugin) {
            $plugins[] = [
                'slug' => $plugin['slug'],
                'name' => $plugin['name'],
                'short_description' => $plugin['short_description'] ?? '',
                'active_installs' => $plugin['active_installs'] ?? 0,
                'rating' => $plugin['rating'] ?? 0,
            ];
        }

        $result = ['success' => true, 'plugins' => $plugins];
        set_transient($cache_key, $result, self::CACHE_TTL * 6);
        return $result;
    }
}
