<?php

namespace watrlabs;

class cookies {

    // https sites get secure cookies, local dev doesn't
    static function secure(): bool {
        return str_starts_with($_ENV['APP_URL'] ?? '', 'https://');
    }

    // creates and assigns a cookie. httponly unless you say otherwise
    static function set(string $name, string $value, int $expires = 0, bool $httpOnly = true): void {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => $_ENV['COOKIE_DOMAIN'] ?? '',
            'secure' => self::secure(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value; // so the rest of this request sees it too
    }

    static function get(string $name): ?string {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    // gets rid of a cookie by setting its expiration date in the past
    static function destroy(string $name): void {
        setcookie($name, '', [
            'expires' => 1,
            'path' => '/',
            'domain' => $_ENV['COOKIE_DOMAIN'] ?? '',
            'secure' => self::secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }

}
