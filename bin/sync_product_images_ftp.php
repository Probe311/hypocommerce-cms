<?php

declare(strict_types=1);

/**
 * Pousse les fichiers images produits locaux vers le FTP (chemins distants alignés sur /uploads/products/).
 *
 * Modes:
 *   --mirror          Parcourt récursivement backend/var/uploads/products (équivalent CMS).
 *   --from-db         N’upload que les fichiers référencés en product_images (URLs locales).
 *
 * Config: variables d’environnement FTP_HOST, FTP_USER, FTP_PASSWORD (ou arguments positionnels).
 * Optionnel: FTP_PORT (défaut 21), --max-per-product=4 (mode --from-db uniquement).
 *
 * Usage:
 *   php backend/bin/sync_product_images_ftp.php [--mirror|--from-db] [--max-per-product=4]
 *   php backend/bin/sync_product_images_ftp.php <host> <user> <pass> [--mirror|--from-db]
 */

require __DIR__ . '/bootstrap.php';

loadBackendEnv();

use App\Infrastructure\Database\ConnectionFactory;

$mirror = in_array('--mirror', $argv, true);
$fromDb = in_array('--from-db', $argv, true);
if (!$mirror && !$fromDb) {
    $fromDb = true;
}

$maxPerProduct = 4;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--max-per-product=')) {
        $maxPerProduct = max(1, min(20, (int) substr((string) $arg, strlen('--max-per-product='))));
    }
}

$host = $_ENV['FTP_HOST'] ?? null;
$user = $_ENV['FTP_USER'] ?? null;
$pass = $_ENV['FTP_PASSWORD'] ?? null;
$port = (int) ($_ENV['FTP_PORT'] ?? 21);

$positional = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
if (count($positional) >= 3) {
    $host = $positional[0];
    $user = $positional[1];
    $pass = $positional[2];
}

if (!is_string($host) || $host === '' || !is_string($user) || $user === '' || !is_string($pass)) {
    fwrite(STDERR, "FTP: définir FTP_HOST, FTP_USER, FTP_PASSWORD dans .env ou passer host user pass en arguments.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$localProductsDir = dirname(__DIR__, 1) . '/var/uploads/products';

if (!is_dir($localProductsDir)) {
    fwrite(STDERR, "Répertoire local introuvable: {$localProductsDir}\n");
    exit(1);
}

$remoteRoots = [
    '/www/uploads/products',
    '/public_html/uploads/products',
    '/uploads/products',
];

/**
 * @param resource $conn
 */
function ensureFtpDir($conn, string $path): void
{
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        @ftp_mkdir($conn, $current);
    }
}

/**
 * @param resource $conn
 */
function ftpPutFile($conn, string $localFile, string $remotePath): bool
{
    $remoteDir = dirname($remotePath);
    ensureFtpDir($conn, $remoteDir);
    return @ftp_put($conn, $remotePath, $localFile, FTP_BINARY);
}

/**
 * @param resource $conn
 */
function uploadTreeFromDir($conn, string $localRoot, string $remoteRoot): int
{
    $count = 0;
    if (!is_dir($localRoot)) {
        return 0;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $relative = str_replace('\\', '/', substr($full, strlen(rtrim($localRoot, '\\/')) + 1));
        $remotePath = rtrim($remoteRoot, '/') . '/' . $relative;
        if (ftpPutFile($conn, $full, $remotePath)) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param resource $conn
 * @param list<array{path:string}> $files
 */
function uploadExplicitFiles($conn, string $remoteRoot, array $files): int
{
    $count = 0;
    foreach ($files as $row) {
        $rel = $row['path'];
        $localFull = dirname(__DIR__, 1) . '/var/uploads/products/' . $rel;
        if (!is_file($localFull)) {
            continue;
        }
        $remotePath = rtrim($remoteRoot, '/') . '/' . $rel;
        if (ftpPutFile($conn, $localFull, $remotePath)) {
            $count++;
        }
    }
    return $count;
}

$conn = @ftp_connect($host, $port, 60);
if ($conn === false) {
    fwrite(STDERR, "FTP connect failed ({$host}:{$port})\n");
    exit(1);
}

if (!@ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed\n");
    ftp_close($conn);
    exit(1);
}
ftp_pasv($conn, true);

$uploaded = 0;
$selectedRoot = null;

if ($mirror) {
    foreach ($remoteRoots as $root) {
        ensureFtpDir($conn, $root);
        $n = uploadTreeFromDir($conn, $localProductsDir, $root);
        if ($n > 0 || $selectedRoot === null) {
            $uploaded = $n;
            $selectedRoot = $root;
            if ($n > 0) {
                break;
            }
        }
    }
} else {
    $pdo = ConnectionFactory::getConnection();
    $maxPos = (int) $maxPerProduct;
    $sql = "SELECT SUBSTRING(pi.url, 19) AS rel
            FROM product_images pi
            INNER JOIN products p ON p.id = pi.product_id AND p.status = 'published'
            WHERE pi.url LIKE '/uploads/products/%'
              AND pi.position < {$maxPos}
            ORDER BY pi.product_id ASC, pi.position ASC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $files = [];
    $seen = [];
    foreach ($rows as $r) {
        $rel = trim((string) ($r['rel'] ?? ''));
        if ($rel === '' || isset($seen[$rel])) {
            continue;
        }
        $seen[$rel] = true;
        $files[] = ['path' => $rel];
    }

    foreach ($remoteRoots as $root) {
        ensureFtpDir($conn, $root);
        $n = uploadExplicitFiles($conn, $root, $files);
        if ($n > 0 || $selectedRoot === null) {
            $uploaded = $n;
            $selectedRoot = $root;
            if ($n > 0) {
                break;
            }
        }
    }
}

ftp_close($conn);

$mode = $mirror ? 'mirror' : 'from-db';
fwrite(STDOUT, "FTP upload mode={$mode} uploaded={$uploaded} remote_root=" . ($selectedRoot ?? 'none') . "\n");

if ($uploaded === 0) {
    fwrite(STDERR, "Aucun fichier uploadé (vérifiez les chemins distants, FTP ou fichiers locaux).\n");
    exit(1);
}
