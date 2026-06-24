<?php
defined('ABSPATH') || exit;

class AIWP_Skills {
    const SKILLS_KEY = 'aiwp_skills';
    const MAX_SKILLS = 100;

    public static function get_all(): array {
        $data = get_option(self::SKILLS_KEY, []);
        return $data['skills'] ?? [];
    }

    public static function get(string $slug): ?array {
        $skills = self::get_all();
        return $skills[$slug] ?? null;
    }

    public static function save(string $slug, array $skill_data): bool {
        $data = get_option(self::SKILLS_KEY, ['version' => '1.0', 'skills' => []]);
        if (!isset($data['skills'])) $data['skills'] = [];
        if (count($data['skills']) >= self::MAX_SKILLS && !isset($data['skills'][$slug])) return false;

        $skill_data['updated_at'] = current_time('mysql');
        if (!isset($skill_data['created_at'])) $skill_data['created_at'] = current_time('mysql');
        if (!isset($skill_data['created_by'])) $skill_data['created_by'] = get_current_user_id();
        $data['skills'][$slug] = $skill_data;
        return update_option(self::SKILLS_KEY, $data, false);
    }

    public static function delete(string $slug): bool {
        $data = get_option(self::SKILLS_KEY, ['version' => '1.0', 'skills' => []]);
        if (isset($data['skills'][$slug])) {
            unset($data['skills'][$slug]);
            return update_option(self::SKILLS_KEY, $data, false);
        }
        return false;
    }

    public static function list_skills(string $category = ''): array {
        $skills = self::get_all();
        if (!empty($category)) {
            $skills = array_filter($skills, fn($s) => ($s['category'] ?? '') === $category);
        }
        return $skills;
    }

    public static function execute(string $slug, array $params = []): array {
        $skill = self::get($slug);
        if (!$skill) return ['success' => false, 'error' => "Skill '{$slug}' not found."];

        $results = [];
        $context = ['params' => $params, 'results' => []];

        foreach ($skill['steps'] as $step) {
            if (!empty($step['condition'])) {
                if (!self::check_condition($step['condition'], $context)) {
                    if (!empty($step['optional'])) continue;
                    return ['success' => false, 'error' => "Condition not met for step: {$step['description']}", 'results' => $results];
                }
            }

            $args = self::resolve_args($step['args'] ?? [], $params, $context);
            $tools_manager = aiwp()->get_tools_manager();
            $result = $tools_manager->execute($step['tool'], $args);

            $results[] = ['step_id' => $step['id'], 'tool' => $step['tool'], 'args' => $args, 'result' => $result];
            $context['results']["step_{$step['id']}"] = $result;

            if (!empty($result['success']) && $result['success'] === false && empty($step['optional'])) {
                return ['success' => false, 'error' => "Step {$step['id']} failed: " . ($result['error'] ?? 'Unknown error'), 'results' => $results];
            }
        }

        return ['success' => true, 'skill' => $slug, 'results' => $results, 'message' => "Skill '{$skill['name']}' executed."];
    }

    public static function export(string $slug = ''): string {
        if (!empty($slug)) {
            $skill = self::get($slug);
            return json_encode($skill ? [$slug => $skill] : [], JSON_PRETTY_PRINT);
        }
        $data = get_option(self::SKILLS_KEY, ['version' => '1.0', 'skills' => []]);
        return json_encode($data['skills'] ?? [], JSON_PRETTY_PRINT);
    }

    public static function import(string $json): array {
        $skills = json_decode($json, true);
        if (!is_array($skills)) return ['success' => false, 'error' => 'Invalid JSON'];
        $imported = 0;
        $errors = [];
        foreach ($skills as $slug => $skill_data) {
            if (self::save($slug, $skill_data)) $imported++;
            else $errors[] = $slug;
        }
        return ['success' => empty($errors), 'imported' => $imported, 'errors' => $errors];
    }

    private static function check_condition(string $condition, array $context): bool {
        $parts = explode(':', $condition, 2);
        $type = $parts[0] ?? '';
        $value = $parts[1] ?? '';

        switch ($type) {
            case 'if_not_active':
                $tools_manager = aiwp()->get_tools_manager();
                $result = $tools_manager->execute('wp_get_plugins', ['status' => 'active']);
                if (!empty($result['success']) && !empty($result['plugins'])) {
                    foreach ($result['plugins'] as $plugin) {
                        if ($plugin['slug'] === $value) return false;
                    }
                }
                return true;
            default:
                if (preg_match('/^step_(\d+):check_failed$/', $condition, $m)) {
                    $step_result = $context['results']["step_{$m[1]}"] ?? null;
                    return !$step_result || empty($step_result['success']);
                }
                return true;
        }
    }

    private static function resolve_args(array $args, array $params, array $context): array {
        $resolved = [];
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                if (preg_match('/^\{\{(\w+)\|(.*)\}\}$/', $value, $m)) {
                    $resolved[$key] = $params[$m[1]] ?? $m[2];
                } elseif (preg_match('/^\{\{step_(\d+)\.result\.(\w+)\}\}$/', $value, $m)) {
                    $step_result = $context['results']["step_{$m[1]}"] ?? [];
                    $resolved[$key] = $step_result[$m[2]] ?? '';
                } else {
                    $resolved[$key] = $value;
                }
            } else {
                $resolved[$key] = $value;
            }
        }
        return $resolved;
    }
}
