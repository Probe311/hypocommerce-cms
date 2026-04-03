<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/sync_local_cms_uploads_ftp.php <ftp_host> <ftp_user> <ftp_pass> <local_dir>\n");
    exit(1);
}

$host = $argv[1];
$user = $argv[2];
$pass = $argv[3];
$localDir = $argv[4];

if (!is_dir($localDir)) {
    fwrite(STDERR, "Local dir not found: {$localDir}\n");
    exit(1);
}

$conn = @ftp_connect($host, 21, 30);
if ($conn === false) {
    fwrite(STDERR, "FTP connect failed\n");
    exit(1);
}

if (!@ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed\n");
    ftp_close($conn);
    exit(1);
}
ftp_pasv($conn, true);

$roots = ['/www/uploads/cms', '/public_html/uploads/cms', '/uploads/cms'];
$selectedRoot = null;
$uploaded = 0;

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
function uploadTree($conn, string $localRoot, string $remoteRoot): int
{
    $count = 0;
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
        $remoteDir = dirname($remotePath);
        ensureFtpDir($conn, $remoteDir);
        $ok = @ftp_put($conn, $remotePath, $full, FTP_BINARY);
        if ($ok) {
            $count++;
        }
    }

    return $count;
}

foreach ($roots as $root) {
    try {
        ensureFtpDir($conn, $root);
        $uploaded = uploadTree($conn, $localDir, $root);
        $selectedRoot = $root;
        break;
    } catch (Throwable) {
        continue;
    }
}

ftp_close($conn);

if ($selectedRoot === null) {
    fwrite(STDERR, "FTP upload failed for all roots\n");
    exit(1);
}

fwrite(STDOUT, "Uploaded {$uploaded} files to {$selectedRoot}\n");

