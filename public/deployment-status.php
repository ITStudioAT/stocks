<?php

declare(strict_types=1);

use function Stocks\DeploymentStatus\readStatus;

require_once __DIR__.'/../scripts/deployment-status.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (! in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if ($method === 'GET') {
    echo json_encode(['state' => readStatus(__DIR__.'/../storage/framework')], JSON_THROW_ON_ERROR);
}
