<?php

require_once __DIR__ . '/../controllers/NoteController.php';

$router->get('/api/notes', [NoteController::class, 'index']);
$router->get('/api/notes/{id}', [NoteController::class, 'show']);
$router->post('/api/notes', [NoteController::class, 'store']);
$router->put('/api/notes/{id}', [NoteController::class, 'update']);
$router->delete('/api/notes/{id}', [NoteController::class, 'destroy']);
$router->post('/api/notes/{id}/pin', [NoteController::class, 'togglePin']);
$router->post('/api/notes/{id}/lock', [NoteController::class, 'setLock']);
$router->post('/api/notes/{id}/verify-password', [NoteController::class, 'verifyPassword']);

$router->get('/api/shared-notes', [NoteController::class, 'fetchShared']);
$router->post('/api/notes/{id}/share', [NoteController::class, 'share']);
$router->patch('/api/notes/{id}/share', [NoteController::class, 'updateSharePermission']);
$router->post('/api/notes/{id}/revoke-share', [NoteController::class, 'revokeShare']);
