<?php

require_once __DIR__ . '/../services/AuthService.php';

class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct(PDO $conn)
    {
        parent::__construct($conn);
        $this->authService = new AuthService($conn);
    }

    public function register($request, $response)
    {
        try {
            $body = $request->getBody();

            $email = trim($body['email'] ?? '');
            $displayName = trim($body['displayName'] ?? '');
            $password = $body['password'] ?? '';
            $confirmPassword = $body['confirmPassword'] ?? '';

            $result = $this->authService->register($email, $displayName, $password, $confirmPassword);

            $response->sendAndContinue([
                'user' => $result['user'],
                'message' => 'Registration successful. Activation email will be sent shortly.'
            ], 201);

            return null;
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function resendActivation($request, $response)
    {
        try {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                return $this->error($response, 'Unauthorized', 401);
            }

            $result = $this->authService->sendActivationEmailForUser($userId);
            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function activate($request, $response)
    {
        try {
            $body = $request->getBody();
            $token = trim($body['token'] ?? '');

            $result = $this->authService->activate($token);

            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function login($request, $response)
    {
        try {
            $body = $request->getBody();
            $email = $body['email'] ?? '';
            $password = $body['password'] ?? '';

            $user = $this->authService->login($email, $password);

            return $this->success($response, [
                'message' => 'Login successful',
                'user' => $user
            ], 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function logout($request, $response)
    {
        try {
            $this->authService->logout();
            return $this->success($response, ['message' => 'Logged out successfully']);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function me($request, $response)
    {
        try {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                return $this->error($response, 'Unauthorized', 401);
            }

            $user = $this->authService->me($userId);
            return $this->success($response, ['user' => $user]);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function forgotPassword($request, $response)
    {
        try {
            $body = $request->getBody();
            $email = trim($body['email'] ?? '');

            $result = $this->authService->forgotPassword($email);

            // Phản hồi cho người dùng TRƯỚC
            $response->sendAndContinue([
                'message' => 'If the email exists, reset instructions have been sent.'
            ], 200);

            // Gửi mail NGẦM
            if (isset($result['token'])) {
                try {
                    $this->authService->getMailService()->sendPasswordResetEmail(
                        $result['email'],
                        $result['displayName'],
                        $result['token'],
                        $result['otp']
                    );
                } catch (Throwable $mailError) {
                    error_log("Background mail error (forgotPassword): " . $mailError->getMessage());
                }
            }

            return null;
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function resetPassword($request, $response)
    {
        try {
            $body = $request->getBody();
            $token = $body['token'] ?? '';
            $password = $body['password'] ?? '';
            $confirmPassword = $body['confirmPassword'] ?? '';

            $result = $this->authService->resetPassword($token, $password, $confirmPassword);

            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function verifyResetOtp($request, $response)
    {
        try {
            $body = $request->getBody();
            $email = trim($body['email'] ?? '');
            $otp = trim($body['otp'] ?? '');

            $result = $this->authService->verifyResetOtp($email, $otp);

            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function verifyResetToken($request, $response)
    {
        try {
            $body = $request->getBody();
            $token = trim($body['token'] ?? '');

            $result = $this->authService->verifyResetToken($token);

            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function resetPasswordOtp($request, $response)
    {
        try {
            $body = $request->getBody();
            $email = trim($body['email'] ?? '');
            $otp = trim($body['otp'] ?? '');
            $password = $body['password'] ?? '';
            $confirmPassword = $body['confirmPassword'] ?? '';

            // 1. Verify OTP first to get token
            $otpResult = $this->authService->verifyResetOtp($email, $otp);
            $token = $otpResult['token'];

            // 2. Perform reset using the token
            $result = $this->authService->resetPassword($token, $password, $confirmPassword);

            return $this->success($response, $result, 200);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }
}
