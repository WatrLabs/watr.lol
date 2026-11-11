<?php
use watrlabs\authentication;
use watrlabs\roles;
use watrlabs\throttle;
use watrlabs\forum\posts;
use watrlabs\forum\threads;
use watrlabs\social\follows;
use watrlabs\social\notifications;
use watrlabs\social\reactions;
use watrlabs\social\statuses;

global $router; // IMPORTANT: KEEP THIS HERE!

const PEOPLE_PER_PAGE = 30;
const NOTIFICATIONS_PER_PAGE = 30;

function profile_or_404($username) {
    $profile = (new authentication())->getByUsername((string) $username);
    if (!$profile) {
        abort(404, "Nobody here goes by \"$username\".");
    }
    return $profile;
}

// ---------- following ----------

$router->post('/u/{username}/follow', function ($username) {
    require_csrf();
    $user = require_login();
    $profile = profile_or_404($username);

    if (throttle::tooMany('follow', (string) $user->id, 60, 3600)) {
        flash("You've followed a lot of people this hour. Try again later.", 'warning');
        redirect('/u/' . $profile->username);
    }
    throttle::hit('follow', (string) $user->id);

    $error = (new follows())->follow($user, $profile);
    flash($error ?? 'Following ' . ($profile->display_name ?: $profile->username) . '.', $error ? 'warning' : 'info');
    redirect(safe_continue(post('continue'), '/u/' . $profile->username));
});

$router->post('/u/{username}/unfollow', function ($username) {
    require_csrf();
    $user = require_login();
    $profile = profile_or_404($username);
    (new follows())->unfollow((int) $user->id, (int) $profile->id);
    flash('Unfollowed ' . ($profile->display_name ?: $profile->username) . '.', 'plain');
    redirect(safe_continue(post('continue'), '/u/' . $profile->username));
});

foreach (['followers', 'following'] as $which) {
    $router->get('/u/{username}/' . $which, function ($username) use ($which) {
        $profile = profile_or_404($username);
        $follows = new follows();
        $total = $which === 'followers' ? $follows->followerCount($profile->id) : $follows->followingCount($profile->id);
        $pager = paginate($total, PEOPLE_PER_PAGE);
        $me = current_user();

        render('users/follows.twig', [
            'profile' => $profile,
            'which' => $which,
            'people' => $follows->listPeople($profile->id, $which, $pager['offset'], $pager['per']),
            'my_following' => $me ? $follows->followingIds($me->id) : [],
            'pager' => $pager,
        ]);
    });
}

// ---------- people ----------

$router->get('/people', function () {
    global $db;
    $q = mb_substr(query('q'), 0, 40);

    $base = fn () => $db->table('users')->where('banned', 0);
    $count = $base();
    $list = $base();
    if ($q !== '') {
        $like = '%' . addcslashes(strtolower($q), '%_\\') . '%';
        foreach ([$count, $list] as $query) {
            $query->where(function ($w) use ($like, $db) {
                $w->where('username_lower', 'LIKE', $like)->orWhere($db->raw('LOWER(display_name)'), 'LIKE', $like);
            });
        }
    }

    $pager = paginate($count->count(), PEOPLE_PER_PAGE);
    $me = current_user();

    render('users/people.twig', [
        'q' => $q,
        'people' => $list->orderBy('last_seen', 'DESC')->limit($pager['per'])->offset($pager['offset'])->get(),
        'my_following' => $me ? (new follows())->followingIds($me->id) : [],
        'pager' => $pager,
    ]);
});

// ---------- updates ----------

$router->post('/updates', function () {
    require_csrf();
    $user = require_login();
    $statuses = new statuses();
    $body = post('body');

    $error = $statuses->validate($body);
    if (!$error && !roles::atLeast($user, 'mod') && throttle::tooMany('post', (string) $user->id, 10, 300)) {
        $error = "You're posting a lot. Wait a few minutes and try again.";
    }
    if ($error) {
        flash($error, 'warning');
        redirect('/');
    }

    throttle::hit('post', (string) $user->id);
    $statuses->create($user, $body);
    flash('Posted.');
    redirect(safe_continue(post('continue'), '/'));
});

$router->get('/updates/{id}', function ($id) {
    $status = (new statuses())->get((int) $id);
    if (!$status || !$status->author || $status->author->banned) {
        abort(404, "That update doesn't exist. It might have been deleted.");
    }
    $me = current_user();
    $status->reactions = (new reactions())->summarize('status', [$status->id], $me ? (int) $me->id : null)[$status->id] ?? [];
    render('social/update.twig', ['status' => $status]);
});

$router->post('/updates/{id}/delete', function ($id) {
    require_csrf();
    $user = require_login();
    $statuses = new statuses();
    $status = $statuses->get((int) $id);
    if (!$status) {
        redirect('/');
    }
    if ((int) $status->user_id !== (int) $user->id && !roles::atLeast($user, 'mod')) {
        abort(403, "You can't delete someone else's update.");
    }
    $statuses->delete((int) $status->id);
    flash('Deleted the update.', 'plain');
    redirect(safe_continue(post('continue'), '/'));
});

// ---------- reactions ----------

$router->post('/react', function () {
    require_csrf();
    $user = require_login();

    $target = post('target');
    $targetId = (int) post('id');
    $emoji = post('emoji');
    $back = safe_continue(post('continue'), '/');

    if (!in_array($target, reactions::TARGETS, true) || !isset(reactions::EMOJI[$emoji])) {
        abort(404);
    }

    // what the reaction is on, who wrote it and where it lives
    if ($target === 'post') {
        $thing = (new posts())->get($targetId);
        $thread = $thing ? (new threads())->get((int) $thing->thread_id) : null;
        $url = '/forum/p/' . $targetId;
        $summary = $thread ? $thread->title : '';
    } else {
        $thing = (new statuses())->get($targetId);
        $url = '/updates/' . $targetId;
        $summary = $thing ? $thing->body : '';
    }
    if (!$thing) {
        abort(404, "That doesn't exist anymore.");
    }

    if (throttle::tooMany('react', (string) $user->id, 120, 600)) {
        if (wants_json()) {
            http_response_code(429);
            return ['error' => 'Slow down a bit.'];
        }
        flash('Slow down a bit.', 'warning');
        redirect($back);
    }
    throttle::hit('react', (string) $user->id);

    $reactions = new reactions();
    $reactions->toggle($target, $targetId, $user, (int) $thing->user_id, $url, $summary, $emoji);

    if (wants_json()) {
        return ['reactions' => (object) ($reactions->summarize($target, [$targetId], (int) $user->id)[$targetId] ?? [])];
    }
    redirect($back);
});

// ---------- notifications ----------

$router->group('/notifications', function ($router) {

    $router->get('/', function () {
        $user = require_login();
        $notes = new notifications();
        $pager = paginate($notes->countFor($user->id), NOTIFICATIONS_PER_PAGE);
        render('social/notifications.twig', [
            'notes' => $notes->listFor($user->id, $pager['offset'], $pager['per']),
            'pager' => $pager,
        ]);
    });

    // clicking one marks it seen and goes where it points
    $router->get('/{id}', function ($id) {
        $user = require_login();
        $notes = new notifications();
        $note = $notes->get($user->id, (int) $id);
        if (!$note) {
            redirect('/notifications');
        }
        $notes->markSeen($user->id, (int) $note->id);
        redirect(safe_continue($note->url, '/notifications'));
    });

    $router->post('/seen', function () {
        require_csrf();
        $user = require_login();
        (new notifications())->markSeen($user->id);
        flash('Marked everything as read.', 'plain');
        redirect('/notifications');
    });

    $router->post('/clear', function () {
        require_csrf();
        $user = require_login();
        (new notifications())->clear($user->id);
        flash('Cleared your notifications.', 'plain');
        redirect('/notifications');
    });

});
