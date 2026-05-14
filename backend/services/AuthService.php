<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/MailService.php';

class AuthService
{
    private User $userModel;
    private MailService $mailService;

    public function __construct(\PDO $conn)
    {
        $this->userModel = new User($conn);
        $this->mailService = new MailService();
    }

    public function login($email, $password)
    {
        $email = strtolower(trim($email));
        $user = $this->userModel->findByEmail($email);

        if (!$user) {
            throw new Exception('Invalid email or password', 401);
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception('Invalid email or password', 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        session_write_close();

        return $this->sanitizeUser($user);
    }

    public function logout()
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        return true;
    }

    public function register($email, $displayName, $password, $confirmPassword)
    {
        $email = strtolower(trim($email));
        if ($email === '' || $displayName === '' || $password === '' || $confirmPassword === '') {
            throw new Exception('All fields are required', 422);
        }

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters long', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format', 422);
        }

        if ($password !== $confirmPassword) {
            throw new Exception('Password and confirm password do not match', 422);
        }

        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser) {
            throw new Exception('Email already exists', 409);
        }

        $db = $this->userModel->getDb();
        $db->beginTransaction();

        try {
            $userId = $this->userModel->create($email, $displayName, $password);

            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $this->userModel->saveActivationToken($userId, $token, $expires);

            $user = $this->userModel->getById($userId);
            
            $db->commit();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            session_write_close();

            return [
                'user' => $this->sanitizeUser($user),
                'token' => $token,
                'email' => $email,
                'displayName' => $displayName
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function activate($token)
    {
        if (trim($token) === '') {
            throw new Exception('Activation token is required', 422);
        }

        $activated = $this->userModel->activateAccount($token);

        if (!$activated) {
            throw new Exception('Invalid or expired activation token', 400);
        }

        return [
            'message' => 'Account activated successfully'
        ];
    }

    public function sendActivationEmailForUser($userId)
    {
        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new Exception('User not found', 404);
        }

        if (!empty($user['is_activated'])) {
            return [
                'message' => 'Account is already activated'
            ];
        }

        $token = $user['activation_token'] ?? null;
        $expires = $user['activation_expires'] ?? null;
        $isExpired = !$expires || strtotime($expires) < time();

        if (!$token || $isExpired) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $this->userModel->saveActivationToken($userId, $token, $expires);
        }

        $sent = $this->mailService->sendActivationEmail(
            $user['email'],
            $user['display_name'],
            $token
        );

        if (!$sent) {
            throw new Exception('Unable to send activation email', 500);
        }

        return [
            'message' => 'Activation email sent'
        ];
    }

    public function forgotPassword($email)
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new Exception('Email is required', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format', 422);
        }

        $user = $this->userModel->findByEmail($email);

        if (!$user) {
            return [
                'message' => 'If the email exists, reset instructions have been sent.'
            ];
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $otp = (string)rand(100000, 999999);
        $this->userModel->saveResetToken($email, $token, $otp, $expires);

        return [
            'token' => $token,
            'otp' => $otp,
            'email' => $email,
            'displayName' => $user['display_name']
        ];
    }

    public function resetPassword($token, $password, $confirmPassword)
    {
        if (trim($token) === '' || $password === '' || $confirmPassword === '') {
            throw new Exception('All fields are required', 422);
        }

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters long', 422);
        }

        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match', 422);
        }

        $user = $this->userModel->findByResetToken($token);
        if (!$user) {
            throw new Exception('Invalid or expired reset token', 400);
        }

        $updated = $this->userModel->resetPassword($user['id'], $password);

        return [
            'message' => 'Password has been reset successfully'
        ];
    }

    public function me($userId)
    {
        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new Exception('User not found', 404);
        }

        return $this->sanitizeUser($user);
    }

    private function sanitizeUser($user)
    {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'displayName' => $user['display_name'],
            'avatarUrl' => $user['avatar_url'],
            'isActivated' => (bool)$user['is_activated'],
            'isVerified' => (bool)$user['is_activated'], // Legacy alias
            'preferences' => [
                'noteColor' => $user['note_color'] ?? 'default',
                'fontSize' => (int)($user['font_size'] ?? 14),
                'theme' => $user['theme'] ?? 'light'
            ],
            'createdAt' => $user['created_at']
        ];
    }

    public function verifyResetOtp($email, $otp)
    {
        $user = $this->userModel->findByResetOtp($email, $otp);
        if (!$user) {
            throw new Exception('Invalid or expired OTP', 400);
        }
        return ['token' => $user['reset_token']];
    }

    public function verifyResetToken($token)
    {
        $user = $this->userModel->findByResetToken($token);
        if (!$user) {
            throw new Exception('Invalid or expired reset token', 400);
        }
        return ['email' => $user['email']];
    }

    public function getCurrentUser()
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            throw new Exception('Unauthorized', 401);
        }

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new Exception('User not found', 404);
        }

        return $this->sanitizeUser($user);
    }

    public function getMailService(): MailService {
        return $this->mailService;
    }
}
