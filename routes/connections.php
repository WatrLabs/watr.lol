<?php
use watrlabs\authentication;
use watrlabs\cookies;
use watrlabs\encryption;
use watrlabs\sessions;
use watrlabs\sitefunctions;
use watrlabs\throttle;
use watrlabs\connections\linked;
use watrlabs\connections\providers;

global $router; // IMPORTANT: KEEP THIS HERE!

// sign in (or sign up, or link) with discord, github, google, or any oauth2 provider.
// the flow: POST /auth/connect/x -> provider -> GET /auth/callback/x -> logged in, linked,
// or picking a username at /auth/finish
$router->group('/auth', function ($router) {

    $router->post('/connect/{provider}', function ($slug) {
        require_csrf();

        $provider = providers::get($slug);
        if (!$provider) {
            abort(404, "Signing in with that isn't set up here.");
        }

        $user = current_user();
        $state = encryption::token(24);

        // remembers what we were doing for when the provider sends them back
        cookies::set('watr_connect', encryption::sign([
            'state' => $state,
            'provider' => $provider['slug'],
            'user' => $user ? (int) $user->id : null,
            'continue' => safe_continue(post('continue')),
            'exp' => time() + 600,
        ]), time() + 600);

        redirect(providers::authorizeUrl($provider, $state));
    });

    $router->get('/callback/{provider}', function ($slug) {
        $provider = providers::get($slug);
        $pending = encryption::unsign(cookies::get('watr_connect'));
        cookies::destroy('watr_connect');

        if (!$provider || !$pending || $pending['provider'] !== $provider['slug'] || !hash_equals($pending['state'], query('state'))) {
            abort(403, "That sign in link didn't come from here, or it expired. Start again from the log in page.");
        }

        $linking = $pending['user'] !== null;
        $back = $linking ? '/settings/connections' : '/auth/login';
        $user = current_user();

        // they hit cancel on the provider's side
        if (query('error') !== '' || query('code') === '') {
            flash("You cancelled signing in with {$provider['name']}.", 'plain');
            redirect($back);
        }

        // the person who started linking has to be the one finishing it
        if ($linking && (!$user || (int) $user->id !== $pending['user'])) {
            abort(403, 'You were logged out partway through. Log in and try linking again.');
        }

        try {
            $identity = providers::identify($provider, query('code'));
        } catch (RuntimeException $e) {
            error_log('watr.lol connect: ' . $e->getMessage());
            flash("Couldn't sign in with {$provider['name']}. Try again in a minute.", 'danger');
            redirect($back);
        }

        $links = new linked();
        $existing = $links->find($provider['slug'], $identity['id']);

        if ($linking) {
            if ($existing && (int) $existing->user_id !== (int) $user->id) {
                flash("That {$provider['name']} account is already linked to a different watr.lol account.", 'danger');
            } elseif ($existing) {
                flash("That {$provider['name']} account is already linked.", 'plain');
            } elseif (isset($links->forUser($user->id)[$provider['slug']])) {
                flash("You already have a {$provider['name']} account linked. Unlink it first.", 'warning');
            } else {
                $links->link($user->id, $provider['slug'], $identity);
                flash("Linked {$provider['name']}.");
            }
            redirect('/settings/connections');
        }

        if ($existing) {
            $account = (new authentication())->getUserInfo($existing->user_id);
            if (!$account) {
                flash('The account linked to that is gone.', 'danger');
                redirect('/auth/login');
            }
            if ($account->banned) {
                abort(403, 'This account is banned' . ($account->ban_reason ? ': ' . $account->ban_reason : '.'));
            }
            (new sessions())->createSession($account->id);
            redirect($pending['continue']);
        }

        if (($_ENV['REGISTRATION_OPEN'] ?? 'true') !== 'true') {
            flash("That {$provider['name']} account isn't linked to anyone, and sign ups are closed right now.", 'warning');
            redirect('/auth/login');
        }

        // nobody has this one yet: new account, they just need to pick a name
        cookies::set('watr_signup', encryption::sign([
            'provider' => $provider['slug'],
            'identity' => $identity,
            'continue' => $pending['continue'],
            'exp' => time() + 900,
        ]), time() + 900);

        redirect('/auth/finish');
    });

    $router->get('/finish', function () {
        $pending = encryption::unsign(cookies::get('watr_signup'));
        if (!$pending) {
            redirect('/auth/register');
        }

        $suggested = linked::suggestUsername($pending['identity']['username']);
        if ($suggested && (new authentication())->validateUsername($suggested)) {
            $suggested = ''; // taken or reserved, let them pick
        }

        render('auth/finish.twig', [
            'provider' => providers::name($pending['provider']),
            'identity' => $pending['identity'],
            'username' => $suggested,
        ]);
    });

    $router->post('/finish', function () {
        require_csrf();

        $pending = encryption::unsign(cookies::get('watr_signup'));
        if (!$pending) {
            abort(403, 'That took a bit too long. Start again from the sign up page.');
        }

        $username = post('username');
        $view = [
            'provider' => providers::name($pending['provider']),
            'identity' => $pending['identity'],
            'username' => $username,
        ];

        $links = new linked();
        if ($links->find($pending['provider'], $pending['identity']['id'])) {
            cookies::destroy('watr_signup');
            abort(403, 'That account was linked to someone in the meantime. Log in instead.');
        }

        if (throttle::tooMany('register', sitefunctions::getip(), 5, 3600)) {
            http_response_code(422);
            render('auth/finish.twig', $view + ['error' => 'Too many new accounts from your network. Try again in an hour.']);
            return;
        }

        $result = (new authentication())->createUser($username, null);
        if (isset($result['error'])) {
            http_response_code(422);
            render('auth/finish.twig', $view + ['error' => $result['error']]);
            return;
        }

        throttle::hit('register', sitefunctions::getip());
        $links->link($result['id'], $pending['provider'], $pending['identity']);
        cookies::destroy('watr_signup');
        (new sessions())->createSession($result['id']);

        flash("Welcome to watr.lol, $username!");
        redirect($pending['continue']);
    });

});
