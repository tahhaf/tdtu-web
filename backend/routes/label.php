<?php

require_once __DIR__ . '/../controllers/LabelController.php';

$router->get('/api/labels', [LabelController::class, 'index']);
$router->post('/api/labels', [LabelController::class, 'store']);
$router->put('/api/labels/{id}', [LabelController::class, 'update']);
$router->delete('/api/labels/{id}', [LabelController::class, 'destroy']);
