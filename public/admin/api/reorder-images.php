<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

header('Content-Type: application/json');

$expected = $_ENV['ADMIN_API_TOKEN'] ?? '';
$provided = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$appSecret = (string) ($_ENV['APP_SECRET'] ?? 'dev-secret');
$expectedCsrf = hash_hmac('sha256', $expected, $appSecret);
if (!is_string($expected) || $expected === '' || $provided === '' || !hash_equals($expected, $provided) || $csrf === '' || !hash_equals($expectedCsrf, $csrf)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '[]', true);

if (!isset($input['orders']) || !is_array($input['orders'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing orders payload']);
    exit;
}

$pdo = ConnectionFactory::getConnection();
$stmt = $pdo->prepare('UPDATE product_images SET position = :pos WHERE id = :id');

foreach ($input['orders'] as $item) {
    if (!isset($item['id'], $item['position'])) {
        continue;
    }
    $stmt->execute([
        'id' => (int) $item['id'],
        'pos' => (int) $item['position'],
    ]);
}

echo json_encode(['success' => true]);

