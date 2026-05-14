<?php

require_once __DIR__ . '/../core/Response.php';

class AuthMiddleware
{
    public static function auth(Response $response)
    {
        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        $response->error('Unauthorized', 401);
        exit;
    }
}