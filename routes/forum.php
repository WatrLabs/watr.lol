<?php
use watrlabs\roles;
use watrlabs\throttle;
use watrlabs\forum\categories;
use watrlabs\forum\threads;
use watrlabs\forum\posts;

global $router; // IMPORTANT: KEEP THIS HERE!

const THREADS_PER_PAGE = 25;
const POSTS_PER_PAGE = 20;

function forum_thread_or_404($id) {
    $thread = (new threads())->get((int) $id);
    if (!$thread) {
        abort(404, "That thread doesn't exist. It might have been deleted.");
    }
    $thread->category = (new categories())->get($thread->category_id);
    return $thread;
}

function forum_post_or_404($id) {
    $post = (new posts())->get((int) $id);
    if (!$post) {
        abort(404, "That post doesn't exist. It might have been deleted.");
    }
    return $post;
}

// the author can change their own post, staff can change anyone's
function forum_can_edit($user, $post, $thread): bool {
    if (!$user) return false;
    if (roles::atLeast($user, 'mod')) return true;
    return (int) $post->user_id === (int) $user->id && !$thread->locked;
}

function forum_post_too_fast($user): bool {
    if (roles::atLeast($user, 'mod')) {
        return false;
    }
    return throttle::tooMany('post', (string) $user->id, 10, 300);
}

$router->group('/forum', function ($router) {

    $router->get('/', function () {
        render('forum/index.twig', [
            'categories' => (new categories())->all(),
            'recent' => (new threads())->recent(10),
        ]);
    });

    $router->get('/t/{id}', function ($id) {
        $thread = forum_thread_or_404($id);
        $pager = paginate((int) $thread->post_count, POSTS_PER_PAGE);

        render('forum/thread.twig', [
            'thread' => $thread,
            'first_post_id' => (new posts())->first($thread->id)->id ?? null,
            'posts' => (new posts())->listForThread($thread->id, $pager['offset'], $pager['per']),
            'pager' => $pager,
        ]);
    });

    $router->post('/t/{id}/reply', function ($id) {
        require_csrf();
        $user = require_login();
        $thread = forum_thread_or_404($id);
        $posts = new posts();
        $body = post('body');

        $error = null;
        if ($thread->locked && !roles::atLeast($user, 'mod')) {
            $error = 'This thread is locked. Nobody can reply to it.';
        } elseif (forum_post_too_fast($user)) {
            $error = "You're posting a lot. Wait a few minutes and try again.";
        } else {
            $error = $posts->validateBody($body);
        }

        if ($error) {
            http_response_code(422);
            $pager = paginate((int) $thread->post_count, POSTS_PER_PAGE, PHP_INT_MAX);
            render('forum/thread.twig', [
                'thread' => $thread,
                'first_post_id' => $posts->first($thread->id)->id ?? null,
                'posts' => $posts->listForThread($thread->id, $pager['offset'], $pager['per']),
                'pager' => $pager,
                'error' => $error,
                'draft' => $body,
            ]);
            return;
        }

        throttle::hit('post', (string) $user->id);
        $postId = $posts->create($thread->id, $user->id, $body);
        redirect('/forum/p/' . $postId);
    });

    // staff buttons on a thread
    $router->post('/t/{id}/pin', function ($id) {
        require_csrf();
        require_role('mod');
        $thread = forum_thread_or_404($id);
        (new threads())->update($thread->id, ['pinned' => $thread->pinned ? 0 : 1]);
        flash($thread->pinned ? 'Unpinned the thread.' : 'Pinned the thread.', 'plain');
        redirect('/forum/t/' . $thread->id);
    });

    $router->post('/t/{id}/lock', function ($id) {
        require_csrf();
        require_role('mod');
        $thread = forum_thread_or_404($id);
        (new threads())->update($thread->id, ['locked' => $thread->locked ? 0 : 1]);
        flash($thread->locked ? 'Unlocked the thread.' : 'Locked the thread.', 'plain');
        redirect('/forum/t/' . $thread->id);
    });

    $router->post('/t/{id}/move', function ($id) {
        require_csrf();
        require_role('mod');
        $thread = forum_thread_or_404($id);
        $category = (new categories())->get((int) post('category'));
        if ($category) {
            (new threads())->update($thread->id, ['category_id' => $category->id]);
            flash("Moved the thread to {$category->name}.", 'plain');
        }
        redirect('/forum/t/' . $thread->id);
    });

    $router->post('/t/{id}/delete', function ($id) {
        require_csrf();
        require_role('mod');
        $thread = forum_thread_or_404($id);
        (new threads())->delete($thread->id);
        flash('Deleted the thread "' . $thread->title . '".', 'plain');
        redirect('/forum/' . ($thread->category->slug ?? ''));
    });

    // links to a single post land on the right page of its thread
    $router->get('/p/{id}', function ($id) {
        $post = forum_post_or_404($id);
        $page = (new posts())->pageOf($post, POSTS_PER_PAGE);
        redirect('/forum/t/' . $post->thread_id . ($page > 1 ? '?page=' . $page : '') . '#p' . $post->id);
    });

    $router->get('/p/{id}/edit', function ($id) {
        $user = require_login();
        $post = forum_post_or_404($id);
        $thread = forum_thread_or_404($post->thread_id);
        if (!forum_can_edit($user, $post, $thread)) {
            abort(403, "You can't edit this post.");
        }

        $isFirst = (int) ((new posts())->first($thread->id)->id ?? 0) === (int) $post->id;
        render('forum/edit.twig', ['post' => $post, 'thread' => $thread, 'is_first' => $isFirst, 'body' => $post->body, 'title' => $thread->title]);
    });

    $router->post('/p/{id}/edit', function ($id) {
        require_csrf();
        $user = require_login();
        $post = forum_post_or_404($id);
        $thread = forum_thread_or_404($post->thread_id);
        if (!forum_can_edit($user, $post, $thread)) {
            abort(403, "You can't edit this post.");
        }

        $posts = new posts();
        $threads = new threads();
        $isFirst = (int) ($posts->first($thread->id)->id ?? 0) === (int) $post->id;
        $body = post('body');
        $title = post('title');

        $error = $posts->validateBody($body);
        if (!$error && $isFirst) {
            $error = $threads->validateTitle($title);
        }

        if ($error) {
            http_response_code(422);
            render('forum/edit.twig', ['post' => $post, 'thread' => $thread, 'is_first' => $isFirst, 'body' => $body, 'title' => $title, 'error' => $error]);
            return;
        }

        $posts->update($post->id, $body);
        if ($isFirst && $title !== $thread->title) {
            $threads->update($thread->id, ['title' => $title]);
        }

        flash('Saved your changes.');
        redirect('/forum/p/' . $post->id);
    });

    $router->post('/p/{id}/delete', function ($id) {
        require_csrf();
        $user = require_login();
        $post = forum_post_or_404($id);
        $thread = forum_thread_or_404($post->thread_id);
        if (!forum_can_edit($user, $post, $thread)) {
            abort(403, "You can't delete this post.");
        }

        $isFirst = (int) ((new posts())->first($thread->id)->id ?? 0) === (int) $post->id;
        (new posts())->delete($post);

        if ($isFirst) {
            flash('Deleted the thread "' . $thread->title . '".', 'plain');
            redirect('/forum/' . ($thread->category->slug ?? ''));
        }

        flash('Deleted the post.', 'plain');
        redirect('/forum/t/' . $thread->id);
    });

    $router->get('/{slug}', function ($slug) {
        $category = (new categories())->bySlug($slug);
        if (!$category) {
            abort(404, "There's no forum category called \"$slug\".");
        }

        $threads = new threads();
        $pager = paginate($threads->countForCategory($category->id), THREADS_PER_PAGE);

        render('forum/category.twig', [
            'category' => $category,
            'threads' => $threads->listForCategory($category->id, $pager['offset'], $pager['per']),
            'pager' => $pager,
        ]);
    });

    $router->get('/{slug}/new', function ($slug) {
        $user = require_login();
        $category = (new categories())->bySlug($slug);
        if (!$category) {
            abort(404, "There's no forum category called \"$slug\".");
        }
        if ($category->staff_only && !roles::atLeast($user, 'mod')) {
            abort(403, "Only staff can start threads in {$category->name}.");
        }
        render('forum/new.twig', ['category' => $category]);
    });

    $router->post('/{slug}/new', function ($slug) {
        require_csrf();
        $user = require_login();
        $category = (new categories())->bySlug($slug);
        if (!$category) {
            abort(404, "There's no forum category called \"$slug\".");
        }
        if ($category->staff_only && !roles::atLeast($user, 'mod')) {
            abort(403, "Only staff can start threads in {$category->name}.");
        }

        $threads = new threads();
        $title = post('title');
        $body = post('body');

        $error = $threads->validateTitle($title) ?? (new posts())->validateBody($body);
        if (!$error && forum_post_too_fast($user)) {
            $error = "You're posting a lot. Wait a few minutes and try again.";
        }

        if ($error) {
            http_response_code(422);
            render('forum/new.twig', ['category' => $category, 'title' => $title, 'body' => $body, 'error' => $error]);
            return;
        }

        throttle::hit('post', (string) $user->id);
        $threadId = $threads->create($category->id, $user->id, $title, $body);
        redirect('/forum/t/' . $threadId);
    });

});
