<?php
use watrlabs\authentication;
use watrlabs\sessions;
use watrlabs\forum\posts;
use watrlabs\forum\threads;
use watrlabs\oauth\server;
use watrlabs\connections\linked;
use watrlabs\connections\providers;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->get('/u/{username}', function ($username) {
    $profile = (new authentication())->getByUsername($username);
    if (!$profile) {
        abort(404, "Nobody here goes by \"$username\".");
    }

    render('users/profile.twig', [
        'profile' => $profile,
        'post_count' => (new posts())->countByUser($profile->id),
        'threads' => (new threads())->byUser($profile->id, 10),
    ]);
});

$router->group('/settings', function ($router) {

    $router->get('/', function () {
        render('settings/profile.twig', ['user' => require_login()]);
    });

    $router->post('/', function () {
        global $db;
        require_csrf();
        $user = require_login();
        $auth = new authentication();

        $username = post('username');
        $displayName = post('display_name');
        $bio = post('bio');

        $error = null;
        if ($username !== $user->username) {
            $error = $auth->validateUsername($username, (int) $user->id);
        }
        if (!$error && mb_strlen($displayName) > 40) {
            $error = 'Display names can be 40 characters at most.';
        }
        if (!$error && mb_strlen($bio) > 500) {
            $error = 'Your bio can be 500 characters at most.';
        }

        if ($error) {
            http_response_code(422);
            $user->username = $username;
            $user->display_name = $displayName;
            $user->bio = $bio;
            render('settings/profile.twig', ['user' => $user, 'error' => $error]);
            return;
        }

        $db->table('users')->where('id', $user->id)->update([
            'username' => $username,
            'username_lower' => strtolower($username),
            'display_name' => $displayName ?: null,
            'bio' => $bio ?: null,
            'updated' => time(),
        ]);

        flash('Saved your profile.');
        redirect('/settings');
    });

    $router->get('/password', function () {
        render('settings/password.twig', ['user' => require_login()]);
    });

    $router->post('/password', function () {
        require_csrf();
        $user = require_login();
        $auth = new authentication();
        $sessions = new sessions();

        $current = (string) ($_POST['current'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $error = null;
        if ($user->password && !password_verify($current, $user->password)) {
            $error = 'Your current password is wrong.';
        } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $error = 'Your new passwords do not match.';
        } else {
            $error = $auth->validatePassword($password);
        }

        if ($error) {
            http_response_code(422);
            render('settings/password.twig', ['user' => $user, 'error' => $error]);
            return;
        }

        $auth->setPassword($user->id, $password);

        // a new password logs out everything else, in case the old one leaked
        $sessions->revokeAllForUser($user->id, (int) $sessions->getCurrentSession()->id);

        flash($user->password ? 'Changed your password. Other devices were logged out.' : 'Set your password. You can log in with it now.');
        redirect('/settings/password');
    });

    $router->get('/sessions', function () {
        $user = require_login();
        $sessions = new sessions();
        render('settings/sessions.twig', [
            'user' => $user,
            'sessions' => $sessions->listForUser($user->id),
            'current' => $sessions->getCurrentSession(),
        ]);
    });

    $router->post('/sessions/{id}/revoke', function ($id) {
        require_csrf();
        $user = require_login();
        (new sessions())->revoke($user->id, (int) $id);
        flash('Logged that device out.', 'plain');
        redirect('/settings/sessions');
    });

    $router->post('/sessions/revoke-others', function () {
        require_csrf();
        $user = require_login();
        $sessions = new sessions();
        $sessions->revokeAllForUser($user->id, (int) $sessions->getCurrentSession()->id);
        flash('Logged out everywhere else.', 'plain');
        redirect('/settings/sessions');
    });

    $router->get('/connections', function () {
        $user = require_login();
        $links = (new linked())->forUser($user->id);

        // show what's set up, plus anything they linked before it was switched off
        $rows = [];
        foreach (providers::enabled() as $slug => $provider) {
            $rows[$slug] = ['slug' => $slug, 'name' => $provider['name'], 'enabled' => true, 'link' => $links[$slug] ?? null];
        }
        foreach ($links as $slug => $link) {
            $rows[$slug] ??= ['slug' => $slug, 'name' => providers::name($slug), 'enabled' => false, 'link' => $link];
        }

        render('settings/connections.twig', [
            'user' => $user,
            'rows' => $rows,
            'can_unlink' => $user->password || count($links) > 1,
        ]);
    });

    $router->post('/connections/{provider}/unlink', function ($provider) {
        require_csrf();
        $user = require_login();
        $links = new linked();
        $mine = $links->forUser($user->id);

        if (!isset($mine[$provider])) {
            redirect('/settings/connections');
        }

        // never leave someone with no way back in
        if (!$user->password && count($mine) <= 1) {
            flash("Set a password first, or you won't be able to log in.", 'warning');
            redirect('/settings/connections');
        }

        $links->unlink($user->id, $provider);
        flash('Unlinked ' . providers::name($provider) . '.', 'plain');
        redirect('/settings/connections');
    });

    $router->get('/apps', function () {
        $user = require_login();
        render('settings/apps.twig', [
            'user' => $user,
            'grants' => (new server())->grantsForUser($user->id),
            'scopes' => server::SCOPES,
        ]);
    });

    $router->post('/apps/{id}/revoke', function ($id) {
        require_csrf();
        $user = require_login();
        (new server())->revokeGrant((int) $id, $user->id);
        flash('Disconnected that app. It has to ask again next time.', 'plain');
        redirect('/settings/apps');
    });

});
