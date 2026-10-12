<?php

namespace watrlabs;

class sitefunctions {

    // google's blob emoji, the happy ones. everyone gets one picked by their account number
    const BLOB_AVATARS = [
        '1f600', '1f603', '1f604', '1f601', '1f606', '1f609', '1f60a', '1f60b', '1f60e',
        '1f60d', '1f642', '1f643', '1f61b', '1f61c', '1f913', '1f917', '1f60f', '1f633',
    ];

    static function blobAvatar($user): string {
        // nobody (a deleted account) gets the neutral face
        $code = $user ? self::BLOB_AVATARS[((int) $user->id) % count(self::BLOB_AVATARS)] : '1f610';
        return '/assets/images/blobs/u' . $code . '.png';
    }

    static function format_number($int) {

        if ($int >= 1000000000) {
            $formated = number_format($int / 1000000000, 1);
            $suffix = "B+";
        } elseif ($int >= 1000000) {
            $formated = number_format($int / 1000000, 1);
            $suffix = "M+";
        } elseif ($int >= 1000) {
            $formated = number_format($int / 1000, 1);
            $suffix = "K+";
        } else {
            $formated = (string) $int;
            $suffix = "";
        }

        if (substr($formated, -2) === '.0') {
            $formated = substr($formated, 0, -2);
        }

        return $formated . $suffix;
    }

    // "5 minutes ago", and plain dates once it's been a while
    static function ago(int $time): string {
        $diff = time() - $time;

        if ($diff < 60) return 'just now';
        if ($diff < 3600) return self::plural(intdiv($diff, 60), 'minute') . ' ago';
        if ($diff < 86400) return self::plural(intdiv($diff, 3600), 'hour') . ' ago';
        if ($diff < 86400 * 7) return self::plural(intdiv($diff, 86400), 'day') . ' ago';

        return date('j M Y', $time);
    }

    static function plural(int $n, string $word): string {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    static function getip() {
        if (($_ENV['TRUST_CLOUDFLARE'] ?? 'false') === 'true' && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return substr($_SERVER['HTTP_CF_CONNECTING_IP'], 0, 45);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

}
