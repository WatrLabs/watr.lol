<?php

namespace watrlabs;

class encryption {

    // this generates a random string at will
    public function genRandString(int $length, bool $safe = true) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        if(!$safe){
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-={}|\][":;\'<>?';
        }

        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    // a url-safe random token. 32 bytes is plenty for sessions and secrets
    static function token(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    // tokens live in the database as hashes, so a leaked db doesn't leak logins
    static function hashToken(string $token): string {
        return hash('sha256', $token);
    }

    // packs an array into a string nobody can change without APP_KEY.
    // not secret, just tamper-proof. used for short-lived cookies
    static function sign(array $data): string {
        $payload = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $_ENV['APP_KEY']);
    }

    // the other half of sign(). null if it's been messed with or has expired
    static function unsign(?string $value): ?array {
        if (!$value || !str_contains($value, '.')) {
            return null;
        }

        [$payload, $mac] = explode('.', $value, 2);

        if (!hash_equals(hash_hmac('sha256', $payload, $_ENV['APP_KEY']), $mac)) {
            return null;
        }

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        if (!is_array($data) || (isset($data['exp']) && $data['exp'] < time())) {
            return null;
        }

        return $data;
    }

    // this takes a string and hashes it
    // this is useful for stuff like file storage and whatnot
    static function hashString(string $seed, int $length = 16): string
    {
        $hash = hash('sha256', $seed, true);
        $randomized = rtrim(strtr(base64_encode($hash), '+/', 'AZ'), '=');

        return substr($randomized, 0, $length);
    }

}
