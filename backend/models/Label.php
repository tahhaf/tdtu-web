<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class Label
{
    private PDO $conn;
    private string $table = 'labels';

    public function __construct(?PDO $conn = null)
    {
        if ($conn instanceof PDO) {
            $this->conn = $conn;
            return;
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    public function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_label (user_id, name)
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    private function ensureSchema(): void
    {
        Schema::ensureColumns($this->conn, $this->table, [
            'created_at' => "ALTER TABLE {$this->table} ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER name",
            'updated_at' => "ALTER TABLE {$this->table} ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ]);

        Schema::ensureIndex(
            $this->conn,
            $this->table,
            'unique_user_label',
            "ALTER TABLE {$this->table} ADD UNIQUE KEY unique_user_label (user_id, name)"
        );
    }

    public function create($userId, $name)
    {
        $sql = "INSERT INTO {$this->table} (user_id, name)
                VALUES (:user_id, :name)";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name
        ]);
    }

    public function getAllByUser($userId)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE user_id = :user_id
                ORDER BY name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id, $userId)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE id = :id AND user_id = :user_id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $name)
    {
        $sql = "UPDATE {$this->table}
                SET name = :name
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':name' => $name
        ]);
    }

    public function delete($id, $userId)
    {
        $sql = "DELETE FROM {$this->table}
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId
        ]);
    }
}
