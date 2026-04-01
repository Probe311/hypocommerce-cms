<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

header('Content-Type: application/json');

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

