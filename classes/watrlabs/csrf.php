<?php

namespace watrlabs;

// double-submit: a random value in a cookie, the same value in every form.
// another site can make your browser post, but it can't read the cookie to fill the form
class csrf {

    const COOKIE = 'watr_csrf';

    static function token(): string {
        $token = cookies::get(self::COOKIE);
        if (!$token || strlen($token) < 32) {
            $token = encryption::token(24);
            cookies::set(self::COOKIE, $token);
        }
        return $token;
    }

    static function check($given): bool {
        $token = cookies::get(self::COOKIE);
        return is_string($given) && $token && hash_equals($token, $given);
    }

    static function field(): string {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token()) . '">';
    }

}
