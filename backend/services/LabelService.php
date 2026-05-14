<?php

require_once __DIR__ . '/../models/Label.php';

class LabelService
{
    private Label $labelModel;

    public function __construct(PDO $db)
    {
        $this->labelModel = new Label($db);
    }

    public function getAllLabels($userId)
    {
        return $this->labelModel->getAllByUser($userId);
    }

    public function createLabel($userId, $name)
    {
        if (empty($name)) {
            throw new Exception('Name is required', 400);
        }

        if (!$this->labelModel->create($userId, $name)) {
            throw new Exception('Failed to create label', 500);
        }

        $labels = $this->labelModel->getAllByUser($userId);
        return array_values(array_filter($labels, fn($l) => $l['name'] === $name))[0] ?? null;
    }

    public function updateLabel($id, $userId, $name)
    {
        if (empty($name)) {
            throw new Exception('Name is required', 400);
        }

        if (!$this->labelModel->update($id, $userId, $name)) {
            throw new Exception('Failed to update label', 500);
        }

        return ['id' => $id, 'name' => $name];
    }

    public function deleteLabel($id, $userId)
    {
        if (!$this->labelModel->delete($id, $userId)) {
            throw new Exception('Failed to delete label', 500);
        }
        return true;
    }
}
