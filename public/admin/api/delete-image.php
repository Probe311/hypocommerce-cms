<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Logging\LoggerFactory;

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

if (!isset($_POST['image_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing image_id']);
    exit;
}

$imageId = (int) $_POST['image_id'];
$pdo = ConnectionFactory::getConnection();

$stmt = $pdo->prepare('SELECT product_id, url FROM product_images WHERE id = :id');
$stmt->execute(['id' => $imageId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Image not found']);
    exit;
}

$pdo->prepare('DELETE FROM product_images WHERE id = :id')->execute(['id' => $imageId]);

$filePath = dirname(__DIR__, 3) . '/var' . str_replace('/uploads', '/uploads', $row['url']);
if (is_file($filePath)) {
    @unlink($filePath);
}

LoggerFactory::getLogger()->info('Product image deleted', [
    'image_id' => $imageId,
    'product_id' => $row['product_id'],
]);

echo json_encode(['success' => true]);

