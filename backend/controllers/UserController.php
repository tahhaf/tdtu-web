<?php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UserService.php';

class UserController extends Controller
{
    private AuthService $authService;
    private UserService $userService;

    public function __construct(PDO $conn)
    {
        parent::__construct($conn);
        $this->authService = new AuthService($conn);
        $this->userService = new UserService($conn);
    }

    /**
     * Get current logged-in user info (Criterion 5)
     */
    public function me($request, $response)
    {
        try {
            $user = $this->authService->getCurrentUser();
            // Return flat data for FE
            return $response->json($user, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    /**
     * Update display name and avatar (Criterion 6)
     */
    public function updateProfile($request, $response)
    {
        try {
            $user = $this->authService->getCurrentUser();
            $userId = $user['id'];
            
            $body = $request->getBody();
            $displayName = trim($body['displayName'] ?? '');
            $avatarUrl = $body['avatarUrl'] ?? null;

            $user = $this->userService->updateProfile($userId, $displayName, $avatarUrl);

            return $response->json([
                'message' => 'Profile updated successfully',
                'user' => $user
            ], 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    /**
     * Change user password (Criterion 7)
     */
    public function changePassword($request, $response)
    {
        try {
            $user = $this->authService->getCurrentUser();
            $userId = $user['id'];
            
            $body = $request->getBody();
            $currentPassword = $body['currentPassword'] ?? '';
            $newPassword = $body['newPassword'] ?? '';
            $confirmPassword = $body['confirmPassword'] ?? '';

            $result = $this->userService->changePassword($userId, $currentPassword, $newPassword, $confirmPassword);

            return $response->json($result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    /**
     * Update theme and preferences (Criterion 8)
     */
    public function updatePreferences($request, $response)
    {
        try {
            $user = $this->authService->getCurrentUser();
            $userId = $user['id'];
            
            $body = $request->getBody();
            $theme = $body['theme'] ?? 'light';
            $fontSize = $body['fontSize'] ?? 14;
            $noteColor = $body['noteColor'] ?? 'default';

            $result = $this->userService->updatePreferences($userId, $theme, $fontSize, $noteColor);

            return $response->json($result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }
}
