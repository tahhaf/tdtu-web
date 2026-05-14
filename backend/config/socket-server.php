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

/**
 * Dispatches incoming connections to either the WebSocket handler or a
 * plain-HTTP handler depending on whether the request carries an
 * "Upgrade: websocket" header.  This lets the same port serve both
 * ws:// clients and ordinary browser / health-check HTTP requests.
 */
class HttpWsRouter implements HttpServerInterface
{
    /** @var WsServer */
    private $wsServer;

    public function __construct(WsServer $wsServer)
    {
        $this->wsServer = $wsServer;
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null)
    {
        $upgrade = $request ? strtolower($request->getHeaderLine('Upgrade')) : '';

        if ($upgrade === 'websocket') {
            // Hand off to the WebSocket stack
            $this->wsServer->onOpen($conn, $request);
        } else {
            // Return a plain HTTP 200 for browser / health-check requests
            $body    = 'WebSocket server is running';
            $headers = implode("\r\n", [
                'HTTP/1.1 200 OK',
                'Content-Type: text/plain; charset=utf-8',
                'Content-Length: ' . strlen($body),
                'Connection: close',
            ]);
            $conn->send("{$headers}\r\n\r\n{$body}");
            $conn->close();
        }
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
