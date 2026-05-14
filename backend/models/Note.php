<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class Note
{
    private PDO $conn;
    private string $table = 'notes';

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
            client_id VARCHAR(100) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            note_color VARCHAR(50) DEFAULT NULL,
            is_pinned BOOLEAN DEFAULT FALSE,
            pinned_at DATETIME DEFAULT NULL,
            is_locked BOOLEAN DEFAULT FALSE,
            note_password_hash VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_client_note (user_id, client_id)
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    public function create($userId, $title, $content, $clientId = null)
    {
        $clientId = $clientId !== null && trim((string)$clientId) !== '' ? trim((string)$clientId) : null;

        if ($clientId !== null) {
            $existing = $this->findByClientId($userId, $clientId);
            if ($existing) {
                return (int)$existing['id'];
            }
        }

        $sql = "INSERT INTO {$this->table} (user_id, client_id, title, content)
                VALUES (:user_id, :client_id, :title, :content)";

        $stmt = $this->conn->prepare($sql);

        if ($stmt->execute([
            ':user_id' => $userId,
            ':client_id' => $clientId,
            ':title' => $title,
            ':content' => $content
        ])) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    public function findByClientId($userId, $clientId)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE user_id = :user_id AND client_id = :client_id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':client_id' => $clientId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllByUser($userId)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE user_id = :user_id
                ORDER BY is_pinned DESC, pinned_at DESC, updated_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id, $userId)
    {
        // Check ownership or shared access
        $sql = "SELECT n.*, ns.permission 
                FROM {$this->table} n
                LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.shared_with_user_id = :user_id AND ns.revoked_at IS NULL
                WHERE n.id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $userId, $title, $content, $noteColor = null)
    {
        // Allow if owner OR has 'edit' permission
        $sql = "UPDATE {$this->table} n
                LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.shared_with_user_id = :user_id
                SET n.title = :title,
                    n.content = :content,
                    n.note_color = :note_color
                WHERE n.id = :id 
                  AND (n.user_id = :user_id OR (ns.shared_with_user_id = :user_id AND ns.permission = 'edit' AND ns.revoked_at IS NULL))";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':title' => $title,
            ':content' => $content,
            ':note_color' => $noteColor
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

    public function search($userId, $keyword)
    {
        $sql = "SELECT n.*, ns.permission 
                FROM {$this->table} n
                LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.shared_with_user_id = :user_id AND ns.revoked_at IS NULL
                WHERE (n.user_id = :user_id OR ns.shared_with_user_id = :user_id)
                  AND (
                    n.title LIKE :keyword 
                    OR (n.is_locked = 0 AND n.content LIKE :keyword)
                  )
                ORDER BY n.is_pinned DESC, n.pinned_at DESC, n.updated_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':keyword' => '%' . $keyword . '%'
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setPinStatus($id, $userId, $isPinned)
    {
        $sql = "UPDATE {$this->table}
                SET is_pinned = :is_pinned,
                    pinned_at = :pinned_at
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->conn->prepare($sql);

        $pinnedAt = $isPinned ? date('Y-m-d H:i:s') : null;

        return $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':is_pinned' => $isPinned ? 1 : 0,
            ':pinned_at' => $pinnedAt
        ]);
    }

    public function setLockStatus($id, $userId, $isLocked, $password = null)
    {
        $passwordHash = null;

        if ($isLocked) {
            if ($password === null || trim($password) === '') {
                return false;
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        $sql = "UPDATE {$this->table}
                SET is_locked = :is_locked,
                    note_password_hash = :note_password_hash
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':is_locked' => $isLocked ? 1 : 0,
            ':note_password_hash' => $passwordHash
        ]);
    }

    public function verifyNotePassword($id, $password)
    {
        $sql = "SELECT note_password_hash
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id
        ]);

        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$note || empty($note['note_password_hash'])) {
            return false;
        }

        return password_verify($password, $note['note_password_hash']);
    }

    private function ensureSchema(): void
    {
        Schema::ensureColumns($this->conn, $this->table, [
            'client_id' => "ALTER TABLE {$this->table} ADD client_id VARCHAR(100) DEFAULT NULL AFTER user_id",
            'note_color' => "ALTER TABLE {$this->table} ADD note_color VARCHAR(50) DEFAULT NULL AFTER content",
            'is_pinned' => "ALTER TABLE {$this->table} ADD is_pinned BOOLEAN DEFAULT FALSE AFTER note_color",
            'pinned_at' => "ALTER TABLE {$this->table} ADD pinned_at DATETIME DEFAULT NULL AFTER is_pinned",
            'is_locked' => "ALTER TABLE {$this->table} ADD is_locked BOOLEAN DEFAULT FALSE AFTER pinned_at",
            'note_password_hash' => "ALTER TABLE {$this->table} ADD note_password_hash VARCHAR(255) DEFAULT NULL AFTER is_locked",
            'created_at' => "ALTER TABLE {$this->table} ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note_password_hash",
            'updated_at' => "ALTER TABLE {$this->table} ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ]);

        Schema::ensureIndex(
            $this->conn,
            $this->table,
            'unique_user_client_note',
            "ALTER TABLE {$this->table} ADD UNIQUE KEY unique_user_client_note (user_id, client_id)"
        );
    }
}
