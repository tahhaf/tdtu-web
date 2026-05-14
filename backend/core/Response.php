<?php

class Response
{
    public function json($data, int $status = 200)
    {
        // Clear buffer to ensure only JSON is sent
        while (ob_get_level()) ob_end_clean();

        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
        header('Access-Control-Allow-Credentials: true');

        echo json_encode($data);
        return null;
    }

    public function success($data = [], int $status = 200)
    {
        return $this->json($data, $status);
    }

    public function error(string $message = 'Error', int $status = 400, $errors = null)
    {
        $payload = ['message' => $message];
        if ($errors !== null) $payload['errors'] = $errors;
        return $this->json($payload, $status);
    }

    /**
     * Send response and continue execution
     */
    public function sendAndContinue($data, int $status = 200)
    {
        // Clear buffer
        while (ob_get_level()) ob_end_clean();

        $output = json_encode($data);

        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
        header('Access-Control-Allow-Credentials: true');
        header('Content-Length: ' . strlen($output));
        header('Connection: close');

        echo $output;

        // FastCGI optimization
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ignore_user_abort(true);
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }
}
