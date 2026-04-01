<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoPageRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, title, content, meta_title, meta_description, published_at
             FROM pages
             WHERE slug = :slug AND published_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }
}
