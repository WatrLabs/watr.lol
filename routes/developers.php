<?php
use watrlabs\cookies;
use watrlabs\encryption;
use watrlabs\roles;
use watrlabs\oauth\apps;
use watrlabs\oauth\server;

global $router; // IMPORTANT: KEEP THIS HERE!

// your app, or any app if you're an admin
function developer_app_or_404($id) {
    $user = require_login();
    $app = (new apps())->get((int) $id);
    if (!$app || ((int) $app->owner_id !== (int) $user->id && !roles::atLeast($user, 'admin'))) {
        abort(404, "That app doesn't exist, or it isn't yours.");
    }
    return $app;
}

// a new secret is shown exactly once, on the page right after it's made
function developer_show_secret(int $appId, string $secret): void {
    cookies::set('watr_secret', encryption::sign(['app' => $appId, 'secret' => $secret, 'exp' => time() + 120]), time() + 120);
}

$router->group('/developers', function ($router) {

    $router->get('/', function () {
        $user = require_login();
        render('developers/index.twig', ['apps' => (new apps())->forOwner($user->id)]);
    });

    $router->get('/docs', function () {
        render('developers/docs.twig', [
            'base' => rtrim($_ENV['APP_URL'] ?? '', '/'),
            'scopes' => server::SCOPES,
        ]);
    });

    $router->get('/new', function () {
        require_login();
        render('developers/form.twig', ['app' => null]);
    });

    $router->post('/new', function () {
        require_csrf();
        $user = require_login();
        $apps = new apps();

        $form = ['name' => post('name'), 'description' => post('description'), 'homepage' => post('homepage'), 'redirect_uris' => post('redirect_uris')];

        if (count($apps->forOwner($user->id)) >= 10 && !roles::atLeast($user, 'admin')) {
            $fields = ['error' => 'You can have 10 apps at most. Delete one you don\'t use first.'];
        } else {
            $fields = $apps->validate($form['name'], $form['description'], $form['homepage'], $form['redirect_uris']);
        }

        if (isset($fields['error'])) {
            http_response_code(422);
            render('developers/form.twig', ['app' => null, 'form' => $form, 'error' => $fields['error']]);
            return;
        }

        [$id, $secret] = $apps->create($user->id, $fields);
        developer_show_secret($id, $secret);
        flash('Made your app. Copy the secret now, it won\'t be shown again.');
        redirect('/developers/' . $id);
    });

    $router->get('/{id}', function ($id) {
        $app = developer_app_or_404($id);

        $shown = encryption::unsign(cookies::get('watr_secret'));
        $secret = null;
        if ($shown && (int) $shown['app'] === (int) $app->id) {
            $secret = $shown['secret'];
            cookies::destroy('watr_secret');
        }

        render('developers/app.twig', [
            'app' => $app,
            'secret' => $secret,
            'redirects' => (new apps())->redirectUris($app),
            'base' => rtrim($_ENV['APP_URL'] ?? '', '/'),
        ]);
    });

    $router->get('/{id}/edit', function ($id) {
        $app = developer_app_or_404($id);
        render('developers/form.twig', [
            'app' => $app,
            'form' => [
                'name' => $app->name,
                'description' => $app->description,
                'homepage' => $app->homepage,
                'redirect_uris' => $app->redirect_uris,
            ],
        ]);
    });

    $router->post('/{id}/edit', function ($id) {
        require_csrf();
        $app = developer_app_or_404($id);
        $apps = new apps();

        $form = ['name' => post('name'), 'description' => post('description'), 'homepage' => post('homepage'), 'redirect_uris' => post('redirect_uris')];
        $fields = $apps->validate($form['name'], $form['description'], $form['homepage'], $form['redirect_uris']);

        if (isset($fields['error'])) {
            http_response_code(422);
            render('developers/form.twig', ['app' => $app, 'form' => $form, 'error' => $fields['error']]);
            return;
        }

        $apps->update($app->id, $fields);
        flash('Saved your app.');
        redirect('/developers/' . $app->id);
    });

    $router->post('/{id}/secret', function ($id) {
        require_csrf();
        $app = developer_app_or_404($id);
        $secret = (new apps())->resetSecret($app->id);
        developer_show_secret((int) $app->id, $secret);
        flash('Made a new secret. The old one stopped working just now.', 'warning');
        redirect('/developers/' . $app->id);
    });

    $router->post('/{id}/delete', function ($id) {
        require_csrf();
        $app = developer_app_or_404($id);
        (new apps())->delete($app->id);
        flash("Deleted {$app->name}. Everyone signed in through it was logged out of it.", 'plain');
        redirect('/developers');
    });

});
