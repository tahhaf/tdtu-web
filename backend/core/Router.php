<?php

class Router
{
    private array $routes = [];
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function get(string $path, $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, $handler): void
    {
        $this->routes['PUT'][$path] = $handler;
    }

    public function patch(string $path, $handler): void
    {
        $this->routes['PATCH'][$path] = $handler;
    }

    public function delete(string $path, $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function resolve()
    {
        $request = new Request();
        $response = new Response();

        $method = $request->getMethod();
        $uri = $request->getUri();
        
        // Normalize URI: strip trailing slash for matching
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        $handler = $this->routes[$method][$uri] ?? null;
        $params = [];

        if (!$handler) {
            foreach ($this->routes[$method] ?? [] as $path => $h) {
                // Extract param names: {id} -> id
                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $paramNames);
                $paramNames = $paramNames[1];

                // Convert {id} to ([^/]+)
                $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $path);
                $pattern = "@^" . $pattern . "$@D";

                if (preg_match($pattern, $uri, $matches)) {
                    array_shift($matches); // remove full match
                    $handler = $h;
                    
                    // Map names to values
                    foreach ($paramNames as $index => $name) {
                        $params[$name] = $matches[$index] ?? null;
                    }
                    break;
                }
            }
        }

        if (!$handler) {
            return $response->error('Not Found', 404);
        }

        $request->setParams($params);

        if (is_array($handler)) {
            $controllerClass = $handler[0];
            $methodName = $handler[1];

            $controller = new $controllerClass($this->conn);

            return $controller->$methodName($request, $response);
        }

        return call_user_func($handler, $request, $response, $this->conn);
    }
}
