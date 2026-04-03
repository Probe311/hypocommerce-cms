<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoEeatRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function startRun(string $runType, array $scope): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO eeat_analysis_runs (run_type, scope_json, status, started_at, created_at, updated_at)
             VALUES (:run_type, :scope_json, :status, :started_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'run_type' => $runType,
            'scope_json' => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completeRun(int $runId, string $status, array $stats): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE eeat_analysis_runs
             SET status = :status, stats_json = :stats_json, ended_at = :ended_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $runId,
            'status' => $status,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'ended_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $score
     * @param array<int,array<string,string>> $recommendations
     */
    public function upsertScore(int $runId, array $score, array $recommendations): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO eeat_scores
                (run_id, entity_type, entity_id, entity_slug, locale, score_global, score_experience, score_expertise, score_authoritativeness, score_trust, grade, blockers_count, signals_json, version, computed_at, created_at, updated_at)
                VALUES
                (:run_id, :entity_type, :entity_id, :entity_slug, :locale, :score_global, :score_experience, :score_expertise, :score_authoritativeness, :score_trust, :grade, :blockers_count, :signals_json, :version, :computed_at, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    run_id = VALUES(run_id),
                    entity_slug = VALUES(entity_slug),
                    score_global = VALUES(score_global),
                    score_experience = VALUES(score_experience),
                    score_expertise = VALUES(score_expertise),
                    score_authoritativeness = VALUES(score_authoritativeness),
                    score_trust = VALUES(score_trust),
                    grade = VALUES(grade),
                    blockers_count = VALUES(blockers_count),
                    signals_json = VALUES(signals_json),
                    version = VALUES(version),
                    computed_at = VALUES(computed_at),
                    updated_at = VALUES(updated_at)'
            );
            $upsert->execute([
                'run_id' => $runId,
                'entity_type' => (string) $score['entityType'],
                'entity_id' => (string) $score['entityId'],
                'entity_slug' => $score['entitySlug'] ?? null,
                'locale' => (string) ($score['locale'] ?? 'fr'),
                'score_global' => (float) $score['scoreGlobal'],
                'score_experience' => (float) $score['scoreExperience'],
                'score_expertise' => (float) $score['scoreExpertise'],
                'score_authoritativeness' => (float) $score['scoreAuthoritativeness'],
                'score_trust' => (float) $score['scoreTrust'],
                'grade' => (string) $score['grade'],
                'blockers_count' => (int) $score['blockersCount'],
                'signals_json' => json_encode($score['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'version' => (string) $score['version'],
                'computed_at' => (string) $score['computedAt'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $scoreRowId = $this->findScoreId((string) $score['entityType'], (string) $score['entityId'], (string) ($score['locale'] ?? 'fr'));
            if ($scoreRowId === null) {
                throw new \RuntimeException('eeat_score_upsert_failed');
            }

            $history = $this->pdo->prepare(
                'INSERT INTO eeat_score_history
                 (score_id, run_id, score_global, score_experience, score_expertise, score_authoritativeness, score_trust, grade, signals_json, computed_at)
                 VALUES
                 (:score_id, :run_id, :score_global, :score_experience, :score_expertise, :score_authoritativeness, :score_trust, :grade, :signals_json, :computed_at)'
            );
            $history->execute([
                'score_id' => $scoreRowId,
                'run_id' => $runId,
                'score_global' => (float) $score['scoreGlobal'],
                'score_experience' => (float) $score['scoreExperience'],
                'score_expertise' => (float) $score['scoreExpertise'],
                'score_authoritativeness' => (float) $score['scoreAuthoritativeness'],
                'score_trust' => (float) $score['scoreTrust'],
                'grade' => (string) $score['grade'],
                'signals_json' => json_encode($score['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'computed_at' => (string) $score['computedAt'],
            ]);

            $deleteReco = $this->pdo->prepare('DELETE FROM eeat_recommendations WHERE score_id = :score_id');
            $deleteReco->execute(['score_id' => $scoreRowId]);
            if ($recommendations !== []) {
                $insertReco = $this->pdo->prepare(
                    'INSERT INTO eeat_recommendations
                    (score_id, entity_type, entity_id, rule_code, severity, impact, effort, message, fix_suggestion, status, created_at, updated_at)
                    VALUES
                    (:score_id, :entity_type, :entity_id, :rule_code, :severity, :impact, :effort, :message, :fix_suggestion, :status, :created_at, :updated_at)'
                );
                foreach ($recommendations as $recommendation) {
                    $insertReco->execute([
                        'score_id' => $scoreRowId,
                        'entity_type' => (string) $score['entityType'],
                        'entity_id' => (string) $score['entityId'],
                        'rule_code' => (string) $recommendation['ruleCode'],
                        'severity' => (string) $recommendation['severity'],
                        'impact' => (string) $recommendation['impact'],
                        'effort' => (string) $recommendation['effort'],
                        'message' => (string) $recommendation['message'],
                        'fix_suggestion' => (string) $recommendation['fixSuggestion'],
                        'status' => 'open',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listScores(int $limit = 100, int $offset = 0, ?string $entityType = null): array
    {
        $sql = 'SELECT id, entity_type, entity_id, entity_slug, locale, score_global, score_experience, score_expertise, score_authoritativeness, score_trust, grade, blockers_count, version, computed_at
                FROM eeat_scores';
        $params = [];
        if ($entityType !== null && $entityType !== '') {
            $sql .= ' WHERE entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' ORDER BY score_global ASC, computed_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getScore(string $entityType, string $entityId, string $locale = 'fr'): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, entity_slug, locale, score_global, score_experience, score_expertise, score_authoritativeness, score_trust, grade, blockers_count, signals_json, version, computed_at
             FROM eeat_scores
             WHERE entity_type = :entity_type AND entity_id = :entity_id AND locale = :locale
             LIMIT 1'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'locale' => $locale,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['signals'] = json_decode((string) ($row['signals_json'] ?? '{}'), true);
        unset($row['signals_json']);

        $row['recommendations'] = $this->listRecommendations($entityType, $entityId, 100, 0);
        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecommendations(?string $entityType, ?string $entityId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT id, score_id, entity_type, entity_id, rule_code, severity, impact, effort, message, fix_suggestion, status, updated_at
                , owner, due_date, note, last_status_change_at
                FROM eeat_recommendations
                WHERE 1=1';
        $params = [];
        if ($entityType !== null && $entityType !== '') {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($entityId !== null && $entityId !== '') {
            $sql .= ' AND entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        $sql .= " ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low'), updated_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function overviewStats(): array
    {
        $summaryStmt = $this->pdo->query(
            'SELECT COUNT(*) AS total, AVG(score_global) AS avg_score, SUM(blockers_count) AS blockers
             FROM eeat_scores'
        );
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summary)) {
            $summary = ['total' => 0, 'avg_score' => 0, 'blockers' => 0];
        }

        $byEntityTypeStmt = $this->pdo->query(
            'SELECT entity_type, COUNT(*) AS total, AVG(score_global) AS avg_score
             FROM eeat_scores
             GROUP BY entity_type
             ORDER BY avg_score ASC'
        );
        $byEntityType = $byEntityTypeStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($byEntityType)) {
            $byEntityType = [];
        }

        $topRulesStmt = $this->pdo->query(
            'SELECT rule_code, severity, COUNT(*) AS total
             FROM eeat_recommendations
             WHERE status = "open"
             GROUP BY rule_code, severity
             ORDER BY total DESC
             LIMIT 20'
        );
        $topRules = $topRulesStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($topRules)) {
            $topRules = [];
        }

        $severityStmt = $this->pdo->query(
            'SELECT severity, COUNT(*) AS total
             FROM eeat_recommendations
             WHERE status = "open"
             GROUP BY severity
             ORDER BY total DESC'
        );
        $severityBreakdown = $severityStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($severityBreakdown)) {
            $severityBreakdown = [];
        }

        return [
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'averageScore' => round((float) ($summary['avg_score'] ?? 0), 2),
                'totalBlockers' => (int) ($summary['blockers'] ?? 0),
            ],
            'byEntityType' => $byEntityType,
            'topRules' => $topRules,
            'severityBreakdown' => $severityBreakdown,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listOpportunities(int $limit = 50, int $offset = 0, ?string $entityType = null): array
    {
        $sql = "SELECT
                    s.entity_type,
                    s.entity_id,
                    s.entity_slug,
                    s.score_global,
                    s.grade,
                    s.blockers_count,
                    s.computed_at,
                    MAX(CASE r.severity
                        WHEN 'critical' THEN 4
                        WHEN 'high' THEN 3
                        WHEN 'medium' THEN 2
                        ELSE 1 END) AS severity_weight,
                    SUM(CASE r.severity
                        WHEN 'critical' THEN 4
                        WHEN 'high' THEN 3
                        WHEN 'medium' THEN 2
                        ELSE 1 END) AS severity_sum,
                    COUNT(r.id) AS recommendation_count
                FROM eeat_scores s
                LEFT JOIN eeat_recommendations r ON r.score_id = s.id AND r.status = 'open'
                WHERE 1=1";
        $params = [];
        if ($entityType !== null && $entityType !== '') {
            $sql .= ' AND s.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' GROUP BY s.id, s.entity_type, s.entity_id, s.entity_slug, s.score_global, s.grade, s.blockers_count, s.computed_at';
        $sql .= " ORDER BY ((100 - s.score_global) * 0.65
                    + COALESCE(SUM(CASE r.severity
                        WHEN 'critical' THEN 4
                        WHEN 'high' THEN 3
                        WHEN 'medium' THEN 2
                        ELSE 1 END), 0) * 6
                    + s.blockers_count * 4) DESC, s.score_global ASC";
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function (array $row): array {
            $score = (float) ($row['score_global'] ?? 0.0);
            $severitySum = (int) ($row['severity_sum'] ?? 0);
            $blockers = (int) ($row['blockers_count'] ?? 0);
            $priority = round(((100 - $score) * 0.65) + ($severitySum * 6) + ($blockers * 4), 2);
            $row['priority_score'] = $priority;
            return $row;
        }, $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listQuickWins(int $limit = 50, int $offset = 0, ?string $entityType = null): array
    {
        $sql = "SELECT
                    s.entity_type,
                    s.entity_id,
                    s.entity_slug,
                    s.score_global,
                    s.grade,
                    s.blockers_count,
                    s.computed_at,
                    COUNT(r.id) AS recommendation_count,
                    SUM(CASE WHEN r.impact = 'high' THEN 1 ELSE 0 END) AS high_impact_count,
                    SUM(CASE WHEN r.effort = 'low' THEN 1 ELSE 0 END) AS low_effort_count
                FROM eeat_scores s
                INNER JOIN eeat_recommendations r ON r.score_id = s.id
                WHERE r.status = 'open' AND r.impact = 'high' AND r.effort = 'low'";
        $params = [];
        if ($entityType !== null && $entityType !== '') {
            $sql .= ' AND s.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' GROUP BY s.id, s.entity_type, s.entity_id, s.entity_slug, s.score_global, s.grade, s.blockers_count, s.computed_at';
        $sql .= ' ORDER BY recommendation_count DESC, s.score_global ASC, s.blockers_count DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function updateRecommendationStatus(int $recommendationId, string $status): bool
    {
        if (!in_array($status, ['open', 'in_progress', 'done', 'dismissed'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE eeat_recommendations
             SET status = :status, updated_at = :updated_at, last_status_change_at = :last_status_change_at
             WHERE id = :id'
        );
        return $stmt->execute([
            'id' => $recommendationId,
            'status' => $status,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_status_change_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateRecommendationAssignment(int $recommendationId, array $payload): bool
    {
        if ($recommendationId < 1) {
            return false;
        }
        $owner = isset($payload['owner']) ? trim((string) $payload['owner']) : null;
        $dueDate = isset($payload['dueDate']) ? trim((string) $payload['dueDate']) : null;
        $note = isset($payload['note']) ? trim((string) $payload['note']) : null;

        if ($owner === '') {
            $owner = null;
        }
        if ($dueDate === '') {
            $dueDate = null;
        }
        if ($note === '') {
            $note = null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE eeat_recommendations
             SET owner = :owner, due_date = :due_date, note = :note, updated_at = :updated_at
             WHERE id = :id'
        );
        return $stmt->execute([
            'id' => $recommendationId,
            'owner' => $owner,
            'due_date' => $dueDate,
            'note' => $note,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecommendationsByStatus(string $status, int $limit = 100, int $offset = 0): array
    {
        if (!in_array($status, ['open', 'in_progress', 'done', 'dismissed'], true)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, score_id, entity_type, entity_id, rule_code, severity, impact, effort, message, fix_suggestion, status, updated_at, owner, due_date, note, last_status_change_at
             FROM eeat_recommendations
             WHERE status = :status
             ORDER BY FIELD(severity, "critical", "high", "medium", "low"), updated_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('status', $status);
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function progressStats(): array
    {
        $statusRows = $this->pdo->query(
            'SELECT status, COUNT(*) AS total
             FROM eeat_recommendations
             GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($statusRows)) {
            $statusRows = [];
        }
        $statusMap = [
            'open' => 0,
            'in_progress' => 0,
            'done' => 0,
            'dismissed' => 0,
        ];
        foreach ($statusRows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($statusMap[$status])) {
                $statusMap[$status] = (int) ($row['total'] ?? 0);
            }
        }

        $deltaRows = $this->pdo->query(
            'SELECT s.entity_type, s.entity_id, MIN(h.score_global) AS min_score, MAX(h.score_global) AS max_score
             FROM eeat_score_history h
             INNER JOIN eeat_scores s ON s.id = h.score_id
             GROUP BY s.entity_type, s.entity_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($deltaRows)) {
            $deltaRows = [];
        }
        $improved = 0;
        $degraded = 0;
        $stable = 0;
        foreach ($deltaRows as $row) {
            $min = (float) ($row['min_score'] ?? 0.0);
            $max = (float) ($row['max_score'] ?? 0.0);
            $delta = $max - $min;
            if ($delta > 2.0) {
                $improved++;
            } elseif ($delta < -2.0) {
                $degraded++;
            } else {
                $stable++;
            }
        }

        return [
            'recommendationStatus' => $statusMap,
            'scoreEvolution' => [
                'improved' => $improved,
                'degraded' => $degraded,
                'stable' => $stable,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function runTrends(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, run_type, status, started_at, ended_at, stats_json
             FROM eeat_analysis_runs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static function (array $row): array {
            /** @var mixed $stats */
            $stats = json_decode((string) ($row['stats_json'] ?? '{}'), true);
            $row['stats'] = is_array($stats) ? $stats : [];
            unset($row['stats_json']);
            return $row;
        }, $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listOverdueRecommendations(int $limit = 100, int $offset = 0, ?string $owner = null): array
    {
        $sql = 'SELECT id, score_id, entity_type, entity_id, rule_code, severity, impact, effort, message, fix_suggestion, status, owner, due_date, note, updated_at
                FROM eeat_recommendations
                WHERE status IN ("open", "in_progress")
                  AND due_date IS NOT NULL
                  AND due_date < CURRENT_DATE()';
        $params = [];
        if ($owner !== null && $owner !== '') {
            $sql .= ' AND owner = :owner';
            $params['owner'] = $owner;
        }
        $sql .= ' ORDER BY FIELD(severity, "critical", "high", "medium", "low"), due_date ASC, updated_at ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listDueSoonRecommendations(int $days = 3, int $limit = 100, int $offset = 0, ?string $owner = null): array
    {
        $days = max(1, min(30, $days));
        $sql = 'SELECT id, score_id, entity_type, entity_id, rule_code, severity, impact, effort, message, fix_suggestion, status, owner, due_date, note, updated_at
                FROM eeat_recommendations
                WHERE status IN ("open", "in_progress")
                  AND due_date IS NOT NULL
                  AND due_date >= CURRENT_DATE()
                  AND due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL :days DAY)';
        $params = ['days' => $days];
        if ($owner !== null && $owner !== '') {
            $sql .= ' AND owner = :owner';
            $params['owner'] = $owner;
        }
        $sql .= ' ORDER BY due_date ASC, FIELD(severity, "critical", "high", "medium", "low"), updated_at ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'days') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function slaStats(): array
    {
        $global = $this->pdo->query(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date < CURRENT_DATE() THEN 1 ELSE 0 END) AS overdue_total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date < CURRENT_DATE() AND severity = "critical" THEN 1 ELSE 0 END) AS overdue_critical
             FROM eeat_recommendations'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($global)) {
            $global = ['total' => 0, 'overdue_total' => 0, 'overdue_critical' => 0];
        }

        $byOwner = $this->pdo->query(
            'SELECT
                COALESCE(owner, "unassigned") AS owner,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date < CURRENT_DATE() THEN 1 ELSE 0 END) AS overdue_total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date < CURRENT_DATE() AND severity = "critical" THEN 1 ELSE 0 END) AS overdue_critical
             FROM eeat_recommendations
             GROUP BY owner
             ORDER BY overdue_total DESC, owner ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($byOwner)) {
            $byOwner = [];
        }

        return [
            'summary' => [
                'totalRecommendations' => (int) ($global['total'] ?? 0),
                'overdueTotal' => (int) ($global['overdue_total'] ?? 0),
                'overdueCritical' => (int) ($global['overdue_critical'] ?? 0),
            ],
            'byOwner' => $byOwner,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listCriticalOverdue(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, rule_code, severity, impact, effort, status, owner, due_date, note, updated_at
             FROM eeat_recommendations
             WHERE status IN ("open","in_progress")
               AND severity = "critical"
               AND due_date IS NOT NULL
               AND due_date < CURRENT_DATE()
             ORDER BY due_date ASC, updated_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function autoPrioritizeCriticalOverdue(bool $dryRun = true, int $limit = 100): array
    {
        $items = $this->listCriticalOverdue($limit);
        if ($dryRun || $items === []) {
            return [
                'dryRun' => $dryRun,
                'updated' => 0,
                'items' => $items,
            ];
        }

        $updated = 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE eeat_recommendations
             SET status = :status, note = :note, last_status_change_at = :last_status_change_at, updated_at = :updated_at
             WHERE id = :id'
        );
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $currentNote = trim((string) ($item['note'] ?? ''));
            $autoNote = '[AUTO-PRIORITIZE] Critical overdue escalated ' . $now;
            $mergedNote = $currentNote === '' ? $autoNote : ($currentNote . "\n" . $autoNote);
            $ok = $stmt->execute([
                'id' => $id,
                'status' => 'in_progress',
                'note' => $mergedNote,
                'last_status_change_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ok) {
                $updated++;
            }
        }

        return [
            'dryRun' => false,
            'updated' => $updated,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function digestStats(int $dueSoonDays = 3): array
    {
        $dueSoonDays = max(1, min(30, $dueSoonDays));
        $summary = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("open","in_progress") THEN 1 ELSE 0 END) AS active_total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date < CURRENT_DATE() THEN 1 ELSE 0 END) AS overdue_total,
                SUM(CASE WHEN status IN ("open","in_progress") AND due_date IS NOT NULL AND due_date >= CURRENT_DATE() AND due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL :days DAY) THEN 1 ELSE 0 END) AS due_soon_total
             FROM eeat_recommendations'
        );
        $summary->bindValue('days', $dueSoonDays, PDO::PARAM_INT);
        $summary->execute();
        $row = $summary->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $row = ['total' => 0, 'active_total' => 0, 'overdue_total' => 0, 'due_soon_total' => 0];
        }

        return [
            'dueSoonDays' => $dueSoonDays,
            'totalRecommendations' => (int) ($row['total'] ?? 0),
            'activeRecommendations' => (int) ($row['active_total'] ?? 0),
            'overdueRecommendations' => (int) ($row['overdue_total'] ?? 0),
            'dueSoonRecommendations' => (int) ($row['due_soon_total'] ?? 0),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecommendationOwners(): array
    {
        $rows = $this->pdo->query(
            'SELECT COALESCE(owner, "unassigned") AS owner, status, COUNT(*) AS total
             FROM eeat_recommendations
             GROUP BY owner, status
             ORDER BY owner ASC, status ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSeoSettingsBundle(): array
    {
        $site = $this->pdo->query('SELECT * FROM seo_site_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $social = $this->pdo->query('SELECT * FROM seo_social_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $schema = $this->pdo->query('SELECT * FROM seo_schema_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $eeat = $this->pdo->query('SELECT * FROM seo_eeat_defaults WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $rules = $this->pdo->query('SELECT entity_type, robots_directive, include_in_sitemap FROM seo_indexation_rules ORDER BY entity_type ASC')->fetchAll(PDO::FETCH_ASSOC);

        return [
            'site' => is_array($site) ? $site : [],
            'social' => is_array($social) ? $social : [],
            'schema' => is_array($schema) ? $schema : [],
            'eeat' => is_array($eeat) ? $eeat : [],
            'indexationRules' => is_array($rules) ? $rules : [],
        ];
    }

    public function ensureSeoSettingsDefaults(): void
    {
        $this->pdo->exec('INSERT INTO seo_site_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
        $this->pdo->exec('INSERT INTO seo_social_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
        $this->pdo->exec('INSERT INTO seo_schema_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
        $this->pdo->exec('INSERT INTO seo_eeat_defaults (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');

        $stmt = $this->pdo->prepare(
            'INSERT INTO seo_indexation_rules (entity_type, robots_directive, include_in_sitemap)
             VALUES (:entity_type, :robots_directive, :include_in_sitemap)
             ON DUPLICATE KEY UPDATE
                robots_directive = VALUES(robots_directive),
                include_in_sitemap = VALUES(include_in_sitemap)'
        );
        foreach (['product', 'category', 'cms_page', 'blog_article', 'faq_item', 'legal_page'] as $entityType) {
            $stmt->execute([
                'entity_type' => $entityType,
                'robots_directive' => 'index,follow',
                'include_in_sitemap' => 1,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveSeoSettingsBundle(array $payload, ?int $updatedBy = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $site = is_array($payload['site'] ?? null) ? $payload['site'] : [];
        $social = is_array($payload['social'] ?? null) ? $payload['social'] : [];
        $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
        $eeat = is_array($payload['eeat'] ?? null) ? $payload['eeat'] : [];
        $indexationRules = is_array($payload['indexationRules'] ?? null) ? $payload['indexationRules'] : [];

        $upsertSite = $this->pdo->prepare(
            'UPDATE seo_site_settings SET
                site_name = :site_name,
                title_template = :title_template,
                meta_description_template = :meta_description_template,
                canonical_base = :canonical_base,
                robots_default = :robots_default,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = 1'
        );
        $upsertSite->execute([
            'site_name' => trim((string) ($site['site_name'] ?? 'Hypocommerce CMS')),
            'title_template' => trim((string) ($site['title_template'] ?? '{title} | {site_name}')),
            'meta_description_template' => trim((string) ($site['meta_description_template'] ?? '{title} - {site_name}')),
            'canonical_base' => $this->nullIfEmpty((string) ($site['canonical_base'] ?? '')),
            'robots_default' => $this->normalizeRobots((string) ($site['robots_default'] ?? 'index,follow')),
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ]);

        $upsertSocial = $this->pdo->prepare(
            'UPDATE seo_social_settings SET
                og_site_name = :og_site_name,
                default_og_image_url = :default_og_image_url,
                twitter_card = :twitter_card,
                twitter_site = :twitter_site,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = 1'
        );
        $upsertSocial->execute([
            'og_site_name' => $this->nullIfEmpty((string) ($social['og_site_name'] ?? '')),
            'default_og_image_url' => $this->nullIfEmpty((string) ($social['default_og_image_url'] ?? '')),
            'twitter_card' => trim((string) ($social['twitter_card'] ?? 'summary_large_image')),
            'twitter_site' => $this->nullIfEmpty((string) ($social['twitter_site'] ?? '')),
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ]);

        $upsertSchema = $this->pdo->prepare(
            'UPDATE seo_schema_settings SET
                organization_name = :organization_name,
                organization_url = :organization_url,
                organization_logo_url = :organization_logo_url,
                website_name = :website_name,
                website_url = :website_url,
                search_url_template = :search_url_template,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = 1'
        );
        $upsertSchema->execute([
            'organization_name' => $this->nullIfEmpty((string) ($schema['organization_name'] ?? '')),
            'organization_url' => $this->nullIfEmpty((string) ($schema['organization_url'] ?? '')),
            'organization_logo_url' => $this->nullIfEmpty((string) ($schema['organization_logo_url'] ?? '')),
            'website_name' => $this->nullIfEmpty((string) ($schema['website_name'] ?? '')),
            'website_url' => $this->nullIfEmpty((string) ($schema['website_url'] ?? '')),
            'search_url_template' => $this->nullIfEmpty((string) ($schema['search_url_template'] ?? '')),
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ]);

        $upsertEeat = $this->pdo->prepare(
            'UPDATE seo_eeat_defaults SET
                default_author_name = :default_author_name,
                default_author_role = :default_author_role,
                trust_statement = :trust_statement,
                faq_template = :faq_template,
                min_score_default = :min_score_default,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = 1'
        );
        $upsertEeat->execute([
            'default_author_name' => $this->nullIfEmpty((string) ($eeat['default_author_name'] ?? '')),
            'default_author_role' => $this->nullIfEmpty((string) ($eeat['default_author_role'] ?? '')),
            'trust_statement' => $this->nullIfEmpty((string) ($eeat['trust_statement'] ?? '')),
            'faq_template' => $this->nullIfEmpty((string) ($eeat['faq_template'] ?? '')),
            'min_score_default' => max(40, min(95, (int) ($eeat['min_score_default'] ?? 60))),
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ]);

        $ruleStmt = $this->pdo->prepare(
            'INSERT INTO seo_indexation_rules (entity_type, robots_directive, include_in_sitemap, updated_by, updated_at)
             VALUES (:entity_type, :robots_directive, :include_in_sitemap, :updated_by, :updated_at)
             ON DUPLICATE KEY UPDATE
                robots_directive = VALUES(robots_directive),
                include_in_sitemap = VALUES(include_in_sitemap),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );
        foreach ($indexationRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $entityType = trim((string) ($rule['entity_type'] ?? ''));
            if ($entityType === '') {
                continue;
            }
            $ruleStmt->execute([
                'entity_type' => $entityType,
                'robots_directive' => $this->normalizeRobots((string) ($rule['robots_directive'] ?? 'index,follow')),
                'include_in_sitemap' => (bool) ($rule['include_in_sitemap'] ?? true) ? 1 : 0,
                'updated_by' => $updatedBy,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function coverageAuditReport(): array
    {
        $rows = $this->pdo->query(
            'SELECT entity_type, COUNT(*) AS total,
                    SUM(CASE WHEN score_global >= 70 THEN 1 ELSE 0 END) AS compliant,
                    SUM(CASE WHEN JSON_EXTRACT(signals_json, "$.meta_title") = "optimal" THEN 1 ELSE 0 END) AS meta_title_ok,
                    SUM(CASE WHEN JSON_EXTRACT(signals_json, "$.meta_description") = "optimal" THEN 1 ELSE 0 END) AS meta_description_ok,
                    SUM(CASE WHEN JSON_EXTRACT(signals_json, "$.policy_missing") = "yes" THEN 1 ELSE 0 END) AS policy_missing
             FROM eeat_scores
             GROUP BY entity_type
             ORDER BY entity_type ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        $report = [];
        foreach ($rows as $row) {
            $total = (int) ($row['total'] ?? 0);
            $compliant = (int) ($row['compliant'] ?? 0);
            $missingRequired = max(0, $total - (int) ($row['meta_title_ok'] ?? 0)) + max(0, $total - (int) ($row['meta_description_ok'] ?? 0));
            $blocking = (int) ($row['policy_missing'] ?? 0);
            $report[] = [
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'total' => $total,
                'compliance_rate' => $total > 0 ? round(($compliant * 100) / $total, 2) : 0.0,
                'missing_required_fields' => $missingRequired,
                'blocking_items' => $blocking,
                'priority_score' => round(($missingRequired * 2.5) + ($blocking * 4.0) + max(0.0, (100.0 - ($total > 0 ? (($compliant * 100) / $total) : 0.0))), 2),
            ];
        }
        usort($report, static function (array $a, array $b): int {
            return ((float) ($b['priority_score'] ?? 0.0)) <=> ((float) ($a['priority_score'] ?? 0.0));
        });
        return [
            'items' => $report,
            'backlogPrioritized' => array_slice($report, 0, 20),
        ];
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRobots(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true) ? $normalized : 'index,follow';
    }

    private function findScoreId(string $entityType, string $entityId, string $locale): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM eeat_scores WHERE entity_type = :entity_type AND entity_id = :entity_id AND locale = :locale LIMIT 1'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'locale' => $locale,
        ]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }
}
