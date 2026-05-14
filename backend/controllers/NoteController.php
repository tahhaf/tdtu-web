<?php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../services/NoteService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class NoteController extends Controller
{
    private NoteService $noteService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->noteService = new NoteService($db);
    }

    public function index($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        
        $search = $_GET['q'] ?? null;
        $labelId = $_GET['labelId'] ?? null;

        try {
            $notes = $this->noteService->getAllNotes($userId, $search, $labelId);
            return $this->success($response, $notes);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function show($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        
        try {
            $note = $this->noteService->getNote($id, $userId);
            return $this->success($response, $note);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function store($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $data = $request->getBody();

        try {
            $note = $this->noteService->createNote($userId, $data);
            return $this->success($response, $note, 201);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function update($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $data = $request->getBody();

        try {
            $note = $this->noteService->updateNote($id, $userId, $data);
            return $this->success($response, $note);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function destroy($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $password = $body['password'] ?? null;

        try {
            $this->noteService->deleteNote($id, $userId, $password);
            return $this->success($response, ['message' => 'Note deleted']);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function togglePin($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $isPinned = isset($body['isPinned']) ? (bool)$body['isPinned'] : null;

        try {
            $result = $this->noteService->togglePin($id, $userId, $isPinned);
            return $this->success($response, $result);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function setLock($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();

        try {
            $result = $this->noteService->setLock($id, $userId, $body);
            $response->sendAndContinue($result);
            return null;
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function verifyPassword($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $password = $body['password'] ?? '';

        try {
            $note = $this->noteService->verifyPassword($id, $userId, $password);
            return $this->success($response, $note);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function fetchShared($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];

        try {
            $sharedNotes = $this->noteService->getSharedNotes($userId);
            return $this->success($response, $sharedNotes);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function share($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $email = $body['email'] ?? '';
        $role = $body['role'] ?? 'read';

        try {
            $result = $this->noteService->shareNote($id, $userId, $email, $role);
            
            // Return response to user IMMEDIATELY
            $response->sendAndContinue([
                'message' => 'Note shared successfully. Notifications are being sent.',
                'success' => $result['success'],
                'errors' => $result['errors']
            ]);

            // Process email notifications in the BACKGROUND
            if (!empty($result['notifications'])) {
                $mailService = $this->noteService->getMailService();
                foreach ($result['notifications'] as $notif) {
                    try {
                        $mailService->sendShareNotificationEmail(
                            $notif['email'],
                            $notif['displayName'],
                            $notif['senderName'],
                            $notif['noteTitle'],
                            $notif['role']
                        );
                    } catch (Throwable $mailError) {
                        error_log("Background share mail error: " . $mailError->getMessage());
                    }
                }
            }

            return null;
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function updateSharePermission($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $email = $body['email'] ?? '';
        $role = $body['role'] ?? '';

        try {
            $this->noteService->updateSharePermission($id, $userId, $email, $role);
            return $this->success($response, ['message' => 'Share permission updated']);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }

    public function revokeShare($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $body = $request->getBody();
        $email = $body['email'] ?? '';

        try {
            $this->noteService->revokeShare($id, $userId, $email);
            return $this->success($response, ['message' => 'Share revoked']);
        } catch (Throwable $e) {
            return $this->handleException($response, $e);
        }
    }
}
