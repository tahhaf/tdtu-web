<?php

class Request
{
    private string $method;
    private string $uri;
    private array $body;
    private array $query;
    private array $params = [];

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->body = $this->parseBody();
        $this->query = $_GET ?? [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function getBody(): array
    {
        return $this->body();
    }

    public function query(): array
    {
        return $this->query;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function getParam(string $key)
    {
        return $this->params[$key] ?? null;
    }

    private function parseBody(): array
    {
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
