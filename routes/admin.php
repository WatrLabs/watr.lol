<?php
use watrlabs\authentication;
use watrlabs\mail;
use watrlabs\projects;
use watrlabs\roles;
use watrlabs\sessions;
use watrlabs\forum\categories;
use watrlabs\forum\posts;
use watrlabs\oauth\apps;
use watrlabs\oauth\server;
use watrlabs\connections\linked;
use watrlabs\connections\providers;
use watrlabs\watrkit\sanitize;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->group('/admin', function ($router) {

    $router->get('/', function () {
        global $db;
        require_role('admin');

        render('admin/index.twig', [
            'stats' => [
                'people' => $db->table('users')->count(),
                'new this week' => $db->table('users')->where('created', '>', time() - 604800)->count(),
                'threads' => $db->table('forum_threads')->count(),
                'posts' => $db->table('forum_posts')->count(),
                'apps' => $db->table('oauth_apps')->count(),
                'active projects' => $db->table('projects')->where('active', 1)->count(),
                'signed in now' => $db->table('sessions')->where('last_seen', '>', time() - 900)->count(),
            ],
            'providers' => providers::enabled(),
            'mail_ready' => mail::configured(),
            'newest' => $db->table('users')->orderBy('created', 'DESC')->limit(8)->get(),
        ]);
    });

    // checks the smtp settings by mailing the admin who pressed it
    $router->post('/mail-test', function () {
        require_csrf();
        $me = require_role('admin');

        if (!$me->email) {
            flash('Add and confirm an email in settings first, the test goes there.', 'warning');
            redirect('/admin');
        }

        if (mail::send($me->email, 'Test email from ' . ($_ENV['APP_NAME'] ?? 'watr.lol'), 'test', ['user' => $me])) {
            flash(mail::configured() ? "Sent a test email to {$me->email}." : 'No MAIL_HOST set, so it went to storage/mail.log.');
        } else {
            flash("Couldn't send it. Check the MAIL_ settings in .env, and storage/errorlog.log or your php error log for what the server said.", 'danger');
        }
        redirect('/admin');
    });

    $router->get('/users', function () {
        global $db;
        require_role('admin');

        $search = query('q');
        $base = function () use ($db, $search) {
            $q = $db->table('users');
            if ($search !== '') {
                $q->where('username_lower', 'LIKE', '%' . strtolower($search) . '%');
            }
            return $q;
        };

        $pager = paginate($base()->count(), 30);
        render('admin/users.twig', [
            'users' => $base()->orderBy('created', 'DESC')->limit($pager['per'])->offset($pager['offset'])->get(),
            'pager' => $pager,
            'search' => $search,
        ]);
    });

    $router->get('/users/{id}', function ($id) {
        require_role('admin');
        $person = (new authentication())->getUserInfo((int) $id);
        if (!$person) {
            abort(404, "That account doesn't exist.");
        }

        render('admin/user.twig', [
            'person' => $person,
            'roles' => roles::all(),
            'links' => (new linked())->forUser($person->id),
            'sessions' => count((new sessions())->listForUser($person->id)),
            'post_count' => (new posts())->countByUser($person->id),
        ]);
    });

    $router->post('/users/{id}', function ($id) {
        global $db;
        require_csrf();
        $me = require_role('admin');
        $person = (new authentication())->getUserInfo((int) $id);
        if (!$person) {
            abort(404, "That account doesn't exist.");
        }

        $role = post('role');
        $banned = post('banned') === '1';
        $reason = mb_substr(post('ban_reason'), 0, 255);

        if ((int) $person->id === (int) $me->id && ($role !== 'admin' || $banned)) {
            flash("You can't demote or ban yourself. Ask another admin.", 'warning');
            redirect('/admin/users/' . $person->id);
        }

        if (!in_array($role, roles::all(), true)) {
            $role = $person->role;
        }

        $db->table('users')->where('id', $person->id)->update([
            'role' => $role,
            'banned' => $banned ? 1 : 0,
            'ban_reason' => $banned ? ($reason ?: null) : null,
            'updated' => time(),
        ]);

        // a ban logs them out of here and every app they used watr.lol for
        if ($banned && !$person->banned) {
            (new sessions())->revokeAllForUser($person->id);
            (new server())->revokeAllForUser($person->id);
        }

        flash("Saved {$person->username}.");
        redirect('/admin/users/' . $person->id);
    });

    $router->get('/categories', function () {
        require_role('admin');
        render('admin/categories.twig', ['categories' => (new categories())->all()]);
    });

    $router->post('/categories', function () {
        require_csrf();
        require_role('admin');
        $categories = new categories();

        $name = post('name');
        $slug = post('slug') !== '' ? strtolower(post('slug')) : sanitize::slug($name);
        $description = mb_substr(post('description'), 0, 255);

        $error = $categories->validate($name, $slug);
        if ($error) {
            http_response_code(422);
            render('admin/categories.twig', [
                'categories' => $categories->all(),
                'error' => $error,
                'form' => ['name' => $name, 'slug' => post('slug'), 'description' => $description, 'staff_only' => post('staff_only') === '1'],
            ]);
            return;
        }

        $categories->create($name, $slug, $description, post('staff_only') === '1');
        flash("Made the $name category.");
        redirect('/admin/categories');
    });

    $router->get('/categories/{id}', function ($id) {
        require_role('admin');
        $category = (new categories())->get((int) $id);
        if (!$category) {
            abort(404, "That category doesn't exist.");
        }
        render('admin/category.twig', ['category' => $category]);
    });

    $router->post('/categories/{id}', function ($id) {
        require_csrf();
        require_role('admin');
        $categories = new categories();
        $category = $categories->get((int) $id);
        if (!$category) {
            abort(404, "That category doesn't exist.");
        }

        $name = post('name');
        $slug = strtolower(post('slug'));
        $description = mb_substr(post('description'), 0, 255);

        $error = $categories->validate($name, $slug, (int) $category->id);
        if ($error) {
            http_response_code(422);
            $category->name = $name;
            $category->slug = $slug;
            $category->description = $description;
            render('admin/category.twig', ['category' => $category, 'error' => $error]);
            return;
        }

        $categories->update($category->id, [
            'name' => $name,
            'slug' => $slug,
            'description' => $description ?: null,
            'staff_only' => post('staff_only') === '1' ? 1 : 0,
        ]);
        flash("Saved $name.");
        redirect('/admin/categories');
    });

    $router->post('/categories/{id}/move', function ($id) {
        require_csrf();
        require_role('admin');
        (new categories())->move((int) $id, post('direction') === 'up' ? -1 : 1);
        redirect('/admin/categories');
    });

    $router->post('/categories/{id}/delete', function ($id) {
        require_csrf();
        require_role('admin');
        $categories = new categories();
        $category = $categories->get((int) $id);
        if ($category && !$categories->delete($category->id)) {
            flash("{$category->name} still has threads. Move or delete them first.", 'warning');
        } elseif ($category) {
            flash("Deleted {$category->name}.", 'plain');
        }
        redirect('/admin/categories');
    });

    $router->get('/projects', function () {
        require_role('admin');
        render('admin/projects.twig', ['projects' => (new projects())->all()]);
    });

    $router->post('/projects', function () {
        require_csrf();
        require_role('admin');
        $projects = new projects();

        $name = post('name');
        $description = mb_substr(post('description'), 0, 255);
        $url = post('url');

        $error = $projects->validate($name, $url);
        if ($error) {
            http_response_code(422);
            render('admin/projects.twig', [
                'projects' => $projects->all(),
                'error' => $error,
                'form' => ['name' => $name, 'description' => $description, 'url' => $url, 'active' => post('active') === '1'],
            ]);
            return;
        }

        $projects->create($name, $description, $url, post('active') === '1');
        flash("Added $name.");
        redirect('/admin/projects');
    });

    $router->get('/projects/{id}', function ($id) {
        require_role('admin');
        $project = (new projects())->get((int) $id);
        if (!$project) {
            abort(404, "That project doesn't exist.");
        }
        render('admin/project.twig', ['project' => $project]);
    });

    $router->post('/projects/{id}', function ($id) {
        require_csrf();
        require_role('admin');
        $projects = new projects();
        $project = $projects->get((int) $id);
        if (!$project) {
            abort(404, "That project doesn't exist.");
        }

        $name = post('name');
        $description = mb_substr(post('description'), 0, 255);
        $url = post('url');

        $error = $projects->validate($name, $url);
        if ($error) {
            http_response_code(422);
            $project->name = $name;
            $project->description = $description;
            $project->url = $url;
            $project->active = post('active') === '1';
            render('admin/project.twig', ['project' => $project, 'error' => $error]);
            return;
        }

        $projects->update($project->id, [
            'name' => $name,
            'description' => $description ?: null,
            'url' => $url ?: null,
            'active' => post('active') === '1' ? 1 : 0,
        ]);
        flash("Saved $name.");
        redirect('/admin/projects');
    });

    // shows or hides it on /projects without opening the edit page
    $router->post('/projects/{id}/active', function ($id) {
        require_csrf();
        require_role('admin');
        $projects = new projects();
        $project = $projects->get((int) $id);
        if ($project) {
            $projects->update($project->id, ['active' => $project->active ? 0 : 1]);
            flash($project->active ? "{$project->name} is hidden from /projects." : "{$project->name} is on /projects.", 'plain');
        }
        redirect('/admin/projects');
    });

    $router->post('/projects/{id}/move', function ($id) {
        require_csrf();
        require_role('admin');
        (new projects())->move((int) $id, post('direction') === 'up' ? -1 : 1);
        redirect('/admin/projects');
    });

    $router->post('/projects/{id}/delete', function ($id) {
        require_csrf();
        require_role('admin');
        $projects = new projects();
        $project = $projects->get((int) $id);
        if ($project) {
            $projects->delete($project->id);
            flash("Deleted {$project->name}.", 'plain');
        }
        redirect('/admin/projects');
    });

    $router->get('/apps', function () {
        require_role('admin');
        $apps = (new apps())->all();
        $owners = (new authentication())->mapByIds(array_map(fn ($a) => $a->owner_id, $apps));
        foreach ($apps as $app) {
            $app->owner = $owners[$app->owner_id] ?? null;
        }
        render('admin/apps.twig', ['apps' => $apps]);
    });

    // trusted apps skip the "allow this?" screen. for watrlabs' own sites only
    $router->post('/apps/{id}/trust', function ($id) {
        require_csrf();
        require_role('admin');
        $apps = new apps();
        $app = $apps->get((int) $id);
        if ($app) {
            $apps->update($app->id, ['trusted' => $app->trusted ? 0 : 1]);
            flash($app->trusted ? "{$app->name} asks before signing people in again." : "{$app->name} is trusted. It signs people in without asking.", 'plain');
        }
        redirect('/admin/apps');
    });

});
