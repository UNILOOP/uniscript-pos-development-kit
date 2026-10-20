<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\MockPos;

header('Content-Type: application/json');

try {
    $database = getenv('MOCK_POS_DATABASE') ?: sys_get_temp_dir().'/uniscript-synthetic-pos.sqlite';
    $mock = new MockPos($database, getenv('MOCK_POS_USER') ?: '', getenv('MOCK_POS_PASSWORD') ?: '');
    $body = file_get_contents('php://input', false, null, 0, 1048577);
    $result = $mock->handle($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', getallheaders(), $body);
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo '{"error":"synthetic_service_failed"}';
}
