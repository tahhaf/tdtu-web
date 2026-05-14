<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/NoteSocket.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\Services\NoteSocket;

// Manual .env loader for the socket server
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

$port = getenv('SOCKET_PORT') ?: 8081;
echo "Attempting to start WebSocket server on port {$port}...\n";

require_once __DIR__ . '/../core/Database.php';

$database = new \Database();
$conn = $database->connect();

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new NoteSocket($conn)
        )
    ),
    $port
);

echo "WebSocket server running on port {$port}...\n";

$server->run();
