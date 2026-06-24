<?php
defined('ABSPATH') || exit;

class AIWP_Analyzer {
    const ANALYSIS_KEY = 'aiwp_site_analysis';
    const CACHE_TTL = 86400;

    public static function analyze_site(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $analysis = [
            'version' => '1.0',
            'last_analysis' => current_time('mysql'),
            'themes' => self::analyze_themes(),
            'plugins' => self::analyze_plugins(),
            'security' => self::analyze_security(),
            'performance' => self::analyze_performance(),
            'seo' => self::analyze_seo(),
            'content' => self::analyze_content(),
            'users' => self::analyze_users(),
            'overall_score' => 0,
        ];

        $scores = array_filter([
            $analysis['security']['score'] ?? 0,
            $analysis['performance']['score'] ?? 0,
            $analysis['seo']['score'] ?? 0,
        ]);
        $analysis['overall_score'] = !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 0;

        update_option(self::ANALYSIS_KEY, $analysis, false);
        AIWP_Memory::save_memory('site_analysis', 'last', $analysis);

        return $analysis;
    }

    public static function get_analysis(): ?array {
        $analysis = get_option(self::ANALYSIS_KEY);
        return is_array($analysis) ? $analysis : null;
    }

    public static function is_stale(): bool {
        $analysis = self::get_analysis();
        if (!$analysis) return true;
        $last = strtotime($analysis['last_analysis'] ?? '0');
        return (time() - $last) > self::CACHE_TTL;
    }

    private static function analyze_themes(): array {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $installed = array_keys($all_themes);
        return [
            'active' => [
                'name' => $active_theme->get('Name'),
                'version' => $active_theme->get('Version'),
                'author' => $active_theme->get('Author'),
            ],
            'installed' => $installed,
            'child_theme' => is_child_theme() ? wp_get_theme()->parent()->get('Name') : null,
            'total' => count($installed),
        ];
    }

    private static function analyze_plugins(): array {
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $active = [];
        $inactive = [];
        $security = ['wordfence', 'sucuri', 'ithemes-security', 'all-in-one-wp-security', 'wordfence-security'];
        $seo = ['wordpress-seo', 'all-in-one-seo-pack', 'rank-math', 'seo-by-rank-math'];
        $caching = ['wp-super-cache', 'w3-total-cache', 'litespeed-cache', 'wp-rocket', ' wp-fastest-cache'];
        $found_security = [];
        $found_seo = [];
        $found_caching = [];

        foreach ($all_plugins as $path => $data) {
            $slug = dirname($path);
            $is_active = in_array($path, $active_plugins, true);
            $entry = ['name' => $data['Name'], 'slug' => $slug, 'version' => $data['Version'], 'active' => $is_active];
            if ($is_active) $active[] = $entry;
            else $inactive[] = $entry;
            if (in_array($slug, $security)) $found_security[] = $slug;
            if (in_array($slug, $seo)) $found_seo[] = $slug;
            if (in_array($slug, $caching)) $found_caching[] = $slug;
        }

        return [
            'total' => count($all_plugins),
            'active' => count($active),
            'inactive' => count($inactive),
            'active_list' => $active,
            'inactive_list' => $inactive,
            'security_plugins' => $found_security,
            'seo_plugins' => $found_seo,
            'caching_plugins' => $found_caching,
        ];
    }

    private static function analyze_security(): array {
        $issues = [];
        $score = 100;

        if (defined('XMLRPC_ENABLED') && XMLRPC_ENABLED) {
            $issues[] = ['severity' => 'high', 'message' => 'XML-RPC is enabled'];
            $score -= 15;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $issues[] = ['severity' => 'medium', 'message' => 'WP_DEBUG is enabled in production'];
            $score -= 10;
        }
        if (!(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)) {
            $issues[] = ['severity' => 'medium', 'message' => 'File editing is not disabled'];
            $score -= 5;
        }
        $admin = get_userdata(1);
        if ($admin && $admin->user_login === 'admin') {
            $issues[] = ['severity' => 'high', 'message' => 'Default "admin" username detected'];
            $score -= 20;
        }
        $analysis = get_option(self::ANALYSIS_KEY, []);
        if (empty($analysis['plugins']['security_plugins'] ?? [])) {
            $issues[] = ['severity' => 'high', 'message' => 'No security plugin installed'];
            $score -= 15;
        }

        $score = max(0, $score);
        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => self::generate_recommendations($issues, 'security'),
        ];
    }

    private static function analyze_performance(): array {
        $issues = [];
        $score = 100;

        $analysis = get_option(self::ANALYSIS_KEY, []);
        if (empty($analysis['plugins']['caching_plugins'] ?? [])) {
            $issues[] = ['type' => 'plugin', 'message' => 'No caching plugin installed'];
            $score -= 20;
        }
        $wp_version = get_bloginfo('version');
        if (version_compare($wp_version, '6.0', '<')) {
            $issues[] = ['type' => 'version', 'message' => 'WordPress version is outdated (' . $wp_version . ')'];
            $score -= 10;
        }
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $issues[] = ['type' => 'php', 'message' => 'PHP version below 8.0 (current: ' . PHP_VERSION . ')'];
            $score -= 10;
        }

        $score = max(0, $score);
        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => self::generate_recommendations($issues, 'performance'),
        ];
    }

    private static function analyze_seo(): array {
        $issues = [];
        $score = 100;

        $analysis = get_option(self::ANALYSIS_KEY, []);
        if (empty($analysis['plugins']['seo_plugins'] ?? [])) {
            $issues[] = ['type' => 'plugin', 'message' => 'No SEO plugin installed'];
            $score -= 20;
        }
        $tagline = get_bloginfo('description');
        if (empty($tagline) || $tagline === 'Just another WordPress site') {
            $issues[] = ['type' => 'meta', 'message' => 'Default or missing site tagline'];
            $score -= 10;
        }
        $permalink = get_option('permalink_structure');
        if (empty($permalink) || $permalink === '/?p=%post_id%') {
            $issues[] = ['type' => 'structure', 'message' => 'Plain permalink structure (not SEO-friendly)'];
            $score -= 15;
        }
        $robots_path = ABSPATH . 'robots.txt';
        if (!file_exists($robots_path)) {
            $issues[] = ['type' => 'structure', 'message' => 'No robots.txt file found'];
            $score -= 5;
        }

        $score = max(0, $score);
        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => self::generate_recommendations($issues, 'seo'),
        ];
    }

    private static function analyze_content(): array {
        $page_counts = wp_count_posts('page');
        $post_counts = wp_count_posts('post');
        $comment_counts = wp_count_comments();
        $media_count = wp_count_posts('attachment');
        return [
            'pages' => ['total' => $page_counts->total ?? 0, 'published' => $page_counts->publish ?? 0, 'draft' => $page_counts->draft ?? 0],
            'posts' => ['total' => $post_counts->total ?? 0, 'published' => $post_counts->publish ?? 0, 'draft' => $post_counts->draft ?? 0],
            'media' => ['total' => $media_count->total ?? 0],
            'comments' => ['total' => $comment_counts->total_comments ?? 0, 'pending' => $comment_counts->moderated ?? 0, 'spam' => $comment_counts->spam ?? 0],
        ];
    }

    private static function analyze_users(): array {
        $users = count_users();
        return ['total' => $users['total_users'] ?? 0, 'roles' => $users['avail_roles'] ?? []];
    }

    private static function generate_recommendations(array $issues, string $category): array {
        $recs = [];
        foreach ($issues as $issue) {
            $msg = $issue['message'] ?? '';
            if (strpos($msg, 'XML-RPC') !== false) $recs[] = 'Disable XML-RPC via plugin or .htaccess';
            elseif (strpos($msg, 'admin') !== false) $recs[] = 'Rename the default admin user account';
            elseif (strpos($msg, 'security plugin') !== false) $recs[] = 'Install a security plugin (Wordfence, Sucuri)';
            elseif (strpos($msg, 'caching') !== false) $recs[] = 'Install a caching plugin (WP Super Cache, LiteSpeed Cache)';
            elseif (strpos($msg, 'PHP') !== false) $recs[] = 'Upgrade PHP to 8.0+';
            elseif (strpos($msg, 'outdated') !== false) $recs[] = 'Update WordPress to the latest version';
            elseif (strpos($msg, 'SEO plugin') !== false) $recs[] = 'Install Yoast SEO or Rank Math';
            elseif (strpos($msg, 'tagline') !== false) $recs[] = 'Set a descriptive site tagline';
            elseif (strpos($msg, 'permalink') !== false) $recs[] = 'Change permalink structure to "Post name"';
            elseif (strpos($msg, 'robots.txt') !== false) $recs[] = 'Create a robots.txt file';
            elseif (strpos($msg, 'WP_DEBUG') !== false) $recs[] = 'Disable WP_DEBUG in production';
            elseif (strpos($msg, 'File editing') !== false) $recs[] = 'Add DISALLOW_FILE_EDIT to wp-config.php';
        }
        return $recs;
    }
}
