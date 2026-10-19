<?php
use watrlabs\projects;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->get('/projects', function () {
    render('projects/index.twig', ['projects' => (new projects())->active()]);
});
