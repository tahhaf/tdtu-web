<?php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../services/LabelService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class LabelController extends Controller
{
    private LabelService $labelService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->labelService = new LabelService($db);
    }

    public function index($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        
        try {
            $labels = $this->labelService->getAllLabels($userId);
            return $this->success($response, $labels);
        } catch (Throwable $e) {
            return $this->error($response, $e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function store($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $data = $request->getBody();
        $name = $data['name'] ?? '';

        try {
            $label = $this->labelService->createLabel($userId, $name);
            return $this->success($response, $label, 201);
        } catch (Throwable $e) {
            return $this->error($response, $e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function update($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');
        $data = $request->getBody();
        $name = $data['name'] ?? '';

        try {
            $label = $this->labelService->updateLabel($id, $userId, $name);
            return $this->success($response, $label);
        } catch (Throwable $e) {
            return $this->error($response, $e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function destroy($request, $response)
    {
        AuthMiddleware::auth($response);
        $userId = $_SESSION['user_id'];
        $id = $request->getParam('id');

        try {
            $this->labelService->deleteLabel($id, $userId);
            return $this->success($response, ['message' => 'Label deleted']);
        } catch (Throwable $e) {
            return $this->error($response, $e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
