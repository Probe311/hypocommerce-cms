<?php

declare(strict_types=1);

namespace App\Application\Cms;

final class WorkflowTransitionGuard
{
    public static function isAllowed(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        $allowed = [
            'draft' => ['in_review', 'scheduled', 'published', 'archived'],
            'in_review' => ['draft', 'scheduled', 'published', 'archived'],
            'scheduled' => ['draft', 'published', 'archived'],
            'published' => ['draft', 'archived'],
            'archived' => ['draft'],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }
}
