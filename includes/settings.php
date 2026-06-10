<?php
function get_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        try {
            $stmt = db()->prepare("SELECT value FROM settings WHERE key_name = ?");
            $stmt->execute([$key]);
            $cache[$key] = $stmt->fetchColumn() ?: $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
    return $cache[$key];
}

function set_setting(string $key, string $value): void {
    db()->prepare("INSERT INTO settings (key_name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
        ->execute([$key, $value, $value]);
}
