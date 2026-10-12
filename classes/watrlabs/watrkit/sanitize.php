<?php

namespace watrlabs\watrkit;

class sanitize {

    static function integer($int) {
        return (int) filter_var($int, FILTER_SANITIZE_NUMBER_INT);
    }

    // makes a url slug out of a name. "Help & Support" -> "help-support"
    static function slug(string $text): string {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        return substr($slug, 0, 40);
    }

    // a plain http(s) url or null
    static function url(string $url): ?string {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    static function string($string) {
        return htmlspecialchars($string);
    }

}
