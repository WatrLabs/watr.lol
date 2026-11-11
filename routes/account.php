<?php
use watrlabs\accountdeletion;
use watrlabs\accountmail;
use watrlabs\authentication;
use watrlabs\cookies;
use watrlabs\emailtokens;
use watrlabs\encryption;
use watrlabs\mail;
use watrlabs\sessions;
use watrlabs\throttle;
use watrlabs\twofactor;
use watrlabs\social\follows;
use watrlabs\social\reactions;
use watrlabs\social\statuses;
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

    $me = current_user();
    $follows = new follows();
    $statuses = new statuses();
    $before = (int) query('before') ?: null;
    $updates = $statuses->byUser($profile->id, 20, $before);

    render('users/profile.twig', [
        'profile' => $profile,
        'post_count' => (new posts())->countByUser($profile->id),
        'update_count' => $statuses->countByUser($profile->id),
        'threads' => (new threads())->byUser($profile->id, 10),
        'updates' => $updates,
        'next' => count($updates) === 20 ? (int) end($updates)->created : null,
        'reactions' => (new reactions())->summarize('status', array_map(fn ($s) => $s->id, $updates), $me ? (int) $me->id : null),
        'followers' => $follows->followerCount($profile->id),
        'following' => $follows->followingCount($profile->id),
        'is_following' => $me ? $follows->isFollowing($me->id, $profile->id) : false,
        'follows_me' => $me ? $follows->isFollowing($profile->id, $me->id) : false,
    ]);
});

// checks the "who are you, really" fields on risky forms: password if they have one,
// and an app code if two-factor is on. null when it's fine
function confirm_identity($user): ?string {
    if ($user->password && !password_verify((string) ($_POST['current'] ?? ''), $user->password)) {
        return 'Your password is wrong.';
    }
    if (twofactor::enabled($user)) {
        if (throttle::tooMany('2fa', (string) $user->id, 5, 900)) {
            return 'Too many wrong codes. Wait 15 minutes and try again.';
        }
        if (!(new twofactor())->verify($user, post('code'))) {
            throttle::hit('2fa', (string) $user->id);
            return "That code didn't work.";
        }
    }
    return null;
}

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
        $pronouns = post('pronouns');
        $location = post('location');
        $website = post('website');
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }

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
        if (!$error && mb_strlen($pronouns) > 30) {
            $error = 'Pronouns can be 30 characters at most.';
        }
        if (!$error && mb_strlen($location) > 60) {
            $error = 'Location can be 60 characters at most.';
        }
        if (!$error && $website !== '' && (strlen($website) > 255 || !filter_var($website, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/\s]+\.[^/\s]+#i', $website))) {
            $error = "That website link doesn't look right.";
        }

        if ($error) {
            http_response_code(422);
            $user->username = $username;
            $user->display_name = $displayName;
            $user->bio = $bio;
            $user->pronouns = $pronouns;
            $user->location = $location;
            $user->website = $website;
            render('settings/profile.twig', ['user' => $user, 'error' => $error]);
            return;
        }

        $db->table('users')->where('id', $user->id)->update([
            'username' => $username,
            'username_lower' => strtolower($username),
            'display_name' => $displayName ?: null,
            'bio' => $bio ?: null,
            'pronouns' => $pronouns ?: null,
            'location' => $location ?: null,
            'website' => $website ?: null,
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
        (new accountmail())->notifyPasswordChanged($user);

        flash($user->password ? 'Changed your password. Other devices were logged out.' : 'Set your password. You can log in with it now.');
        redirect('/settings/password');
    });

    $router->get('/email', function () {
        $user = require_login();
        render('settings/email.twig', [
            'user' => $user,
            'pending' => (new emailtokens())->pendingEmail($user->id),
            'mail_ready' => mail::configured(),
        ]);
    });

    $router->post('/email', function () {
        require_csrf();
        $user = require_login();
        $email = post('email');

        $error = null;
        if (!mail::valid($email)) {
            $error = "That email address doesn't look right.";
        } elseif ($user->email && strtolower($email) === strtolower($user->email)) {
            $error = "That's already your email.";
        } elseif ($user->password && !password_verify((string) ($_POST['current'] ?? ''), $user->password)) {
            // otherwise a stolen session could swap the email, then reset the password
            $error = 'Your password is wrong.';
        } elseif (throttle::tooMany('verify', (string) $user->id, 5, 3600)) {
            $error = "You've asked for a lot of links. Wait an hour and try again.";
        }

        if ($error) {
            http_response_code(422);
            render('settings/email.twig', [
                'user' => $user,
                'pending' => (new emailtokens())->pendingEmail($user->id),
                'mail_ready' => mail::configured(),
                'email' => $email,
                'error' => $error,
            ]);
            return;
        }

        throttle::hit('verify', (string) $user->id);

        // taken by someone else: act like it went, so this can't be used to look people up
        if (!(new authentication())->getByEmail($email) && !(new accountmail())->sendVerification($user, $email)) {
            flash("Couldn't send the email. Try again in a few minutes.", 'danger');
            redirect('/settings/email');
        }

        flash("Sent a link to $email. Click it to confirm the address.");
        redirect('/settings/email');
    });

    $router->post('/email/cancel', function () {
        require_csrf();
        $user = require_login();
        (new emailtokens())->cancelPending($user->id);
        flash('Cancelled. That link won\'t work anymore.', 'plain');
        redirect('/settings/email');
    });

    $router->post('/email/remove', function () {
        global $db;
        require_csrf();
        $user = require_login();
        $db->table('users')->where('id', $user->id)->update(['email' => null, 'email_lower' => null, 'updated' => time()]);
        flash("Removed your email. You can't reset your password by email until you add one.", 'plain');
        redirect('/settings/email');
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

    // ---------- security: two-factor and deleting the account ----------

    $router->get('/security', function () {
        $user = require_login();
        render('settings/security.twig', [
            'user' => $user,
            'twofactor' => twofactor::enabled($user),
            'backup_left' => (new twofactor())->backupCodesLeft($user->id),
            'delete_blocker' => (new accountdeletion())->blocker($user),
        ]);
    });

    // step 1: make a secret and show the qr code. nothing is saved until they type a code back
    $router->post('/security/2fa/start', function () {
        require_csrf();
        $user = require_login();
        if (twofactor::enabled($user)) {
            redirect('/settings/security');
        }
        if ($user->password && !password_verify((string) ($_POST['current'] ?? ''), $user->password)) {
            flash('Your password is wrong.', 'danger');
            redirect('/settings/security');
        }
        cookies::set(twofactor::SETUP_COOKIE, encryption::sign([
            'u' => (int) $user->id,
            's' => twofactor::newSecret(),
            'exp' => time() + 900,
        ]), time() + 900);
        redirect('/settings/security/2fa');
    });

    $router->get('/security/2fa', function () {
        $user = require_login();
        $setup = encryption::unsign(cookies::get(twofactor::SETUP_COOKIE));
        if (!$setup || $setup['u'] !== (int) $user->id || twofactor::enabled($user)) {
            redirect('/settings/security');
        }
        $uri = twofactor::uri($setup['s'], $user->username);
        render('settings/twofactor_setup.twig', [
            'user' => $user,
            'secret' => trim(chunk_split($setup['s'], 4, ' ')),
            'qr' => twofactor::qr($uri),
        ]);
    });

    // step 2: the code from the app proves it scanned. then it's on, and they get backup codes
    $router->post('/security/2fa', function () {
        require_csrf();
        $user = require_login();
        $setup = encryption::unsign(cookies::get(twofactor::SETUP_COOKIE));
        if (!$setup || $setup['u'] !== (int) $user->id || twofactor::enabled($user)) {
            flash('That took too long. Start again.', 'warning');
            redirect('/settings/security');
        }

        $step = twofactor::match($setup['s'], post('code'));
        if ($step === null) {
            http_response_code(422);
            render('settings/twofactor_setup.twig', [
                'user' => $user,
                'secret' => trim(chunk_split($setup['s'], 4, ' ')),
                'qr' => twofactor::qr(twofactor::uri($setup['s'], $user->username)),
                'error' => "That code didn't work. Make sure your phone's clock is right and try the newest code.",
            ]);
            return;
        }

        $codes = (new twofactor())->enable($user->id, $setup['s'], $step);
        cookies::destroy(twofactor::SETUP_COOKIE);
        (new accountmail())->notifyTwoFactor($user, true);

        render('settings/backup_codes.twig', ['user' => $user, 'codes' => $codes, 'fresh' => true]);
    });

    $router->post('/security/2fa/backup', function () {
        require_csrf();
        $user = require_login();
        if (!twofactor::enabled($user)) {
            redirect('/settings/security');
        }
        if ($error = confirm_identity($user)) {
            flash($error, 'danger');
            redirect('/settings/security');
        }
        $codes = (new twofactor())->newBackupCodes($user->id);
        render('settings/backup_codes.twig', ['user' => $user, 'codes' => $codes, 'fresh' => false]);
    });

    $router->post('/security/2fa/disable', function () {
        require_csrf();
        $user = require_login();
        if (!twofactor::enabled($user)) {
            redirect('/settings/security');
        }
        if ($error = confirm_identity($user)) {
            flash($error, 'danger');
            redirect('/settings/security');
        }
        (new twofactor())->disable($user->id);
        (new accountmail())->notifyTwoFactor($user, false);
        flash('Turned off two-factor. Logging in only needs your password now.', 'plain');
        redirect('/settings/security');
    });

    $router->post('/delete', function () {
        require_csrf();
        $user = require_login();
        $deletion = new accountdeletion();

        $error = $deletion->blocker($user);
        if (!$error && strtolower(post('confirm_username')) !== strtolower($user->username)) {
            $error = 'Type your username exactly to confirm.';
        }
        $error ??= confirm_identity($user);

        if ($error) {
            flash($error, 'danger');
            redirect('/settings/security');
        }

        (new accountmail())->notifyDeleted($user);
        $deletion->delete($user, post('wipe') === '1');
        cookies::destroy(sessions::cookieName());

        flash('Your account is gone. Thanks for being here.', 'plain');
        redirect('/');
    });

    $router->post('/apps/{id}/revoke', function ($id) {
        require_csrf();
        $user = require_login();
        (new server())->revokeGrant((int) $id, $user->id);
        flash('Disconnected that app. It has to ask again next time.', 'plain');
        redirect('/settings/apps');
    });

});
