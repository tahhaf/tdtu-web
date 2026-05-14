<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class NoteImage
{
    private PDO $conn;
    private string $table = 'note_images';

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
            note_id INT NOT NULL,
            image_url LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    public function create($noteId, $imageUrl)
    {
        $sql = "INSERT INTO {$this->table} (note_id, image_url)
                VALUES (:note_id, :image_url)";

        $stmt = $this->conn->prepare($sql);

        try {
            return $stmt->execute([
                ':note_id' => $noteId,
                ':image_url' => $imageUrl
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '22001') {
                throw $e;
            }

            $this->ensureSchema();
            $stmt = $this->conn->prepare($sql);

            return $stmt->execute([
                ':note_id' => $noteId,
                ':image_url' => $imageUrl
            ]);
        }
    }

    public function getByNoteId($noteId)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE note_id = :note_id
                ORDER BY id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':note_id' => $noteId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByNoteIds(array $noteIds)
    {
        if (empty($noteIds)) return [];
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $sql = "SELECT * FROM {$this->table} WHERE note_id IN ($placeholders) ORDER BY id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($noteIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete($id, $noteId)
    {
        $sql = "DELETE FROM {$this->table}
                WHERE id = :id AND note_id = :note_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':note_id' => $noteId
        ]);
    }

    private function ensureSchema(): void
    {
        $this->conn->exec("ALTER TABLE {$this->table} MODIFY image_url LONGTEXT NOT NULL");

        Schema::ensureColumns($this->conn, $this->table, [
            'created_at' => "ALTER TABLE {$this->table} ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER image_url",
            'updated_at' => "ALTER TABLE {$this->table} ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ]);
    }
}
