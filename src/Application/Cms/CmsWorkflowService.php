<?php

declare(strict_types=1);

namespace App\Application\Cms;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class CmsWorkflowService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function transition(string $entityType, string $entityId, string $fromStatus, string $toStatus, ?int $adminUserId = null, ?string $note = null): void
    {
        if (!WorkflowTransitionGuard::isAllowed($fromStatus, $toStatus)) {
            throw new \RuntimeException('invalid_workflow_transition');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_workflow_events (entity_type, entity_id, from_status, to_status, note, changed_by_admin_id, changed_at)
             VALUES (:entity_type, :entity_id, :from_status, :to_status, :note, :changed_by_admin_id, :changed_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'changed_by_admin_id' => $adminUserId,
            'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

}
