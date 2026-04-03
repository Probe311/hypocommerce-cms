<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;

try {
    pdoFromArgv($argv);
    $pdo = ConnectionFactory::getConnection();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $pageStmt = $pdo->prepare(
        "UPDATE cms_pages
         SET status = 'published', published_at = :now, updated_at = :now
         WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now"
    );
    $pageStmt->execute(['now' => $now]);
    $pagesPublished = $pageStmt->rowCount();

    $articleStmt = $pdo->prepare(
        "UPDATE blog_articles
         SET status = 'published', published_at = :now, updated_at = :now
         WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= :now"
    );
    $articleStmt->execute(['now' => $now]);
    $articlesPublished = $articleStmt->rowCount();

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'publishedPages' => $pagesPublished,
        'publishedArticles' => $articlesPublished,
        'executedAt' => $now,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
