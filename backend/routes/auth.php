<?php

require_once __DIR__ . '/../controllers/AuthController.php';

$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/resend-activation', [AuthController::class, 'resendActivation']);
$router->post('/api/auth/activate', [AuthController::class, 'activate']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/verify-reset-token', [AuthController::class, 'verifyResetToken']);
$router->post('/api/auth/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);
$router->post('/api/auth/reset-password-otp', [AuthController::class, 'resetPasswordOtp']);
