<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Note.php';
require_once __DIR__ . '/../models/Label.php';
require_once __DIR__ . '/../models/NoteImage.php';
require_once __DIR__ . '/../models/NoteLabel.php';
require_once __DIR__ . '/../models/NoteShare.php';

try {
    $config = require __DIR__ . '/config.php';
    $db = $config['db'];

    echo "Connecting to MySQL server at {$db['host']}...\n";
    
    // Connect without DB name first to create it if needed
    $tempConn = new PDO("mysql:host={$db['host']};port={$db['port']}", $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $tempConn->exec("CREATE DATABASE IF NOT EXISTS `{$db['dbname']}`");
    echo "Database '{$db['dbname']}' checked/created.\n";

    $dbConfig = new Database();
    $conn = $dbConfig->connect();
    echo "Authenticated session established.\n";

    // Create tables in correct dependency order
    echo "Initializing schema...\n";
    (new User())->createTable();
    (new Note())->createTable();
    (new Label())->createTable();
    (new NoteImage())->createTable();
    (new NoteLabel())->createTable();
    (new NoteShare())->createTable();

    echo "--- Setup Completed Successfully ---\n";

} catch (Exception $e) {
    echo "Setup Failed: " . $e->getMessage() . "\n";
    echo "Check your configuration in backend/config/config.php\n";
    exit(1);
}
