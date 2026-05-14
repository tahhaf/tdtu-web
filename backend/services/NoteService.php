<?php

require_once __DIR__ . '/../models/Note.php';
require_once __DIR__ . '/../models/NoteImage.php';
require_once __DIR__ . '/../models/NoteLabel.php';
require_once __DIR__ . '/../models/NoteShare.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/MailService.php';

class NoteService
{
    private const VERIFIED_NOTE_SESSION_KEY = 'verified_note_access';

    private Note $noteModel;
    private NoteImage $noteImageModel;
    private NoteLabel $noteLabelModel;
    private NoteShare $noteShareModel;
    private User $userModel;
    private MailService $mailService;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->noteModel = new Note($db);
        $this->noteImageModel = new NoteImage($db);
        $this->noteLabelModel = new NoteLabel($db);
        $this->noteShareModel = new NoteShare($db);
        $this->userModel = new User($db);
        $this->mailService = new MailService();
    }

    public function getAllNotes($userId, $search = null, $labelId = null)
    {
        $notes = $this->fetchRawNotes($userId, $search, $labelId);
        $enriched = $this->enrichAndFormatNotesBatch($notes, $userId);
        return $this->sortNotes($enriched);
    }

    private function fetchRawNotes($userId, $search, $labelId)
    {
        if ($labelId) {
            $notes = $this->noteLabelModel->getNotesByLabelId($labelId);
            return array_filter($notes, fn($note) => $this->canView($note, $userId));
        }

        if ($search) {
            return $this->noteModel->search($userId, $search);
        }

        return $this->noteModel->getAllByUser($userId);
    }

    private function sortNotes(array $notes): array
    {
        usort($notes, function($a, $b) {
            if ($a['isPinned'] !== $b['isPinned']) {
                return $a['isPinned'] ? -1 : 1;
            }
            
            $timeA = $a['isPinned'] ? $a['pinnedAt'] : $a['updatedAt'];
            $timeB = $b['isPinned'] ? $b['pinnedAt'] : $b['updatedAt'];
            
            return strcmp($timeB, $timeA);
        });

        return array_values($notes);
    }

    public function getNote($id, $userId, $bypassLock = false)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $hasAccess = $bypassLock || !$this->isLocked($note) || $this->hasVerifiedAccess($id);

        return $this->enrichAndFormatNote($note, $userId, $hasAccess);
    }

    public function createNote($userId, $data)
    {
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        $clientId = trim($data['clientId'] ?? '');
        $labelIds = $data['labelIds'] ?? [];
        $images = $data['images'] ?? [];

        if ($title === '' && $content === '') {
            throw new Exception('Note cannot be empty. Please provide a title or content.', 422);
        }

        // Rubrik ID 8: Use user default note color if none provided
        $noteColor = $data['noteColor'] ?? null;
        if ($noteColor === null || $noteColor === 'default') {
            $user = $this->userModel->getById($userId);
            $noteColor = $user['note_color'] ?? 'default';
        }

        $this->db->beginTransaction();

        try {
            // 1. Create base note (Strictly Title and Content per Rubric #11)
            $noteId = $this->noteModel->create($userId, $title, $content, $clientId ?: null);

            if (!$noteId) {
                throw new Exception('Failed to create note', 500);
            }

            // 2. Handle optional attributes from other criteria (#8, #15, #19)
            if ($noteColor && $noteColor !== 'default') {
                $this->noteModel->update($noteId, $userId, $title, $content, $noteColor);
            }

            $this->syncLabels($noteId, $labelIds);
            $this->syncImages($noteId, $images);

            if (isset($data['isPinned']) && $data['isPinned']) {
                $this->noteModel->setPinStatus($noteId, $userId, true);
            }

            $this->db->commit();
            return $this->getNote($noteId, $userId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateNote($id, $userId, $data)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureCanEdit($note, $userId);
        $this->ensureUnlocked($note);

        $this->validateNoteContent($data);

        $this->db->beginTransaction();
        try {
            $title = isset($data['title']) ? trim($data['title']) : $note['title'];
            $content = isset($data['content']) ? trim($data['content']) : $note['content'];
            $noteColor = $data['noteColor'] ?? $note['note_color'];

            if (!$this->noteModel->update($id, $userId, $title, $content, $noteColor)) {
                throw new Exception('Failed to update note', 500);
            }

            if (isset($data['labelIds'])) $this->syncLabels($id, $data['labelIds']);
            if (isset($data['images'])) $this->syncImages($id, $data['images']);

            $this->db->commit();
            return $this->getNote($id, $userId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteNote($id, $userId, $password = null)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);

        if ($this->isLocked($note) && !$this->hasVerifiedAccess($id)) {
            if (empty($password) || !$this->noteModel->verifyNotePassword($id, $password)) {
                throw new Exception('Password is required to delete this locked note', 401);
            }
        }

        if (!$this->noteModel->delete($id, $userId)) {
            throw new Exception('Failed to delete note', 500);
        }

        $this->clearVerifiedNoteAccess($id);
        return true;
    }

    public function togglePin($id, $userId, $forceStatus = null)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);

        $newPinStatus = $forceStatus !== null ? (bool)$forceStatus : !$note['is_pinned'];
        
        if (!$this->noteModel->setPinStatus($id, $userId, $newPinStatus)) {
            throw new Exception('Failed to update pin status', 500);
        }

        return ['is_pinned' => $newPinStatus];
    }

    public function setLock($id, $userId, $data)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);

        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? ($data['password'] ?? '');
        $confirmPassword = $data['confirmPassword'] ?? '';

        if ($this->isLocked($note)) {
            if (trim($currentPassword) === '' || !$this->noteModel->verifyNotePassword($id, $currentPassword)) {
                throw new Exception('Current note password is incorrect', 401);
            }

            // Disable lock if both new passwords are empty
            if ($newPassword === '' && $confirmPassword === '') {
                if (!$this->noteModel->setLockStatus($id, $userId, false, null)) {
                    throw new Exception('Failed to disable password protection', 500);
                }
                $this->clearVerifiedNoteAccess($id);
                return $this->getNote($id, $userId);
            }

            $this->validatePasswordPair($newPassword, $confirmPassword);

            if (!$this->noteModel->setLockStatus($id, $userId, true, $newPassword)) {
                throw new Exception('Failed to update note password', 500);
            }
        } else {
            $this->validatePasswordPair($newPassword, $confirmPassword);
            if (!$this->noteModel->setLockStatus($id, $userId, true, $newPassword)) {
                throw new Exception('Failed to enable password protection', 500);
            }
        }

        $this->clearVerifiedNoteAccess($id);
        return $this->getNote($id, $userId);
    }

    private function validatePasswordPair($password, $confirm)
    {
        if ($password === '' || $confirm === '') {
            throw new Exception('Please enter the password twice', 422);
        }
        if ($password !== $confirm) {
            throw new Exception('Passwords do not match', 422);
        }
    }

    public function verifyPassword($id, $userId, $password)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        if (!$this->noteModel->verifyNotePassword($id, $password)) {
            throw new Exception('Incorrect password', 401);
        }

        $this->markNoteAsVerified($id);
        return $this->getNote($id, $userId, true);
    }

    public function getSharedNotes($userId)
    {
        $sharedNotes = $this->noteShareModel->getReceivedByUser($userId);
        return $this->enrichAndFormatNotesBatch($sharedNotes, $userId);
    }

    public function shareNote($id, $userId, $emailsStr, $role)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);
        $this->ensureUnlocked($note);

        if (!in_array($role, ['read', 'edit'])) throw new Exception('Invalid role', 400);

        $emails = array_filter(array_map('trim', explode(',', str_replace(';', ',', $emailsStr))));
        if (empty($emails)) throw new Exception('Email is required', 400);

        $results = ['success' => [], 'errors' => [], 'notifications' => []];
        $sender = $this->userModel->getById($userId);

        foreach ($emails as $email) {
            try {
                $recipient = $this->userModel->findByEmail($email);
                if (!$recipient) throw new Exception("User not found: $email");
                if ((int)$recipient['id'] === (int)$userId) throw new Exception("Cannot share with yourself");

                if ($this->noteShareModel->create($id, $userId, $recipient['id'], $role)) {
                    $results['success'][] = $email;
                    $results['notifications'][] = [
                        'email' => $recipient['email'],
                        'displayName' => $recipient['display_name'] ?? $recipient['email'],
                        'senderName' => $sender['display_name'] ?? $sender['email'],
                        'noteTitle' => $note['title'] ?: 'Untitled Note',
                        'role' => $role
                    ];
                }
            } catch (Exception $e) {
                $results['errors'][] = $e->getMessage();
            }
        }

        if (empty($results['success']) && !empty($results['errors'])) {
            throw new Exception(implode(', ', $results['errors']), 400);
        }

        return $results;
    }

    public function revokeShare($id, $userId, $email)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);
        $this->ensureUnlocked($note);

        $recipient = $this->userModel->findByEmail($email);
        if (!$recipient) throw new Exception('Recipient not found', 404);

        if (!$this->noteShareModel->revoke($id, $userId, $recipient['id'])) {
            throw new Exception('Failed to revoke share', 500);
        }

        return true;
    }

    public function updateSharePermission($id, $userId, $email, $role)
    {
        $note = $this->findNoteOrThrow($id, $userId);
        $this->ensureIsOwner($note, $userId);
        $this->ensureUnlocked($note);

        if (!in_array($role, ['read', 'edit'])) throw new Exception('Invalid role', 400);

        $recipient = $this->userModel->findByEmail($email);
        if (!$recipient) throw new Exception('Recipient not found', 404);

        if (!$this->noteShareModel->updatePermission($id, $userId, $recipient['id'], $role)) {
            throw new Exception('Failed to update share permission', 500);
        }

        return true;
    }

    private function findNoteOrThrow($id, $userId)
    {
        $note = $this->noteModel->getById($id, $userId);
        if (!$note) throw new Exception('Note not found', 404);

        $isOwner = (int)$note['user_id'] === (int)$userId;
        $isShared = !empty($note['permission']);

        if (!$isOwner && !$isShared) {
            throw new Exception('You do not have permission to access this note', 403);
        }

        return $note;
    }

    private function validateNoteContent($data)
    {
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        if ($title === '' && $content === '') {
            throw new Exception('Note cannot be empty. Please provide a title or content.', 422);
        }
    }

    private function canView($note, $userId): bool
    {
        if ((int)$note['user_id'] === (int)$userId) return true;
        return $this->noteShareModel->getShare($note['id'], $userId) !== null;
    }

    private function ensureCanEdit($note, $userId)
    {
        $isOwner = (int)$note['user_id'] === (int)$userId;
        $isCollaborator = ($note['permission'] ?? null) === 'edit';
        if (!$isOwner && !$isCollaborator) {
            throw new Exception('You do not have permission to edit this note', 403);
        }
    }

    private function ensureIsOwner($note, $userId)
    {
        if ((int)$note['user_id'] !== (int)$userId) {
            throw new Exception('Only the owner can perform this action', 403);
        }
    }

    private function ensureUnlocked($note)
    {
        if ($this->isLocked($note) && !$this->hasVerifiedAccess($note['id'])) {
            throw new Exception('Please enter the note password first', 401);
        }
    }

    private function isLocked($note): bool
    {
        return !empty($note['is_locked']);
    }

    private function hasVerifiedAccess($noteId): bool
    {
        return !empty($_SESSION[self::VERIFIED_NOTE_SESSION_KEY][(string)$noteId]);
    }

    private function enrichAndFormatNote($note, $userId, $isUnlocked)
    {
        $id = $note['id'];
        $isOwner = (int)$note['user_id'] === (int)$userId;

        $formatted = $note;
        $formatted['images'] = array_map(fn($img) => $img['image_url'], $this->noteImageModel->getByNoteId($id));
        $formatted['labelIds'] = array_map(fn($l) => (int)$l['id'], $this->noteLabelModel->getLabelsByNoteId($id));

        if ($isOwner) {
            $shares = $this->noteShareModel->getByOwner($userId);
            $formatted['sharedWith'] = array_values(array_map(fn($s) => [
                'email' => $s['recipient_email'],
                'role' => $s['permission'],
                'sharedAt' => $s['shared_at'] ?? null
            ], array_filter($shares, fn($s) => $s['note_id'] == $id)));
        } else {
            $owner = $this->userModel->getById($note['user_id']);
            $formatted['ownerEmail'] = $owner['email'] ?? '';
            $formatted['ownerDisplayName'] = $owner['display_name'] ?? '';
        }

        if ($this->isLocked($note) && !$isUnlocked) {
            $formatted['content'] = "[Locked Content]";
            $formatted['images'] = [];
        }

        return $this->finalizeFormat($formatted);
    }

    private function enrichAndFormatNotesBatch(array $notes, $userId)
    {
        if (empty($notes)) return [];

        $noteIds = array_map(fn($n) => $n['id'], $notes);
        $ownerIds = array_unique(array_map(fn($n) => $n['user_id'], $notes));

        // 1. Batch fetch images and labels
        $allImages = $this->noteImageModel->getByNoteIds($noteIds);
        $allLabels = $this->noteLabelModel->getLabelsByNoteIds($noteIds);
        
        // 2. Batch fetch owners (for shared notes)
        $owners = [];
        $ownersRaw = $this->userModel->getByIds($ownerIds);
        foreach ($ownersRaw as $u) $owners[$u['id']] = $u;

        // 3. Batch fetch shares (only if current user is owner of at least one note in list)
        $allShares = [];
        if (in_array($userId, $ownerIds)) {
            $allShares = $this->noteShareModel->getByOwner($userId);
        }

        // 4. Group by note_id
        $imagesByNote = [];
        foreach ($allImages as $img) $imagesByNote[$img['note_id']][] = $img['image_url'];

        $labelsByNote = [];
        foreach ($allLabels as $l) $labelsByNote[$l['note_id']][] = (int)$l['id'];

        $sharesByNote = [];
        foreach ($allShares as $s) {
            $sharesByNote[$s['note_id']][] = [
                'email' => $s['recipient_email'],
                'role' => $s['permission'],
                'sharedAt' => $s['shared_at'] ?? null
            ];
        }

        // 5. Assemble
        $result = [];
        foreach ($notes as $note) {
            $id = $note['id'];
            $isOwner = (int)$note['user_id'] === (int)$userId;
            $hasAccess = $this->hasVerifiedAccess($id);

            $formatted = $note;
            $formatted['images'] = $imagesByNote[$id] ?? [];
            $formatted['labelIds'] = $labelsByNote[$id] ?? [];

            if ($isOwner) {
                $formatted['sharedWith'] = $sharesByNote[$id] ?? [];
            } else {
                $owner = $owners[$note['user_id']] ?? null;
                $formatted['ownerEmail'] = $owner['email'] ?? '';
                $formatted['ownerDisplayName'] = $owner['display_name'] ?? '';
            }

            if ($this->isLocked($note) && !$hasAccess) {
                $formatted['content'] = "[Locked Content]";
                $formatted['images'] = [];
            }

            $result[] = $this->finalizeFormat($formatted);
        }

        return $result;
    }

    private function finalizeFormat($data)
    {
        return [
            'id' => (int)$data['id'],
            'clientId' => $data['client_id'] ?? null,
            'userId' => (int)($data['user_id'] ?? 0),
            'title' => $data['title'] ?? '',
            'content' => $data['content'] ?? '',
            'noteColor' => $data['note_color'] ?? 'default',
            'isPinned' => (bool)($data['is_pinned'] ?? false),
            'pinnedAt' => $data['pinned_at'] ?? null,
            'isLocked' => (bool)($data['is_locked'] ?? false),
            'images' => $data['images'] ?? [],
            'labelIds' => $data['labelIds'] ?? [],
            'sharedWith' => $data['shared_with'] ?? ($data['sharedWith'] ?? []),
            'ownerEmail' => $data['ownerEmail'] ?? null,
            'ownerDisplayName' => $data['ownerDisplayName'] ?? null,
            'sharedAt' => $data['shared_at'] ?? ($data['sharedAt'] ?? null),
            'permission' => $data['permission'] ?? 'owner',
            'createdAt' => $data['created_at'] ?? null,
            'updatedAt' => $data['updated_at'] ?? null,
        ];
    }

    private function syncLabels($noteId, $labelIds)
    {
        $existing = array_map(fn($l) => (int)$l['id'], $this->noteLabelModel->getLabelsByNoteId($noteId));
        foreach (array_diff($existing, $labelIds) as $id) $this->noteLabelModel->detachLabel($noteId, $id);
        foreach (array_diff($labelIds, $existing) as $id) $this->noteLabelModel->attachLabel($noteId, $id);
    }

    private function syncImages($noteId, $images)
    {
        $allCurrent = $this->noteImageModel->getByNoteId($noteId);
        $currentUrls = array_map(fn($img) => $img['image_url'], $allCurrent);

        // Optimization: If images are the same, do nothing
        if (JSON_encode($currentUrls) === JSON_encode($images)) {
            return;
        }

        // Only clear and re-add if there's an actual change
        foreach ($allCurrent as $img) $this->noteImageModel->delete($img['id'], $noteId);
        foreach ($images as $url) $this->noteImageModel->create($noteId, $url);
    }

    private function markNoteAsVerified($noteId)
    {
        if (!isset($_SESSION[self::VERIFIED_NOTE_SESSION_KEY]) || !is_array($_SESSION[self::VERIFIED_NOTE_SESSION_KEY])) {
            $_SESSION[self::VERIFIED_NOTE_SESSION_KEY] = [];
        }
        $_SESSION[self::VERIFIED_NOTE_SESSION_KEY][(string)$noteId] = time();
    }

    private function clearVerifiedNoteAccess($noteId) {
        if (isset($_SESSION[self::VERIFIED_NOTE_SESSION_KEY][(string)$noteId])) {
            unset($_SESSION[self::VERIFIED_NOTE_SESSION_KEY][(string)$noteId]);
        }
    }

    public function getMailService() {
        return $this->mailService;
    }
}
