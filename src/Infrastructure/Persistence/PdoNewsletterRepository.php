<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoNewsletterRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function subscribe(string $email): void
    {
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare('SELECT id FROM newsletter_subscribers WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO newsletter_subscribers (email, subscribed_at, unsubscribed_at)
                 VALUES (:email, :subscribed_at, NULL)'
            );
            $insert->execute([
                'email' => $email,
                'subscribed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE newsletter_subscribers
             SET unsubscribed_at = NULL, subscribed_at = :subscribed_at
             WHERE id = :id'
        );
        $update->execute([
            'id' => (int) $existingId,
            'subscribed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function unsubscribe(string $email): bool
    {
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare(
            'UPDATE newsletter_subscribers
             SET unsubscribed_at = :unsubscribed_at
             WHERE email = :email AND unsubscribed_at IS NULL'
        );
        $stmt->execute([
            'email' => $email,
            'unsubscribed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() > 0;
    }
}
