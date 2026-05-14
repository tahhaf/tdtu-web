<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class NoteShare
{
    private \PDO $conn;
    private string $table = 'note_shares';

    public function __construct(?\PDO $conn = null)
    {
        if ($conn instanceof \PDO) {
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
            note_id INT NOT NULL,
            owner_id INT NOT NULL,
            shared_with_user_id INT NOT NULL,
            permission ENUM('read', 'edit') NOT NULL DEFAULT 'read',
            shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME DEFAULT NULL,
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_note_share (note_id, shared_with_user_id)
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    public function create($noteId, $ownerId, $sharedWithUserId, $permission = 'read')
    {
        $sql = "INSERT INTO {$this->table} (note_id, owner_id, shared_with_user_id, permission, revoked_at)
                VALUES (:note_id, :owner_id, :shared_with_user_id, :permission, NULL)
                ON DUPLICATE KEY UPDATE
                    permission = :permission_update,
                    revoked_at = NULL,
                    shared_at = CURRENT_TIMESTAMP";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':note_id' => $noteId,
            ':owner_id' => $ownerId,
            ':shared_with_user_id' => $sharedWithUserId,
            ':permission' => $permission,
            ':permission_update' => $permission
        ]);
    }

    private function ensureSchema(): void
    {
        Schema::ensureColumns($this->conn, $this->table, [
            'permission' => "ALTER TABLE {$this->table} ADD permission ENUM('read', 'edit') NOT NULL DEFAULT 'read' AFTER shared_with_user_id",
            'shared_at' => "ALTER TABLE {$this->table} ADD shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER permission",
            'revoked_at' => "ALTER TABLE {$this->table} ADD revoked_at DATETIME DEFAULT NULL AFTER shared_at",
        ]);

        Schema::ensureIndex(
            $this->conn,
            $this->table,
            'unique_note_share',
            "ALTER TABLE {$this->table} ADD UNIQUE KEY unique_note_share (note_id, shared_with_user_id)"
        );
    }

    public function getByOwner($ownerId)
    {
        $sql = "SELECT ns.*, n.title, u.email AS recipient_email, u.display_name AS recipient_name
                FROM {$this->table} ns
                INNER JOIN notes n ON ns.note_id = n.id
                INNER JOIN users u ON ns.shared_with_user_id = u.id
                WHERE ns.owner_id = :owner_id
                  AND ns.revoked_at IS NULL
                ORDER BY ns.shared_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':owner_id' => $ownerId
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getReceivedByUser($userId)
    {
        $sql = "SELECT ns.*, n.*, u.email AS owner_email, u.display_name AS owner_name
                FROM {$this->table} ns
                INNER JOIN notes n ON ns.note_id = n.id
                INNER JOIN users u ON ns.owner_id = u.id
                WHERE ns.shared_with_user_id = :user_id
                  AND ns.revoked_at IS NULL
                ORDER BY ns.shared_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getShare($noteId, $userId)
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE note_id = :note_id AND shared_with_user_id = :user_id AND revoked_at IS NULL
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':note_id' => $noteId,
            ':user_id' => $userId
        ]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updatePermission($noteId, $ownerId, $sharedWithUserId, $permission)
    {
        $sql = "UPDATE {$this->table}
                SET permission = :permission
                WHERE note_id = :note_id
                  AND owner_id = :owner_id
                  AND shared_with_user_id = :shared_with_user_id
                  AND revoked_at IS NULL";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':permission' => $permission,
            ':note_id' => $noteId,
            ':owner_id' => $ownerId,
            ':shared_with_user_id' => $sharedWithUserId
        ]);
    }

    public function revoke($noteId, $ownerId, $sharedWithUserId)
    {
        $sql = "UPDATE {$this->table}
                SET revoked_at = NOW()
                WHERE note_id = :note_id
                  AND owner_id = :owner_id
                  AND shared_with_user_id = :shared_with_user_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':note_id' => $noteId,
            ':owner_id' => $ownerId,
            ':shared_with_user_id' => $sharedWithUserId
        ]);
    }
}
