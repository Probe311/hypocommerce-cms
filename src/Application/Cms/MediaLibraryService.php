<?php

declare(strict_types=1);

namespace App\Application\Cms;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class MediaLibraryService
{
    private PDO $pdo;
    /** @var array<string,bool> */
    private array $tableExistsCache = [];

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function upload(array $file, array $meta = []): array
    {
        if (!isset($file['tmp_name'], $file['name'], $file['size'])) {
            throw new \RuntimeException('invalid_file');
        }
        $tmp = (string) $file['tmp_name'];
        $originalName = (string) $file['name'];
        $size = (int) $file['size'];
        if ($tmp === '' || !is_file($tmp)) {
            throw new \RuntimeException('invalid_tmp_file');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || $mime === '') {
            throw new \RuntimeException('invalid_mime');
        }
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('unsupported_media_type');
        }

        $extension = $this->extensionFromMime($mime);
        $folder = trim((string) ($meta['folder'] ?? 'general'));
        $safeFolder = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($folder));
        if (!is_string($safeFolder) || $safeFolder === '') {
            $safeFolder = 'general';
        }
        $targetDir = dirname(__DIR__, 3) . '/var/uploads/cms/' . $safeFolder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('failed_to_create_media_dir');
        }
        $filename = $this->buildFilename($targetDir, $extension, $originalName, $meta);
        $targetPath = $targetDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $targetPath)) {
            throw new \RuntimeException('failed_to_store_media');
        }

        $dimensions = @getimagesize($targetPath);
        $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : null;
        $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : null;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $url = '/uploads/cms/' . $safeFolder . '/' . $filename;

        $this->insertMediaRecord(
            $targetPath,
            $url,
            $filename,
            $extension,
            $mime,
            $size,
            $width,
            $height,
            $safeFolder,
            $meta,
            $now
        );

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'url' => $url,
            'filename' => $filename,
            'mimeType' => $mime,
            'sizeBytes' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Upload depuis un chemin local (CLI / scripts batch).
     *
     * Note: cette méthode ne dépend pas de `move_uploaded_file()` pour ne pas
     * nécessiter un flux HTTP multipart.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function uploadFromLocalPath(string $localPath, array $meta = []): array
    {
        if ($localPath === '' || !is_file($localPath)) {
            throw new \RuntimeException('invalid_local_path');
        }

        $originalName = basename($localPath);
        $size = (int) (is_file($localPath) ? (filesize($localPath) ?: 0) : 0);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
        if (!is_string($mime) || $mime === '') {
            throw new \RuntimeException('invalid_mime');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('unsupported_media_type');
        }

        $extension = $this->extensionFromMime($mime);
        $folder = trim((string) ($meta['folder'] ?? 'general'));
        $safeFolder = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($folder));
        if (!is_string($safeFolder) || $safeFolder === '') {
            $safeFolder = 'general';
        }

        $targetDir = dirname(__DIR__, 3) . '/var/uploads/cms/' . $safeFolder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('failed_to_create_media_dir');
        }
        $filename = $this->buildFilename($targetDir, $extension, $originalName, $meta);

        $targetPath = $targetDir . '/' . $filename;

        // Copy across FS boundaries. Then remove original (temp) if possible.
        $copied = copy($localPath, $targetPath);
        if (!$copied) {
            throw new \RuntimeException('failed_to_store_media');
        }
        @unlink($localPath);

        $dimensions = @getimagesize($targetPath);
        $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : null;
        $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : null;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $url = '/uploads/cms/' . $safeFolder . '/' . $filename;

        $this->insertMediaRecord(
            $targetPath,
            $url,
            $filename,
            $extension,
            $mime,
            $size,
            $width,
            $height,
            $safeFolder,
            $meta,
            $now
        );

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'url' => $url,
            'filename' => $filename,
            'mimeType' => $mime,
            'sizeBytes' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function buildFilename(string $targetDir, string $extension, string $originalName, array $meta): string
    {
        $desiredRaw = isset($meta['desiredFilename']) ? (string) $meta['desiredFilename'] : '';
        $desired = $this->slugify(pathinfo($desiredRaw, PATHINFO_FILENAME));
        if ($desired === '') {
            $desired = $this->slugify(pathinfo($originalName, PATHINFO_FILENAME));
        }
        if ($desired === '') {
            $desired = 'media';
        }

        $allowRandom = isset($meta['allowRandomSuffix']) ? (bool) $meta['allowRandomSuffix'] : true;
        if ($allowRandom) {
            return $desired . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        }

        $candidate = $desired . '.' . $extension;
        $counter = 2;
        while (is_file($targetDir . '/' . $candidate)) {
            $candidate = $desired . '-' . $counter . '.' . $extension;
            $counter++;
        }

        return $candidate;
    }

    public function archive(int $mediaId): bool
    {
        if ($mediaId < 1) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE cms_media_library SET status = :status, updated_at = :updated_at WHERE id = :id');
        return $stmt->execute([
            'id' => $mediaId,
            'status' => 'archived',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    private function slugify(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        if (!is_string($value)) {
            return '';
        }
        return trim($value, '-');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function insertMediaRecord(
        string $targetPath,
        string $url,
        string $filename,
        string $extension,
        string $mime,
        int $size,
        ?int $width,
        ?int $height,
        string $safeFolder,
        array $meta,
        string $now
    ): void {
        if ($this->tableExists('cms_media_library')) {
            $insert = $this->pdo->prepare(
                'INSERT INTO cms_media_library (path, url, filename, extension, mime_type, size_bytes, width, height, title, alt_text, caption, description, folder, uploaded_by_admin_id, status, created_at, updated_at)
                 VALUES (:path, :url, :filename, :extension, :mime_type, :size_bytes, :width, :height, :title, :alt_text, :caption, :description, :folder, :uploaded_by_admin_id, :status, :created_at, :updated_at)'
            );
            $insert->execute([
                'path' => $targetPath,
                'url' => $url,
                'filename' => $filename,
                'extension' => $extension,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'width' => $width ?: null,
                'height' => $height ?: null,
                'title' => $meta['title'] ?? null,
                'alt_text' => $meta['altText'] ?? null,
                'caption' => $meta['caption'] ?? null,
                'description' => $meta['description'] ?? null,
                'folder' => $safeFolder,
                'uploaded_by_admin_id' => isset($meta['adminUserId']) ? (int) $meta['adminUserId'] : null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        if ($this->tableExists('media_assets')) {
            $metadata = [
                'path' => $targetPath,
                'filename' => $filename,
                'extension' => $extension,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'width' => $width ?: null,
                'height' => $height ?: null,
                'folder' => $safeFolder,
                'title' => $meta['title'] ?? null,
                'caption' => $meta['caption'] ?? null,
                'description' => $meta['description'] ?? null,
                'admin_user_id' => isset($meta['adminUserId']) ? (int) $meta['adminUserId'] : null,
            ];

            $insert = $this->pdo->prepare(
                'INSERT INTO media_assets (url, alt_text, media_type, metadata, created_at, updated_at)
                 VALUES (:url, :alt_text, :media_type, :metadata, :created_at, :updated_at)'
            );
            $insert->execute([
                'url' => $url,
                'alt_text' => $meta['altText'] ?? null,
                'media_type' => 'image',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        throw new \RuntimeException('missing_media_table');
    }

    private function tableExists(string $tableName): bool
    {
        if (isset($this->tableExistsCache[$tableName])) {
            return $this->tableExistsCache[$tableName];
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table"
        );
        $stmt->execute(['table' => $tableName]);
        $exists = ((int) $stmt->fetchColumn()) > 0;
        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }
}
