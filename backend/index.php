<?php
require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config/config.php';

$corsOrigin = $config['cors']['allowed_origin'];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow local network origins or the configured origin
if ($requestOrigin && (
    $requestOrigin === $corsOrigin || 
    preg_match('/^http:\/\/(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+)(:\d+)?$/', $requestOrigin)
)) {
    header("Access-Control-Allow-Origin: $requestOrigin");
} else {
    header("Access-Control-Allow-Origin: $corsOrigin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($requestPath === '/' || $requestPath === '/api/health')) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'ok',
        'service' => 'notemate-api'
    ]);
    exit;
}

session_name($config['session']['name']);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $config['session']['path'],
    'domain' => $config['session']['domain'],
    'secure' => $config['session']['secure'] || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
    'httponly' => $config['session']['httponly'],
    'samesite' => $config['session']['samesite'],
]);

session_start();

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';

$database = new Database();
$conn = $database->connect();

$router = new Router($conn);

// Auto-initialize database tables if needed
try {
    require_once __DIR__ . '/models/User.php';
    require_once __DIR__ . '/models/Note.php';
    require_once __DIR__ . '/models/Label.php';
    require_once __DIR__ . '/models/NoteImage.php';
    require_once __DIR__ . '/models/NoteLabel.php';
    require_once __DIR__ . '/models/NoteShare.php';

    (new User($conn))->createTable();
    (new Note($conn))->createTable();
    (new Label($conn))->createTable();
    (new NoteImage($conn))->createTable();
    (new NoteLabel($conn))->createTable();
    (new NoteShare($conn))->createTable();
} catch (Throwable $e) {
    error_log("Database initialization error: " . $e->getMessage());
}

require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/user.php';
require_once __DIR__ . '/routes/note.php';
require_once __DIR__ . '/routes/label.php';

$router->resolve();
