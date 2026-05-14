<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class NoteLabel
{
    private PDO $conn;
    private string $table = 'note_labels';

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
            note_id INT NOT NULL,
            label_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (note_id, label_id),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    private function ensureSchema(): void
    {
        Schema::ensureColumns($this->conn, $this->table, [
            'created_at' => "ALTER TABLE {$this->table} ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER label_id",
            'updated_at' => "ALTER TABLE {$this->table} ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ]);
    }

    public function attachLabel($noteId, $labelId)
    {
        $sql = "INSERT IGNORE INTO {$this->table} (note_id, label_id)
                VALUES (:note_id, :label_id)";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':note_id' => $noteId,
            ':label_id' => $labelId
        ]);
    }

    public function detachLabel($noteId, $labelId)
    {
        $sql = "DELETE FROM {$this->table}
                WHERE note_id = :note_id AND label_id = :label_id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':note_id' => $noteId,
            ':label_id' => $labelId
        ]);
    }

    public function getLabelsByNoteId($noteId)
    {
        $sql = "SELECT l.*
                FROM {$this->table} nl
                INNER JOIN labels l ON nl.label_id = l.id
                WHERE nl.note_id = :note_id
                ORDER BY l.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':note_id' => $noteId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLabelsByNoteIds(array $noteIds)
    {
        if (empty($noteIds)) return [];
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $sql = "SELECT nl.note_id, l.*
                FROM {$this->table} nl
                INNER JOIN labels l ON nl.label_id = l.id
                WHERE nl.note_id IN ($placeholders)
                ORDER BY l.name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($noteIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNotesByLabelId($labelId)
    {
        $sql = "SELECT n.*
                FROM {$this->table} nl
                INNER JOIN notes n ON nl.note_id = n.id
                WHERE nl.label_id = :label_id
                ORDER BY n.is_pinned DESC, n.pinned_at DESC, n.updated_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':label_id' => $labelId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
