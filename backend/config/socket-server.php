<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/NoteSocket.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\Http\HttpServerInterface;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use App\Services\NoteSocket;
use Psr\Http\Message\RequestInterface;

class HttpWsRouter implements HttpServerInterface
{
    private WsServer $wsServer;

    public function __construct(WsServer $wsServer)
    {
        $this->wsServer = $wsServer;
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null)
    {
        if ($request && strtolower($request->getHeaderLine('Upgrade')) === 'websocket') {
            return $this->wsServer->onOpen($conn, $request);
        }

        $body = "WebSocket server is running\n";
        $conn->send(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n" .
            "Content-Length: " . strlen($body) . "\r\n" .
            "Connection: close\r\n" .
            "\r\n" .
            $body
        );
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->wsServer->onMessage($from, $msg);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->wsServer->onClose($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->wsServer->onError($conn, $e);
    }
}

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
        new HttpWsRouter(
            new WsServer(
                new NoteSocket($conn)
            )
        )
    ),
    $port
);

echo "WebSocket server running on port {$port}...\n";

$server->run();
