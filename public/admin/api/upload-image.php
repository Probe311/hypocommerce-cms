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

if (!isset($_POST['product_id'], $_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product_id or image']);
    exit;
}

$productId = (string) $_POST['product_id'];
$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error']);
    exit;
}

$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowedMime, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image type']);
    exit;
}

$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'bin',
};

$uploadDir = dirname(__DIR__, 3) . '/var/uploads/products';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$filename = $productId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$targetPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to move uploaded file']);
    exit;
}

$publicUrl = '/uploads/products/' . $filename;

$pdo = ConnectionFactory::getConnection();

$stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM product_images WHERE product_id = :pid');
$stmt->execute(['pid' => $productId]);
$nextPos = (int) ($stmt->fetch()['next_pos'] ?? 1);

$insert = $pdo->prepare('INSERT INTO product_images (product_id, url, alt, position) VALUES (:pid, :url, :alt, :pos)');
$insert->execute([
    'pid' => $productId,
    'url' => $publicUrl,
    'alt' => $_POST['alt'] ?? null,
    'pos' => $nextPos,
]);

LoggerFactory::getLogger()->info('Product image uploaded', [
    'product_id' => $productId,
    'file' => $publicUrl,
]);

echo json_encode([
    'success' => true,
    'url' => $publicUrl,
    'position' => $nextPos,
]);

