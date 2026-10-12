<?php
use watrlabs\forum\threads;
use watrlabs\oauth\server;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->get('/', function () {
    $user = current_user();
    $recent = (new threads())->recent(8);

    if (!$user) {
        render('home/landing.twig', ['recent' => $recent]);
        return;
    }

    render('home/dashboard.twig', [
        'recent' => $recent,
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
