<?php
// watr.lol bootstrap. every request comes through here before it hits a route
use Pixie\Connection;
use watrlabs\authentication;
use watrlabs\csrf;
use watrlabs\roles;
use watrlabs\sitefunctions;
use watrlabs\forum\categories;
use watrlabs\connections\providers;
use watrlabs\watrkit\markup;

global $currentuser;
global $db;
global $twig;

define('ROOT', __DIR__);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    // pixie makes dynamic properties, php 8.2+ complains every query. not our problem
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return true;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once ROOT . '/vendor/autoload.php';

spl_autoload_register(function ($class_name) {
    $file = ROOT . '/classes/' . str_replace('\\', DIRECTORY_SEPARATOR, $class_name) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once ROOT . '/helpers.php';

Dotenv\Dotenv::createImmutable(ROOT)->load();

if (strlen($_ENV['APP_KEY'] ?? '') < 32) {
    http_response_code(500);
    die('APP_KEY is missing or too short. Make one with: php -r "echo bin2hex(random_bytes(32));"');
}

try {
    $driver = $_ENV['DB_DRIVER'] ?? 'mysql';

    if ($driver === 'sqlite') {
        $path = $_ENV['DB_PATH'] ?? 'storage/watr.sqlite';
        $config = [
            'driver' => 'sqlite',
            'database' => $path[0] === '/' ? $path : ROOT . '/' . $path,
            'prefix' => '',
        ];
    } else {
        $config = [
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'],
            'database' => $_ENV['DB_NAME'],
            'username' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'charset' => 'utf8mb4', // emoji in posts. the centipede must live 🐛
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ];
    }

    $connection = new Connection($driver, $config);
    $db = $connection->getQueryBuilder();
} catch (PDOException $e) {
    http_response_code(500);
    die('Failed to connect to the database.');
}

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(ROOT . '/views'), [
    'cache' => ROOT . '/storage/cache',
    'auto_reload' => true,
]);

$twig->addFunction(new \Twig\TwigFunction('env', function ($key, $default = null) {
    return $_ENV[$key] ?? $default;
}));
$twig->addFunction(new \Twig\TwigFunction('csrf_field', function () {
    return csrf::field();
}, ['is_safe' => ['html']]));
$twig->addFunction(new \Twig\TwigFunction('csrf_token', fn () => csrf::token()));
$twig->addFunction(new \Twig\TwigFunction('role_at_least', fn ($user, $role) => roles::atLeast($user, $role)));
$twig->addFunction(new \Twig\TwigFunction('sidebar_categories', fn () => (new categories())->all()));
$twig->addFunction(new \Twig\TwigFunction('login_providers', fn () => providers::enabled()));
$twig->addFunction(new \Twig\TwigFunction('flash', fn () => take_flash()));
$twig->addFunction(new \Twig\TwigFunction('turnstile', fn () => \watrlabs\turnstile::widget(), ['is_safe' => ['html']]));
$twig->addFunction(new \Twig\TwigFunction('blob_avatar', fn ($user) => sitefunctions::blobAvatar($user)));

$twig->addFilter(new \Twig\TwigFilter('ago', fn ($time) => sitefunctions::ago((int) $time)));
$twig->addFilter(new \Twig\TwigFilter('day', fn ($time) => date('j M Y', (int) $time)));
$twig->addFilter(new \Twig\TwigFilter('number', fn ($n) => sitefunctions::format_number((int) $n)));
$twig->addFilter(new \Twig\TwigFilter('markup', fn ($text) => markup::render((string) $text), ['is_safe' => ['html']]));

$auth = new authentication();
$currentuser = $auth->getUserInfo();

$twig->addGlobal('currentuser', $currentuser);
$twig->addGlobal('request_path', rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/');
