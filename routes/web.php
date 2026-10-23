<?php
use watrlabs\forum\threads;
use watrlabs\oauth\server;
use watrlabs\social\feed;
use watrlabs\social\follows;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->get('/', function () {
    global $db;
    $user = current_user();

    if (!$user) {
        render('home/landing.twig', ['recent' => (new threads())->recent(8)]);
        return;
    }

    $follows = new follows();
    $following = $follows->followingIds($user->id);

    // following is the default once you follow someone, until then everyone is more fun
    $scope = query('feed');
    if (!in_array($scope, ['following', 'everyone'], true)) {
        $scope = $following ? 'following' : 'everyone';
    }

    $authors = $scope === 'following' ? array_merge($following, [(int) $user->id]) : null;
    $page = (new feed())->build($authors, (int) query('before') ?: null, (int) $user->id);

    render('home/dashboard.twig', [
        'scope' => $scope,
        'feed' => $page['items'],
        'next' => $page['next'],
        'following_count' => count($following),
        'suggestions' => $follows->suggestions($user->id, 5),
        'online' => $db->table('users')->where('banned', 0)->where('last_seen', '>', time() - 600)->orderBy('last_seen', 'DESC')->limit(12)->get(),
        'recent' => (new threads())->recent(5),
        'apps' => (new server())->grantsForUser($user->id),
    ]);
});

$router->set404(function ($code) {
    global $twig;
    if ($code === 405) {
        echo $twig->render('status_codes/404.twig', ['message' => "That page exists, but not like this. Try going back and using the buttons on the page."]);
        return;
    }
    echo $twig->render('status_codes/404.twig');
});
