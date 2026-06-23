<?php

defined('ABSPATH') || exit;

class AIWP_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $github_repo;
    private $github_api_url;

    public function __construct($file) {
        $this->file = $file;
        $this->plugin = get_file_data($file, ['Version' => 'Version']);
        $this->basename = plugin_basename($file);

        $settings = get_option('aiwp_settings', []);
        $repo = !empty($settings['github_repo']) ? $settings['github_repo'] : '';

        $this->github_repo = $repo;
        $this->github_api_url = !empty($repo)
            ? 'https://api.github.com/repos/' . $repo . '/releases'
            : '';
    }

    public function init() {
        if (empty($this->github_repo)) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_package_name'], 10, 3);
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if (!$release) {
            return $transient;
        }

        $latest = ltrim($release['tag_name'], 'vV');
        $current = $this->plugin['Version'];

        if (version_compare($latest, $current, '>')) {
            $transient->response[$this->basename] = (object) [
                'slug' => dirname($this->basename),
                'plugin' => $this->basename,
                'new_version' => $latest,
                'package' => $release['zipball_url'],
                'url' => 'https://github.com/' . $this->github_repo,
                'tested' => $this->get_tested_wp_version($release),
                'requires_php' => $this->get_release_php_version($release),
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->basename)) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if (!$release) {
            return $result;
        }

        $readme = $this->fetch_readme();

        return (object) [
            'name' => 'AI WordPress Agent',
            'slug' => dirname($this->basename),
            'version' => ltrim($release['tag_name'], 'vV'),
            'author' => '<a href="https://github.com/' . $this->github_repo . '">AIWP Team</a>',
            'homepage' => 'https://github.com/' . $this->github_repo,
            'requires' => '5.8',
            'tested' => $this->get_tested_wp_version($release),
            'requires_php' => $this->get_release_php_version($release),
            'downloaded' => 0,
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'ИИ-агент для управления WordPress через чат.',
                'changelog' => $release['body'] ?: 'См. релиз на GitHub.',
                'notes' => $readme,
            ],
            'download_link' => $release['zipball_url'],
            'banners' => [],
        ];
    }

    public function fix_package_name($source, $remote_source, $upgrader) {
        global $wp_filesystem;

        if (strpos($source, 'github.com') === false) {
            return $source;
        }

        $correct_name = dirname($this->basename);
        $expected = trailingslashit($remote_source) . $correct_name;

        if ($source === $expected) {
            return $source;
        }

        if ($wp_filesystem && !$wp_filesystem->move($source, $expected, true)) {
            return $source;
        }

        return $expected;
    }

    private function fetch_latest_release() {
        $cache_key = 'aiwp_github_release_' . md5($this->github_repo);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($this->github_api_url . '/latest', [
            'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress-AIWP'],
            'timeout' => 15,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) {
            return null;
        }

        set_transient($cache_key, $body, HOUR_IN_SECONDS);
        return $body;
    }

    private function fetch_readme(): string {
        $response = wp_remote_get('https://raw.githubusercontent.com/' . $this->github_repo . '/main/README.md', [
            'timeout' => 10,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        return wp_remote_retrieve_body($response);
    }

    private function get_tested_wp_version($release): string {
        $body = $release['body'] ?? '';
        if (preg_match('/Tested up to:\s*([0-9.]+)/i', $body, $m)) {
            return $m[1];
        }
        global $wp_version;
        return $wp_version;
    }

    private function get_release_php_version($release): string {
        $body = $release['body'] ?? '';
        if (preg_match('/Requires PHP:\s*([0-9.]+)/i', $body, $m)) {
            return $m[1];
        }
        return '7.4';
    }
}
