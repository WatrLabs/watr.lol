<?php

namespace watrlabs;

// cloudflare turnstile, the "are you a person?" box on log in, sign up and forgot password.
// on when TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are both set, otherwise everything passes
class turnstile {

    const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    static function enabled(): bool {
        return ($_ENV['TURNSTILE_SITE_KEY'] ?? '') !== '' && ($_ENV['TURNSTILE_SECRET_KEY'] ?? '') !== '';
    }

    // the widget, plus cloudflare's script that fills it in
    static function widget(): string {
        if (!self::enabled()) {
            return '';
        }
        return '<div class="cf-turnstile turnstile" data-sitekey="' . htmlspecialchars($_ENV['TURNSTILE_SITE_KEY']) . '" data-theme="auto" data-size="flexible"></div>'
            . '<script src="' . self::SCRIPT . '" async defer></script>';
    }

    // did whoever sent this form pass the check? asks cloudflare, once per token
    static function verify(): bool {
        if (!self::enabled()) {
            return true;
        }

        $token = $_POST['cf-turnstile-response'] ?? '';
        if (!is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        // TURNSTILE_VERIFY_URL only exists so tests can stand in for cloudflare
        $ch = curl_init(($_ENV['TURNSTILE_VERIFY_URL'] ?? '') ?: self::VERIFY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $_ENV['TURNSTILE_SECRET_KEY'],
            'response' => $token,
            'remoteip' => sitefunctions::getip(),
        ]));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $result = is_string($body) ? json_decode($body, true) : null;

        // can't reach cloudflare: say no, a bot shouldn't get in just because the check is down
        if (!is_array($result)) {
            @file_put_contents(ROOT . '/storage/errorlog.log', date('c') . " turnstile check failed: $error\n\n", FILE_APPEND);
            return false;
        }

        if (empty($result['success']) && array_intersect($result['error-codes'] ?? [], ['invalid-input-secret', 'missing-input-secret'])) {
            @file_put_contents(ROOT . '/storage/errorlog.log', date('c') . " turnstile says TURNSTILE_SECRET_KEY is wrong\n\n", FILE_APPEND);
        }

        return !empty($result['success']);
    }

    // what to tell someone who didn't pass
    const FAILED = 'Finish the "verify you are human" check, then try again.';

}
