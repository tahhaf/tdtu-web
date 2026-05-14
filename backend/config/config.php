<?php

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

if (is_file($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Dynamic Host Detection for multi-device testing (LAN)
$serverHost = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$serverIp = explode(':', $serverHost)[0];
$defaultFrontendUrl = "http://$serverIp:5173";

$envValue = function (string $name) {
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    $value = trim($value);
    $value = trim($value, "\"'");

    if ($value === '' || str_contains($value, '${{')) {
        return null;
    }

    return $value;
};

$parseDatabaseUrl = function (?string $url) {
    if (!$url) {
        return [];
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return [];
    }

    return [
        'host' => $parts['host'] ?? null,
        'port' => isset($parts['port']) ? (string)$parts['port'] : null,
        'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : null,
        'username' => isset($parts['user']) ? urldecode($parts['user']) : null,
        'password' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
    ];
};

$urlDb = $parseDatabaseUrl($envValue('MYSQL_URL'));
$publicUrlDb = $parseDatabaseUrl($envValue('MYSQL_PUBLIC_URL'));
$railwayDb = array_filter($urlDb) ?: array_filter($publicUrlDb);

return [
    'db' => [
        'host' => $envValue('MYSQLHOST') ?: $envValue('DB_HOST') ?: ($railwayDb['host'] ?? 'localhost'),
        'port' => $envValue('MYSQLPORT') ?: $envValue('DB_PORT') ?: ($railwayDb['port'] ?? '3306'),
        'dbname' => $envValue('MYSQLDATABASE') ?: $envValue('DB_NAME') ?: ($railwayDb['dbname'] ?? 'notes_app'),
        'username' => $envValue('MYSQLUSER') ?: $envValue('DB_USER') ?: ($railwayDb['username'] ?? 'root'),
        'password' => $envValue('MYSQLPASSWORD') ?: $envValue('DB_PASS') ?: ($railwayDb['password'] ?? ''),
    ],
    'app' => [
        'url' => $envValue('APP_URL') ?: "http://$serverHost",
        'frontend_url' => $envValue('FRONTEND_URL') ?: $defaultFrontendUrl,
        'debug' => filter_var($envValue('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ],
    'mail' => [
        'mode' => $envValue('MAIL_MODE') ?: 'log', // 'log' or 'api'
        'from' => $envValue('MAIL_FROM') ?: 'noreply@notemate.local',
        'from_name' => $envValue('MAIL_FROM_NAME') ?: 'NoteMate',
        'smtp_host' => $envValue('MAIL_SMTP_HOST') ?: 'smtp.gmail.com',
        'smtp_user' => $envValue('MAIL_SMTP_USER'),
        'smtp_pass' => $envValue('MAIL_SMTP_PASS'),
        'smtp_port' => $envValue('MAIL_SMTP_PORT') ?: 587,
        'smtp_timeout' => $envValue('MAIL_SMTP_TIMEOUT') ?: 5,
        'log_file' => $envValue('MAIL_LOG_FILE') ?: 'emails.log',
    ],
    'session' => [
        'name' => $envValue('SESSION_NAME') ?: 'notemate_session',
        'secure' => filter_var($envValue('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'httponly' => true,
        'samesite' => $envValue('SESSION_SAMESITE') ?: 'Lax',
        'path' => '/',
        'domain' => $envValue('SESSION_DOMAIN') ?: '',
    ],
    'cors' => [
        'allowed_origin' => $envValue('CORS_ALLOWED_ORIGIN') ?: $defaultFrontendUrl,
        'allow_credentials' => true,
    ],
];
