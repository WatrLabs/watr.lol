<?php
// phinx reads the same .env as the site, so there's nothing to fill in here

require_once __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

$driver = $_ENV['DB_DRIVER'] ?? 'mysql';

if ($driver === 'sqlite') {
    $path = $_ENV['DB_PATH'] ?? 'storage/watr.sqlite';
    if ($path[0] !== '/') $path = __DIR__ . '/' . $path;

    $environment = [
        'adapter' => 'sqlite',
        'name' => preg_replace('/\.sqlite3?$/', '', $path),
        'suffix' => '.sqlite',
    ];
} else {
    $environment = [
        'adapter' => 'mysql',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];
}

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'main',
        'main' => $environment,
    ],
    'version_order' => 'creation',
];
