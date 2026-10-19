<?php

use watrlabs\router\routing;
use watrlabs\logging\discord;

// php -S serves real files itself
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

ob_start();

try {
    require_once __DIR__ . '/../init.php';

    global $router;

    if (($_ENV['APP_DEBUG'] ?? 'false') !== 'true') {
        error_reporting(0);
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    // turnstile needs its script and its little iframe from cloudflare
    $cloudflare = \watrlabs\turnstile::enabled() ? ' https://challenges.cloudflare.com' : '';
    header("Content-Security-Policy: default-src 'self'; script-src 'self'$cloudflare; frame-src 'self'$cloudflare; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: https:; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

    $router = new routing();

    $routers = [
        'web',
        'auth',
        'connections',
        'account',
        'forum',
        'projects',
        'oauth',
        'developers',
        'admin',
    ];

    foreach ($routers as $r) {
        $router->addrouter($r);
    }

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $router->dispatch($uri, $_SERVER['REQUEST_METHOD']);

    ob_end_flush();
} catch (Throwable $e) {
    handle_error($e);
}

function handle_error(Throwable $e)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    @file_put_contents(__DIR__ . '/../storage/errorlog.log', date('c') . ' ' . $e . "\n\n", FILE_APPEND);

    try {
        (new discord())->internal_log(substr((string) $e, 0, 3500), 'Site Error!');
    } catch (Throwable $ignored) {
        // the logger broke too. the file log above still has it
    }

    http_response_code(500);

    try {
        global $twig;
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? (string) $e : null;
        echo $twig->render('status_codes/500.twig', ['debug' => $debug]);
    } catch (Throwable $ignored) {
        die("Couldn't process your request. Please try again later.");
    }
}

// aaaaaaaaaaaaaaaa my brain hurts
