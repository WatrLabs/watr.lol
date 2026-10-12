<?php
use watrlabs\accountmail;
use watrlabs\authentication;
use watrlabs\emailtokens;
use watrlabs\mail;
use watrlabs\sessions;
use watrlabs\sitefunctions;
use watrlabs\throttle;
use watrlabs\turnstile;

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
        $result = turnstile::verify()
            ? (new authentication())->attemptLogin($username, (string) ($_POST['password'] ?? ''))
            : ['error' => turnstile::FAILED];

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
        $email = post('email');
        $password = (string) ($_POST['password'] ?? '');
        $view = ['continue' => $continue, 'username' => $username, 'email' => $email];

        $error = null;
        if (($_ENV['REGISTRATION_OPEN'] ?? 'true') !== 'true') {
            $error = 'Sign ups are closed right now.';
        } elseif (!turnstile::verify()) {
            $error = turnstile::FAILED;
        } elseif ($email !== '' && !mail::valid($email)) {
            $error = "That email address doesn't look right.";
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

        // an address someone else already confirmed just doesn't get a link. saying so would
        // tell anyone who has an account here
        $auth = new authentication();
        if ($email !== '' && !$auth->getByEmail($email)) {
            (new accountmail())->sendVerification($auth->getUserInfo($result['id']), $email);
            flash("Welcome to watr.lol, $username! Check $email for a link to confirm it.");
        } else {
            flash("Welcome to watr.lol, $username!");
        }
        redirect($continue);
    });

    // "forgot your password?"
    $router->get('/forgot', function () {
        render('auth/forgot.twig', ['login' => query('login')]);
    });

    $router->post('/forgot', function () {
        require_csrf();

        $login = post('login');
        $ip = sitefunctions::getip();

        if (throttle::tooMany('forgot', $ip, 5, 900)) {
            http_response_code(429);
            render('auth/forgot.twig', ['login' => $login, 'error' => 'Too many reset requests from your network. Wait 15 minutes and try again.']);
            return;
        }
        if (!turnstile::verify()) {
            http_response_code(422);
            render('auth/forgot.twig', ['login' => $login, 'error' => turnstile::FAILED]);
            return;
        }

        throttle::hit('forgot', $ip);

        $user = $login !== '' ? (new authentication())->getByLogin($login) : null;

        // one email per account per 10 minutes, so nobody can flood someone's inbox
        if ($user && $user->email && !$user->banned && !throttle::tooMany('forgot-user', (string) $user->id, 1, 600)) {
            throttle::hit('forgot-user', (string) $user->id);
            (new accountmail())->sendReset($user);
        }

        // the same answer either way, so this page can't be used to check who has an account
        render('auth/forgot.twig', ['sent' => true]);
    });

    $router->get('/reset', function () {
        $token = query('token');
        $row = (new emailtokens())->peek($token, emailtokens::RESET);
        render('auth/reset.twig', [
            'token' => $token,
            'valid' => (bool) $row,
            'person' => $row ? (new authentication())->getUserInfo($row->user_id) : null,
        ]);
    });

    $router->post('/reset', function () {
        require_csrf();

        $token = post('token');
        $tokens = new emailtokens();
        $auth = new authentication();
        $row = $tokens->peek($token, emailtokens::RESET);
        $person = $row ? $auth->getUserInfo($row->user_id) : null;

        if (!$row || !$person || $person->banned) {
            render('auth/reset.twig', ['token' => $token, 'valid' => false]);
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $error = $password !== (string) ($_POST['password_confirm'] ?? '') ? 'Your passwords do not match.' : $auth->validatePassword($password);

        if ($error) {
            http_response_code(422);
            render('auth/reset.twig', ['token' => $token, 'valid' => true, 'person' => $person, 'error' => $error]);
            return;
        }

        $tokens->useUp($row->id);
        $auth->setPassword($person->id, $password);

        // whoever had the old password is out, everywhere
        $sessions = new sessions();
        $sessions->revokeAllForUser($person->id);
        $sessions->createSession($person->id);

        (new accountmail())->notifyPasswordChanged($person);
        flash('Changed your password. Every other device was logged out.');
        redirect('/');
    });

    // the link in "confirm your email"
    $router->get('/verify', function () {
        $result = (new accountmail())->verify(query('token'));

        if (isset($result['error'])) {
            flash($result['error'], 'danger');
        } else {
            flash('Confirmed ' . $result['user']->email . '.');
        }

        redirect(current_user() ? '/settings/email' : '/auth/login');
    });

    $router->post('/logout', function () {
        require_csrf();
        (new sessions())->destroyCurrent();
        flash('Logged out.', 'plain');
        redirect('/');
    });

});
