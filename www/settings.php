<?php
// Settings management for the Tasks application
require_once __DIR__ . '/config.php';

class Settings {
    private static function pdo(): PDO {
        return pdo();
    }

    public static function get(string $key, string $default = ''): string {
        $st = self::pdo()->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : $default;
    }

    public static function set(string $key, string $value): bool {
        $st = self::pdo()->prepare(
            'INSERT INTO settings (key_name, value) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        return $st->execute([$key, $value]);
    }

    public static function siteTitle(): string {
        return self::get('site_title', APP_NAME);
    }

    public static function announcement(): string {
        return self::get('announcement', '');
    }

    public static function timezone(): string {
        return self::get('timezone', date_default_timezone_get());
    }

    public static function loginImageUrl(): string {
        $fileId = self::get('login_image_file_id', '');
        if ($fileId === '' || !is_numeric($fileId)) {
            return '';
        }
        
        require_once __DIR__ . '/lib/Files.php';
        return Files::publicFileImageUrl((int)$fileId, 200);
    }
}
