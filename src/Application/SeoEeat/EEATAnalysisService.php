<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

use App\Infrastructure\Persistence\PdoEeatRepository;

final class EEATAnalysisService
{
    private AnalyzableContentExtractor $extractor;
    private EEATRuleEngine $ruleEngine;
    private RecommendationEngine $recommendationEngine;
    private PdoEeatRepository $repository;
    private SeoSettingsDefaultsApplier $settingsApplier;

    public function __construct()
    {
        $this->extractor = new AnalyzableContentExtractor();
        $this->ruleEngine = new EEATRuleEngine();
        $this->recommendationEngine = new RecommendationEngine();
        $this->repository = new PdoEeatRepository();
        $this->settingsApplier = new SeoSettingsDefaultsApplier();
    }

    /**
     * @return array<string,mixed>
     */
    public function recomputeAll(): array
    {
        return $this->run('full', ['mode' => 'all'], $this->extractor->extractAll());
    }

    /**
     * @return array<string,mixed>
     */
    public function recomputeChangedSince(string $sinceDateTime): array
    {
        return $this->run('incremental', ['since' => $sinceDateTime], $this->extractor->extractChangedSince($sinceDateTime));
    }

    /**
     * @param array<int,array<string,mixed>> $contents
     * @return array<string,mixed>
     */
    private function run(string $runType, array $scope, array $contents): array
    {
        $contents = $this->applySettingsDefaults($contents);
        $contents = $this->injectDuplicateSignals($contents);
        $runId = $this->repository->startRun($runType, $scope);
        $processed = 0;
        $failed = 0;
        $sumScore = 0.0;
        $byEntityType = [];

        try {
            foreach ($contents as $content) {
                try {
                    $score = $this->ruleEngine->score($content);
                    $recommendations = $this->recommendationEngine->build($content, $score);
                    $payload = array_merge($score, [
                        'entityType' => (string) $content['entityType'],
                        'entityId' => (string) $content['entityId'],
                        'entitySlug' => $content['entitySlug'] ?? null,
                        'locale' => (string) ($content['locale'] ?? 'fr'),
                    ]);
                    $this->repository->upsertScore($runId, $payload, $recommendations);
                    $processed++;
                    $sumScore += (float) $score['scoreGlobal'];
                    $entityType = (string) $content['entityType'];
                    if (!isset($byEntityType[$entityType])) {
                        $byEntityType[$entityType] = ['count' => 0, 'sum' => 0.0];
                    }
                    $byEntityType[$entityType]['count']++;
                    $byEntityType[$entityType]['sum'] += (float) $score['scoreGlobal'];
                } catch (\Throwable) {
                    $failed++;
                }
            }

            $entityTypeStats = [];
            foreach ($byEntityType as $entityType => $bucket) {
                $count = (int) ($bucket['count'] ?? 0);
                $sumByType = (float) ($bucket['sum'] ?? 0.0);
                $entityTypeStats[$entityType] = [
                    'count' => $count,
                    'averageScore' => $count > 0 ? round($sumByType / $count, 2) : 0.0,
                ];
            }
            $stats = [
                'processed' => $processed,
                'failed' => $failed,
                'averageScore' => $processed > 0 ? round($sumScore / $processed, 2) : 0.0,
                'byEntityType' => $entityTypeStats,
            ];
            $this->repository->completeRun($runId, 'completed', $stats);
            return ['runId' => $runId, 'status' => 'completed', 'stats' => $stats];
        } catch (\Throwable $e) {
            $this->repository->completeRun($runId, 'failed', [
                'processed' => $processed,
                'failed' => $failed + 1,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $contents
     * @return array<int,array<string,mixed>>
     */
    private function injectDuplicateSignals(array $contents): array
    {
        $titleCounts = [];
        $metaTitleCounts = [];
        foreach ($contents as $content) {
            $titleKey = mb_strtolower(trim((string) ($content['title'] ?? '')));
            $metaTitleKey = mb_strtolower(trim((string) ($content['metaTitle'] ?? '')));
            if ($titleKey !== '') {
                $titleCounts[$titleKey] = ($titleCounts[$titleKey] ?? 0) + 1;
            }
            if ($metaTitleKey !== '') {
                $metaTitleCounts[$metaTitleKey] = ($metaTitleCounts[$metaTitleKey] ?? 0) + 1;
            }
        }

        foreach ($contents as $index => $content) {
            $trustSignals = is_array($content['trustSignals'] ?? null) ? $content['trustSignals'] : [];
            $titleKey = mb_strtolower(trim((string) ($content['title'] ?? '')));
            $metaTitleKey = mb_strtolower(trim((string) ($content['metaTitle'] ?? '')));
            $trustSignals['duplicateTitle'] = $titleKey !== '' && ($titleCounts[$titleKey] ?? 0) > 1;
            $trustSignals['duplicateMetaTitle'] = $metaTitleKey !== '' && ($metaTitleCounts[$metaTitleKey] ?? 0) > 1;
            $content['trustSignals'] = $trustSignals;
            $contents[$index] = $content;
        }

        return $contents;
    }

    /**
     * @param array<int,array<string,mixed>> $contents
     * @return array<int,array<string,mixed>>
     */
    private function applySettingsDefaults(array $contents): array
    {
        $settings = $this->repository->getSeoSettingsBundle();
        foreach ($contents as $index => $content) {
            $contents[$index] = $this->settingsApplier->apply($content, $settings);
        }
        return $contents;
    }
}
