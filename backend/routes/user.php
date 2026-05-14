<?php

require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

$router->get('/api/users/me', function ($request, $response, $conn) {
    AuthMiddleware::auth($response);

    $controller = new UserController($conn);
    return $controller->me($request, $response);
});

$router->put('/api/users/me/profile', function ($request, $response, $conn) {
    AuthMiddleware::auth($response);

    $controller = new UserController($conn);
    return $controller->updateProfile($request, $response);
});

$router->put('/api/users/me/password', function ($request, $response, $conn) {
    AuthMiddleware::auth($response);

    $controller = new UserController($conn);
    return $controller->changePassword($request, $response);
});

$router->put('/api/users/me/preferences', function ($request, $response, $conn) {
    AuthMiddleware::auth($response);

    $controller = new UserController($conn);
    return $controller->updatePreferences($request, $response);
});