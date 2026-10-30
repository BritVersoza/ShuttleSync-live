<?php
/**
 * Configuration loader - loads .env file and provides config access
 */
class Config {
    private static $loaded = false;
    private static $config = [];

    public static function load(string $envPath = __DIR__ . '/../.env'): void {
        if (self::$loaded) return;
        
        if (!file_exists($envPath)) {
            throw new RuntimeException(".env file not found at {$envPath}");
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || $line === '') continue;
            
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                self::$config[trim($key)] = trim($value);
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed {
        if (!self::$loaded) self::load();
        return self::$config[$key] ?? $default;
    }

    public static function getRequired(string $key): mixed {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Required config {$key} not set");
        }
        return $value;
    }
}