<?php

namespace App\Services;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class NoteSocket implements MessageComponentInterface
{
    protected $db;

    public function __construct(?\PDO $db = null)
    {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
        $this->db = $db;
        echo "NoteSocket Server Started!\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['action'])) {
            return;
        }

        switch ($data['action']) {
            case 'join':
                $noteId = $data['noteId'] ?? null;
                $userId = $data['userId'] ?? null;
                if ($noteId && $userId) {
                    if ($this->checkPermission($userId, $noteId)) {
                        $this->joinRoom($from, $noteId);
                    } else {
                        $from->send(json_encode(['error' => 'Permission denied']));
                    }
                }
                break;

            case 'note-updated':
            case 'note-deleted':
            case 'note-pinned':
                $noteId = $data['noteId'] ?? null;
                if ($noteId) {
                    $this->broadcastToRoom($from, $noteId, $msg);
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        $this->leaveAllRooms($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function joinRoom(ConnectionInterface $conn, $noteId)
    {
        $this->leaveAllRooms($conn);

        if (!isset($this->rooms[$noteId])) {
            $this->rooms[$noteId] = [];
        }

        $this->rooms[$noteId][$conn->resourceId] = $conn;
        echo "Connection {$conn->resourceId} joined room: note_{$noteId}\n";
    }

    protected function leaveAllRooms(ConnectionInterface $conn)
    {
        foreach ($this->rooms as $noteId => &$clients) {
            if (isset($clients[$conn->resourceId])) {
                unset($clients[$conn->resourceId]);
                echo "Connection {$conn->resourceId} left room: note_{$noteId}\n";
            }
        }
    }

    protected function broadcastToRoom(ConnectionInterface $from, $noteId, $msg)
    {
        if (!isset($this->rooms[$noteId])) {
            return;
        }

        foreach ($this->rooms[$noteId] as $client) {
            if ($from !== $client) {
                $client->send($msg);
            }
        }
    }

    protected function checkPermission($userId, $noteId)
    {
        if (!$this->db) return true;

        $sql = "SELECT id FROM notes WHERE id = :noteId AND user_id = :userId
                UNION
                SELECT note_id FROM note_shares WHERE note_id = :noteId AND shared_with_user_id = :userId AND revoked_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':noteId' => $noteId, ':userId' => $userId]);
        
        return $stmt->fetch() !== false;
    }
}
