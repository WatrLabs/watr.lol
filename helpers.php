<?php
// little functions every route uses. kept global so routes stay short

use watrlabs\cookies;
use watrlabs\csrf;
use watrlabs\encryption;
use watrlabs\roles;

function render(string $view, array $data = []): void
{
    global $twig;
    echo $twig->render($view, $data);
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

// stop right here and show an error page
function abort(int $code, ?string $message = null): never
{
    global $twig;
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    if (ob_get_level() === 1) {
        ob_clean();
    }
    http_response_code($code);
    $view = in_array($code, [403, 404, 500], true) ? $code : 403;
    echo $twig->render("status_codes/$view.twig", ['message' => $message]);
    exit;
}

function current_user()
{
    global $currentuser;
    return $currentuser;
}

function require_login()
{
    $user = current_user();
    if (!$user) {
        redirect('/auth/login?continue=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    return $user;
}

function require_role(string $role)
{
    $user = require_login();
    if (!roles::atLeast($user, $role)) {
        abort(403, "You need to be a $role to see this page.");
    }
    return $user;
}

// every POST form carries a csrf token. no token, no change
function require_csrf(): void
{
    if (!csrf::check($_POST['_csrf'] ?? null)) {
        abort(403, 'This form expired. Go back, refresh the page and try again.');
    }
}

// a trimmed string from the posted form, never an array
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

// only ever send people back to a page on this site
function safe_continue(?string $url, string $fallback = '/'): string
{
    if (!is_string($url) || $url === '' || $url[0] !== '/' || str_starts_with($url, '//') || str_contains($url, '\\')) {
        return $fallback;
    }
    return $url;
}

// a message that shows up as a toast on the next page
function flash(string $message, string $type = 'info'): void
{
    cookies::set('watr_flash', encryption::sign(['m' => $message, 't' => $type, 'exp' => time() + 60]), time() + 60);
}

function take_flash(): ?array
{
    $data = encryption::unsign(cookies::get('watr_flash'));
    if (cookies::get('watr_flash') !== null) {
        cookies::destroy('watr_flash');
    }
    if (!$data) {
        return null;
    }
    return ['message' => (string) $data['m'], 'type' => (string) $data['t']];
}

// works out which slice of a list we're on. ?page=3 and friends
function paginate(int $total, int $perPage, ?int $page = null): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = $page ?? (int) query('page', '1');
    $page = min(max(1, $page), $pages);

    return [
        'page' => $page,
        'pages' => $pages,
        'per' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'total' => $total,
    ];
}
