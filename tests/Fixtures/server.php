<?php

// Router script for `php -S`: the endpoints Http/CurlTransportTest drives.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/slow':
        sleep(3);
        echo '{"status":"success","data":[]}';
        break;
    case '/redirect':
        header('Location: https://elsewhere.example/x?token=secret', true, 302);
        break;
    case '/big':
        header('Content-Type: application/json');
        echo str_repeat('a', 2 * 1024 * 1024);
        break;
    case '/unavailable':
        http_response_code(503);
        header('Content-Type: application/problem+json');
        echo '{"status":503,"code":"service_unavailable"}';
        break;
    case '/gzip':
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode('{"data":{"hello":"world"}}');
        break;
    case '/echo':
        header('Content-Type: application/json');
        echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'body' => file_get_contents('php://input')]);
        break;
    default:
        header('Content-Type: application/json');
        echo '{"data":{}}';
}
