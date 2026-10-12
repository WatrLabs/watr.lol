<?php
use watrlabs\authentication;
use watrlabs\sessions;
use watrlabs\sitefunctions;
use watrlabs\throttle;

global $router; // IMPORTANT: KEEP THIS HERE!

// public facing auth pages
// where users can register and log in
$router->group('/auth', function ($router) {

    $router->get('/login', function () {
        $continue = safe_continue(query('continue'));
        if (current_user()) {
            redirect($continue);
        }
        render('auth/login.twig', ['continue' => $continue]);
    });

    $router->post('/login', function () {
        require_csrf();

        $continue = safe_continue(post('continue'));
        $username = post('username');
        $result = (new authentication())->attemptLogin($username, (string) ($_POST['password'] ?? ''));

        if (isset($result['error'])) {
            http_response_code(422);
            render('auth/login.twig', ['continue' => $continue, 'username' => $username, 'error' => $result['error']]);
            return;
        }

        (new sessions())->createSession($result['user']->id);
        redirect($continue);
    });

    $router->get('/register', function () {
        $continue = safe_continue(query('continue'));
        if (current_user()) {
            redirect($continue);
        }
        render('auth/register.twig', ['continue' => $continue]);
    });

    $router->post('/register', function () {
        require_csrf();

        $continue = safe_continue(post('continue'));
        $username = post('username');
        $password = (string) ($_POST['password'] ?? '');
        $view = ['continue' => $continue, 'username' => $username];

        $error = null;
        if (($_ENV['REGISTRATION_OPEN'] ?? 'true') !== 'true') {
            $error = 'Sign ups are closed right now.';
        } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $error = 'Your passwords do not match.';
        } elseif (throttle::tooMany('register', sitefunctions::getip(), 5, 3600)) {
            $error = 'Too many new accounts from your network. Try again in an hour.';
        }

        $result = $error ? ['error' => $error] : (new authentication())->createUser($username, $password);

        if (isset($result['error'])) {
            http_response_code(422);
            render('auth/register.twig', $view + ['error' => $result['error']]);
            return;
        }

        throttle::hit('register', sitefunctions::getip());
        (new sessions())->createSession($result['id']);
        flash("Welcome to watr.lol, $username!");
        redirect($continue);
    });

    $router->post('/logout', function () {
        require_csrf();
        (new sessions())->destroyCurrent();
        flash('Logged out.', 'plain');
        redirect('/');
    });

});
