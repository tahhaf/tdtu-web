<?php

require_once __DIR__ . '/../models/User.php';

class UserService
{
    private User $userModel;

    public function __construct(PDO $conn)
    {
        $this->userModel = new User($conn);
    }

    public function updateProfile($userId, $displayName, $avatarUrl = null)
    {
        if (trim($displayName) === '') {
            throw new Exception('Display name is required', 422);
        }

        $this->userModel->updateProfile($userId, $displayName, $avatarUrl);

        $user = $this->userModel->getById($userId);

        return $this->sanitizeUser($user);
    }

    public function changePassword($userId, $currentPassword, $newPassword, $confirmPassword)
    {
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new Exception('Current password, new password and confirm password are required', 422);
        }

        if (strlen($newPassword) < 6) {
            throw new Exception('New password must be at least 6 characters long', 422);
        }

        if ($newPassword !== $confirmPassword) {
            throw new Exception('New password and confirm password do not match', 422);
        }

        $user = $this->userModel->getById($userId);

        if (!$user) {
            throw new Exception('User not found', 404);
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new Exception('Current password is incorrect', 400);
        }

        $this->userModel->changePassword($userId, $newPassword);

        return [
            'message' => 'Password changed successfully'
        ];
    }

    public function updatePreferences($userId, $theme, $fontSize, $noteColor)
    {
        $allowedThemes = ['light', 'dark'];

        if (!in_array($theme, $allowedThemes, true)) {
            throw new Exception('Invalid theme value', 422);
        }

        $fontSize = (int)$fontSize;
        if ($fontSize <= 0) {
            throw new Exception('Font size must be greater than 0', 422);
        }

        if (trim($noteColor) === '') {
            throw new Exception('Note color is required', 422);
        }

        $this->userModel->updatePreferences($userId, $theme, $fontSize, $noteColor);

        $user = $this->userModel->getById($userId);

        return [
            'message' => 'Preferences updated successfully',
            'preferences' => [
                'theme' => $user['theme'],
                'fontSize' => (int)$user['font_size'],
                'noteColor' => $user['note_color'],
            ]
        ];
    }

    private function sanitizeUser($user)
    {
        return [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'displayName' => $user['display_name'],
            'isActivated' => (bool)$user['is_activated'],
            'avatarUrl' => $user['avatar_url'],
            'preferences' => [
                'theme' => $user['theme'],
                'fontSize' => (int)$user['font_size'],
                'noteColor' => $user['note_color'],
            ],
            'createdAt' => $user['created_at'] ?? null,
            'updatedAt' => $user['updated_at'] ?? null,
        ];
    }
}