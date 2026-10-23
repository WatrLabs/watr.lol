<?php

namespace watrlabs;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

// codes from an authenticator app (totp, rfc 6238) plus one-time backup codes
class twofactor {

    const DIGITS = 6;
    const PERIOD = 30;
    const BACKUP_COUNT = 10;
    const PENDING_COOKIE = 'watr_2fa';      // "password was right, now the code" during login
    const SETUP_COOKIE = 'watr_2fa_setup';  // the secret being set up, before it's confirmed

    static function enabled($user): bool {
        return $user && !empty($user->totp_secret);
    }

    static function newSecret(): string {
        return self::base32(random_bytes(20));
    }

    // what the authenticator app scans
    static function uri(string $secret, string $username): string {
        $issuer = $_ENV['APP_NAME'] ?? 'watr.lol';
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    // an svg data url, so it fits the csp (img-src data:) and needs no extra request
    static function qr(string $uri): string {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'addQuietzone' => true,
            'svgUseFillAttributes' => true,
        ]);
        return (new QRCode($options))->render($uri);
    }

    static function code(string $secret, int $step): string {
        $key = self::unbase32($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // returns the time step the code matched, or null. allows one step either side for clock drift
    static function match(string $secret, string $code, ?int $lastStep = null): ?int {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), self::PERIOD);
        for ($step = $now - 1; $step <= $now + 1; $step++) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // already used, someone may be replaying it
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    // checks an app code or a backup code for someone with two-factor on, and uses it up
    public function verify($user, string $input): bool {
        global $db;

        if (!self::enabled($user)) {
            return false;
        }

        $step = self::match($user->totp_secret, $input, $user->totp_last_step !== null ? (int) $user->totp_last_step : null);
        if ($step !== null) {
            $db->table('users')->where('id', $user->id)->update(['totp_last_step' => $step]);
            return true;
        }

        return $this->useBackupCode((int) $user->id, $input);
    }

    public function enable(int $userId, string $secret, int $step): array {
        global $db;
        $db->table('users')->where('id', $userId)->update([
            'totp_secret' => $secret,
            'totp_last_step' => $step,
            'updated' => time(),
        ]);
        return $this->newBackupCodes($userId);
    }

    public function disable(int $userId): void {
        global $db;
        $db->table('users')->where('id', $userId)->update(['totp_secret' => null, 'totp_last_step' => null, 'updated' => time()]);
        $db->table('backup_codes')->where('user_id', $userId)->delete();
    }

    // throws the old ones away. the plain codes only exist in what this returns
    public function newBackupCodes(int $userId): array {
        global $db;
        $db->table('backup_codes')->where('user_id', $userId)->delete();

        $codes = [];
        for ($i = 0; $i < self::BACKUP_COUNT; $i++) {
            // 10 characters from a set with no 0/o or 1/l mixups, shown as xxxxx-xxxxx
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $db->table('backup_codes')->insert([
                'user_id' => $userId,
                'code_hash' => self::hashBackup($raw),
                'created' => time(),
            ]);
        }
        return $codes;
    }

    public function backupCodesLeft(int $userId): int {
        global $db;
        return $db->table('backup_codes')->where('user_id', $userId)->where('used', 0)->count();
    }

    private function useBackupCode(int $userId, string $input): bool {
        global $db;
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($clean) !== 10) {
            return false;
        }
        $row = $db->table('backup_codes')
            ->where('user_id', $userId)
            ->where('code_hash', self::hashBackup($clean))
            ->where('used', 0)
            ->first();
        if (!$row) {
            return false;
        }
        $db->table('backup_codes')->where('id', $row->id)->update(['used' => 1]);
        return true;
    }

    // keyed with APP_KEY so a leaked table alone can't be brute forced offline
    private static function hashBackup(string $clean): string {
        return hash_hmac('sha256', $clean, $_ENV['APP_KEY']);
    }

    private static function base32(string $bytes): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function unbase32(string $text): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

}
