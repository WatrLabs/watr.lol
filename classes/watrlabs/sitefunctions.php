<?php

namespace watrlabs;

class sitefunctions {

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
