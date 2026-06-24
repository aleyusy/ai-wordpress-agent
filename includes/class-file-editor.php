<?php
defined('ABSPATH') || exit;

class AIWP_FileEditor {
    const ALLOWED_EXTENSIONS = ['php', 'css', 'js', 'txt', 'html'];
    const MAX_FILE_SIZE = 512000;

    private static function get_theme_dir(): string {
        return get_theme_file_path('/');
    }

    private static function validate_path(string $path): ?string {
        $real_theme = realpath(self::get_theme_dir());
        if ($real_theme === false) return null;
        $real_path = realpath($path);
        if ($real_path === false) return null;
        if (strpos($real_path, $real_theme) !== 0) return null;
        $ext = pathinfo($real_path, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), self::ALLOWED_EXTENSIONS, true)) return null;
        return $real_path;
    }

    public static function list_files(string $subdir = '', bool $recursive = true): array {
        $theme_dir = self::get_theme_dir();
        $target = $subdir ? trailingslashit($theme_dir) . $subdir : $theme_dir;
        $target = rtrim($target, '/\\');

        if (!is_dir($target)) return ['success' => false, 'error' => 'Directory not found.'];

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
            $recursive ? RecursiveIteratorIterator::SELF_FIRST : RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $relative = ltrim(str_replace($theme_dir, '', $file->getPathname()), '/\\');
            if ($file->isDir()) {
                $files[] = ['path' => $relative, 'type' => 'directory', 'name' => $file->getFilename()];
            } else {
                $ext = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
                if (in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    $files[] = [
                        'path' => $relative,
                        'type' => 'file',
                        'name' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                }
            }
        }

        return ['success' => true, 'files' => $files, 'total' => count($files)];
    }

    public static function read_file(string $file_path): array {
        $full_path = self::get_theme_dir() . '/' . ltrim($file_path, '/\\');
        $validated = self::validate_path($full_path);
        if (!$validated) return ['success' => false, 'error' => 'Invalid file path or not allowed.'];
        if (!file_exists($validated)) return ['success' => false, 'error' => 'File not found.'];
        if (filesize($validated) > self::MAX_FILE_SIZE) return ['success' => false, 'error' => 'File too large (max 500KB).'];

        $content = file_get_contents($validated);
        if ($content === false) return ['success' => false, 'error' => 'Failed to read file.'];

        return [
            'success' => true,
            'path' => $file_path,
            'content' => $content,
            'size' => filesize($validated),
            'modified' => date('Y-m-d H:i:s', filemtime($validated)),
        ];
    }

    public static function write_file(string $file_path, string $content): array {
        $full_path = self::get_theme_dir() . '/' . ltrim($file_path, '/\\');
        $validated = self::validate_path($full_path);
        if (!$validated) return ['success' => false, 'error' => 'Invalid file path or not allowed.'];

        if (file_exists($validated)) {
            $backup_dir = self::get_theme_dir() . '/.aiwp-backups/';
            if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
            $backup_name = str_replace(['/', '\\'], '_', $file_path) . '.' . date('Y-m-d-His') . '.bak';
            copy($validated, $backup_dir . $backup_name);
        }

        $dir = dirname($validated);
        if (!is_dir($dir)) wp_mkdir_p($dir);

        $result = file_put_contents($validated, $content);
        if ($result === false) return ['success' => false, 'error' => 'Failed to write file.'];

        self::log_edit($file_path, get_current_user_id());

        return [
            'success' => true,
            'path' => $file_path,
            'bytes_written' => $result,
            'message' => "File '{$file_path}' saved successfully.",
        ];
    }

    public static function edit_file(string $file_path, string $old_content, string $new_content): array {
        $read_result = self::read_file($file_path);
        if (!$read_result['success']) return $read_result;

        $content = $read_result['content'];
        if (strpos($content, $old_content) === false) return ['success' => false, 'error' => 'Old content not found in file.'];

        $updated = str_replace($old_content, $new_content, $content);
        return self::write_file($file_path, $updated);
    }

    public static function get_templates(): array {
        $theme_dir = self::get_theme_dir();
        $templates = [];
        $standard = [
            'index.php' => 'Main Index', 'header.php' => 'Header', 'footer.php' => 'Footer',
            'sidebar.php' => 'Sidebar', 'single.php' => 'Single Post', 'page.php' => 'Page Template',
            'archive.php' => 'Archive', 'search.php' => 'Search Results', '404.php' => '404 Error',
            'comments.php' => 'Comments', 'style.css' => 'Stylesheet', 'functions.php' => 'Functions',
        ];

        foreach ($standard as $file => $desc) {
            $path = $theme_dir . '/' . $file;
            if (file_exists($path)) {
                $templates[] = ['file' => $file, 'name' => $desc, 'size' => filesize($path), 'modified' => date('Y-m-d H:i:s', filemtime($path))];
            }
        }

        $pt_dir = $theme_dir . '/page-templates';
        if (is_dir($pt_dir)) {
            foreach (glob($pt_dir . '/*.php') as $file) {
                $templates[] = ['file' => 'page-templates/' . basename($file), 'name' => 'Page Template: ' . pathinfo($file, PATHINFO_FILENAME), 'size' => filesize($file), 'modified' => date('Y-m-d H:i:s', filemtime($file))];
            }
        }

        return ['success' => true, 'templates' => $templates];
    }

    public static function get_css_files(): array {
        $theme_dir = self::get_theme_dir();
        $css_files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
                $relative = ltrim(str_replace($theme_dir, '', $file->getPathname()), '/\\');
                $css_files[] = ['path' => $relative, 'name' => $file->getFilename(), 'size' => $file->getSize(), 'modified' => date('Y-m-d H:i:s', $file->getMTime())];
            }
        }
        return ['success' => true, 'css_files' => $css_files];
    }

    private static function log_edit(string $file_path, int $user_id): void {
        $logs = get_option('aiwp_file_edit_logs', []);
        if (!is_array($logs)) $logs = [];
        $logs[] = ['file' => $file_path, 'user_id' => $user_id, 'timestamp' => current_time('mysql')];
        if (count($logs) > 100) $logs = array_slice($logs, -100);
        update_option('aiwp_file_edit_logs', $logs, false);
    }

    public static function get_edit_logs(int $limit = 50): array {
        $logs = get_option('aiwp_file_edit_logs', []);
        return array_slice(array_reverse($logs), 0, $limit);
    }

    public static function restore_backup(string $file_path, string $backup_name): array {
        $theme_dir = self::get_theme_dir();
        $backup_path = $theme_dir . '/.aiwp-backups/' . basename($backup_name);
        $full_path = $theme_dir . '/' . ltrim($file_path, '/\\');

        if (!file_exists($backup_path)) return ['success' => false, 'error' => 'Backup not found.'];
        $validated = self::validate_path($full_path);
        if (!$validated) return ['success' => false, 'error' => 'Invalid file path.'];

        $result = copy($backup_path, $validated);
        return $result ? ['success' => true, 'message' => "File restored from backup."] : ['success' => false, 'error' => 'Failed to restore.'];
    }
}
