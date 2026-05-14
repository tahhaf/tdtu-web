<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';

class User
{
    private PDO $conn;
    private string $table = 'users';

    public function __construct(?PDO $conn = null)
    {
        if ($conn instanceof PDO) {
            $this->conn = $conn;
            return;
        }

        $database = new Database();
        $this->conn = $database->connect();
    }

    /**
     * Get the database connection for transactions
     */
    public function getDb(): PDO
    {
        return $this->conn;
    }

    public function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            display_name VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_activated BOOLEAN DEFAULT FALSE,
            activation_token VARCHAR(255),
            activation_expires DATETIME,
            reset_token VARCHAR(255),
            reset_otp VARCHAR(10),
            reset_expires DATETIME,
            avatar_url LONGTEXT,
            theme ENUM('light', 'dark') DEFAULT 'light',
            font_size INT DEFAULT 14,
            note_color VARCHAR(50) DEFAULT 'default',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";

        $result = $this->conn->exec($sql);
        $this->ensureSchema();

        return $result;
    }

    private function ensureSchema(): void
    {
        Schema::ensureColumns($this->conn, $this->table, [
            'is_activated' => "ALTER TABLE {$this->table} ADD is_activated BOOLEAN DEFAULT FALSE AFTER password_hash",
            'activation_token' => "ALTER TABLE {$this->table} ADD activation_token VARCHAR(255) DEFAULT NULL AFTER is_activated",
            'activation_expires' => "ALTER TABLE {$this->table} ADD activation_expires DATETIME DEFAULT NULL AFTER activation_token",
            'reset_token' => "ALTER TABLE {$this->table} ADD reset_token VARCHAR(255) DEFAULT NULL AFTER activation_expires",
            'reset_otp' => "ALTER TABLE {$this->table} ADD reset_otp VARCHAR(10) DEFAULT NULL AFTER reset_token",
            'reset_expires' => "ALTER TABLE {$this->table} ADD reset_expires DATETIME DEFAULT NULL AFTER reset_otp",
            'avatar_url' => "ALTER TABLE {$this->table} MODIFY COLUMN avatar_url LONGTEXT DEFAULT NULL",
            'theme' => "ALTER TABLE {$this->table} ADD theme ENUM('light', 'dark') DEFAULT 'light' AFTER avatar_url",
            'font_size' => "ALTER TABLE {$this->table} ADD font_size INT DEFAULT 14 AFTER theme",
            'note_color' => "ALTER TABLE {$this->table} ADD note_color VARCHAR(50) DEFAULT 'default' AFTER font_size",
            'created_at' => "ALTER TABLE {$this->table} ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note_color",
            'updated_at' => "ALTER TABLE {$this->table} ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ]);
        Schema::ensureColumnType($this->conn, $this->table, 'avatar_url', "ALTER TABLE {$this->table} MODIFY COLUMN avatar_url LONGTEXT DEFAULT NULL");
    }

    public function create($email, $displayName, $password)
    {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $sql = "INSERT INTO {$this->table} (email, display_name, password_hash)
                VALUES (:email, :display_name, :password_hash)";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':email' => $email,
            ':display_name' => $displayName,
            ':password_hash' => $passwordHash
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function findByEmail($email)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE email = :email
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':email' => $email
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id' => $id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByIds(array $ids)
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$this->table} WHERE id IN ($placeholders)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveActivationToken($id, $token, $expires)
    {
        $sql = "UPDATE {$this->table}
                SET activation_token = :activation_token,
                    activation_expires = :activation_expires
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':activation_token' => $token,
            ':activation_expires' => $expires
        ]);
    }

    public function activateAccount($token)
    {
        $currentTime = date('Y-m-d H:i:s');

        $sql = "UPDATE {$this->table}
                SET is_activated = 1,
                    activation_token = NULL,
                    activation_expires = NULL
                WHERE activation_token = :token
                  AND activation_expires IS NOT NULL
                  AND activation_expires >= :current_time";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':token' => $token,
            ':current_time' => $currentTime
        ]);

        return $stmt->rowCount() > 0;
    }

    public function saveResetToken($email, $token, $otp, $expires)
    {
        $sql = "UPDATE {$this->table}
                SET reset_token = :reset_token,
                    reset_otp = :reset_otp,
                    reset_expires = :reset_expires
                WHERE email = :email";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':email' => $email,
            ':reset_token' => $token,
            ':reset_otp' => $otp,
            ':reset_expires' => $expires
        ]);
    }

    public function findByResetToken($token)
    {
        $currentTime = date('Y-m-d H:i:s');

        $sql = "SELECT *
                FROM {$this->table}
                WHERE reset_token = :reset_token
                  AND reset_expires IS NOT NULL
                  AND reset_expires >= :current_time
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':reset_token' => $token,
            ':current_time' => $currentTime
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByResetOtp($email, $otp)
    {
        $currentTime = date('Y-m-d H:i:s');

        $sql = "SELECT *
                FROM {$this->table}
                WHERE email = :email
                  AND reset_otp = :reset_otp
                  AND reset_expires IS NOT NULL
                  AND reset_expires >= :current_time
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':reset_otp' => $otp,
            ':current_time' => $currentTime
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function resetPassword($id, $newPassword)
    {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $sql = "UPDATE {$this->table}
                SET password_hash = :password_hash,
                    reset_token = NULL,
                    reset_otp = NULL,
                    reset_expires = NULL
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':password_hash' => $passwordHash
        ]);
    }

    public function updateProfile($id, $displayName, $avatarUrl = null)
    {
        $sql = "UPDATE {$this->table}
                SET display_name = :display_name,
                    avatar_url = :avatar_url
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':display_name' => $displayName,
            ':avatar_url' => $avatarUrl
        ]);
    }

    public function changePassword($id, $newPassword)
    {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $sql = "UPDATE {$this->table}
                SET password_hash = :password_hash
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':password_hash' => $passwordHash
        ]);
    }

    public function updatePreferences($id, $theme, $fontSize, $noteColor)
    {
        $sql = "UPDATE {$this->table}
                SET theme = :theme,
                    font_size = :font_size,
                    note_color = :note_color
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $id,
            ':theme' => $theme,
            ':font_size' => $fontSize,
            ':note_color' => $noteColor
        ]);
    }
}
