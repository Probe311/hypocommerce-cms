<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

final class EEATRuleEngine
{
    public const VERSION = 'v2';

    /** @var array<string,mixed> */
    private array $templateMatrix;

    public function __construct(?string $matrixPath = null)
    {
        $path = $matrixPath ?? dirname(__DIR__, 3) . '/config/eeat_template_matrix.json';
        $this->templateMatrix = $this->loadTemplateMatrix($path);
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public function score(array $content): array
    {
        $entityType = (string) ($content['entityType'] ?? 'unknown');
        $title = (string) ($content['title'] ?? '');
        $metaTitle = (string) ($content['metaTitle'] ?? '');
        $metaDescription = (string) ($content['metaDescription'] ?? '');
        $body = (string) ($content['body'] ?? '');
        $author = (string) ($content['author'] ?? '');
        $trustSignals = is_array($content['trustSignals'] ?? null) ? $content['trustSignals'] : [];
        $updatedAt = (string) ($content['updatedAt'] ?? '');

        $wordCount = $this->wordCount($body);
        $internalLinkCount = $this->countInternalLinks($body);
        $faqQuestionCount = $this->countOccurrences($body, ['?']);
        $templateHintCoverage = $this->templateHintCoverage($entityType, $body);
        $keywordCoverage = $this->keywordCoverage($title, $body);
        $readability = $this->readabilityScore($body);
        $experience = 0.0;
        $expertise = 0.0;
        $authoritativeness = 0.0;
        $trust = 0.0;
        $blockers = 0;
        $signals = [];

        $depthTarget = $this->wordDepthThreshold($entityType);
        if ($wordCount >= $depthTarget) {
            $experience += 35;
            $signals['content_depth'] = 'good';
        } elseif ($wordCount >= (int) floor($depthTarget * 0.45)) {
            $experience += 20;
            $signals['content_depth'] = 'medium';
        } else {
            $signals['content_depth'] = 'low';
            $blockers++;
        }

        if ($title !== '') {
            $experience += 15;
            $expertise += 10;
        } else {
            $blockers++;
        }
        if ($metaTitle !== '' && mb_strlen($metaTitle) >= 20 && mb_strlen($metaTitle) <= 65) {
            $expertise += 25;
            $authoritativeness += 10;
            $trust += 10;
            $signals['meta_title'] = 'optimal';
        } else {
            $signals['meta_title'] = 'missing_or_bad_length';
            $blockers++;
        }

        if ($metaDescription !== '' && mb_strlen($metaDescription) >= 70 && mb_strlen($metaDescription) <= 180) {
            $expertise += 20;
            $trust += 15;
            $signals['meta_description'] = 'optimal';
        } else {
            $signals['meta_description'] = 'missing_or_bad_length';
            $blockers++;
        }

        if ($author !== '') {
            $expertise += 20;
            $authoritativeness += 20;
            $trust += 10;
            $signals['author'] = 'present';
        } else {
            $signals['author'] = 'missing';
        }

        if (($trustSignals['hasFreshness'] ?? false) === true) {
            $trust += 20;
            $signals['freshness'] = 'present';
        } else {
            $signals['freshness'] = 'missing';
        }
        if (($trustSignals['hasMeta'] ?? false) === true) {
            $trust += 15;
        }
        if (($trustSignals['hasAuthor'] ?? false) === true) {
            $authoritativeness += 15;
        }
        if (($trustSignals['duplicateTitle'] ?? false) === true) {
            $expertise -= 8;
            $trust -= 6;
            $signals['duplicate_title'] = 'yes';
            $blockers++;
        }
        if (($trustSignals['duplicateMetaTitle'] ?? false) === true) {
            $authoritativeness -= 8;
            $trust -= 8;
            $signals['duplicate_meta_title'] = 'yes';
            $blockers++;
        }
        $daysSinceUpdate = $this->daysSince($updatedAt);
        if ($daysSinceUpdate <= 90) {
            $trust += 10;
            $signals['recency_days'] = (string) $daysSinceUpdate;
        } elseif ($daysSinceUpdate > 365) {
            $signals['stale_content'] = 'yes';
            $blockers++;
        }

        if ($internalLinkCount >= 3) {
            $authoritativeness += 12;
            $signals['internal_links'] = 'good';
        } elseif ($internalLinkCount >= 1) {
            $authoritativeness += 6;
            $signals['internal_links'] = 'medium';
        } else {
            $signals['internal_links'] = 'missing';
        }

        if ($faqQuestionCount >= 2) {
            $experience += 8;
            $trust += 5;
            $signals['faq_richness'] = 'good';
        }

        if ($templateHintCoverage >= 0.5) {
            $experience += 10;
            $expertise += 8;
            $signals['template_hint_coverage'] = 'good';
        } elseif ($templateHintCoverage > 0.0) {
            $experience += 5;
            $signals['template_hint_coverage'] = 'medium';
        } else {
            $signals['template_hint_coverage'] = 'missing';
        }

        if ($keywordCoverage >= 0.7) {
            $expertise += 8;
            $signals['keyword_coverage'] = 'good';
        } elseif ($keywordCoverage >= 0.4) {
            $expertise += 4;
            $signals['keyword_coverage'] = 'medium';
        } else {
            $signals['keyword_coverage'] = 'low';
        }

        if ($readability >= 70) {
            $experience += 8;
            $signals['readability'] = 'good';
        } elseif ($readability >= 45) {
            $experience += 4;
            $signals['readability'] = 'medium';
        } else {
            $signals['readability'] = 'low';
        }

        if (($trustSignals['businessCritical'] ?? false) === true) {
            $trust += 5;
            $signals['business_critical'] = 'yes';
        }
        if (($trustSignals['generatedMetaTitle'] ?? false) === true) {
            $signals['meta_title_source'] = 'generated';
        } else {
            $signals['meta_title_source'] = 'explicit';
        }
        if (($trustSignals['generatedMetaDescription'] ?? false) === true) {
            $signals['meta_description_source'] = 'generated';
        } else {
            $signals['meta_description_source'] = 'explicit';
        }
        $hasCanonical = (bool) ($trustSignals['hasCanonical'] ?? false);
        $signals['canonical'] = $hasCanonical ? 'present' : 'missing';
        if (($trustSignals['requiresAuthor'] ?? false) === true && $author === '') {
            $signals['policy_missing'] = 'yes';
            $blockers++;
        }
        $minScoreTarget = (int) ($trustSignals['minScoreTarget'] ?? 60);
        $signals['min_score_target'] = (string) $minScoreTarget;
        $signals['schema_type'] = (string) ($trustSignals['schemaType'] ?? 'WebPage');

        $experience = max(0.0, min(100.0, $experience));
        $expertise = max(0.0, min(100.0, $expertise));
        $authoritativeness = max(0.0, min(100.0, $authoritativeness));
        $trust = max(0.0, min(100.0, $trust));

        $weights = $this->dimensionWeights($entityType);
        $global = round(
            ($experience * $weights['experience'])
            + ($expertise * $weights['expertise'])
            + ($authoritativeness * $weights['authoritativeness'])
            + ($trust * $weights['trust']),
            2
        );
        if ($global < $minScoreTarget) {
            $signals['below_min_target'] = 'yes';
        } else {
            $signals['below_min_target'] = 'no';
        }

        return [
            'scoreGlobal' => $global,
            'scoreExperience' => round($experience, 2),
            'scoreExpertise' => round($expertise, 2),
            'scoreAuthoritativeness' => round($authoritativeness, 2),
            'scoreTrust' => round($trust, 2),
            'grade' => $this->gradeFromScore($global),
            'blockersCount' => $blockers,
            'signals' => $signals,
            'version' => self::VERSION,
            'computedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    private function gradeFromScore(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            default => 'D',
        };
    }

    private function wordCount(string $text): int
    {
        $trimmed = trim(strip_tags($text));
        if ($trimmed === '') {
            return 0;
        }
        $parts = preg_split('/\s+/', $trimmed);
        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadTemplateMatrix(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function wordDepthThreshold(string $entityType): int
    {
        return match ($entityType) {
            'product' => 220,
            'faq_item' => 90,
            'category' => 140,
            'blog_article', 'cms_page' => 420,
            default => 180,
        };
    }

    /**
     * @return array{experience:float,expertise:float,authoritativeness:float,trust:float}
     */
    private function dimensionWeights(string $entityType): array
    {
        return match ($entityType) {
            'product' => ['experience' => 0.30, 'expertise' => 0.25, 'authoritativeness' => 0.15, 'trust' => 0.30],
            'blog_article' => ['experience' => 0.20, 'expertise' => 0.35, 'authoritativeness' => 0.20, 'trust' => 0.25],
            default => ['experience' => 0.25, 'expertise' => 0.30, 'authoritativeness' => 0.20, 'trust' => 0.25],
        };
    }

    private function countInternalLinks(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        preg_match_all('/(?:href=|https?:\/\/|\/[a-z0-9\-_\/]+)/i', $text, $matches);
        return isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;
    }

    /**
     * @param array<int,string> $tokens
     */
    private function countOccurrences(string $text, array $tokens): int
    {
        $sum = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $sum += substr_count($text, $token);
        }
        return $sum;
    }

    private function templateHintCoverage(string $entityType, string $body): float
    {
        $templates = $this->templateMatrix['templates'] ?? [];
        if (!is_array($templates) || $body === '') {
            return 0.0;
        }
        $template = match ($entityType) {
            'product' => $templates['fiche_produit_p1'] ?? null,
            'category', 'brand_page' => $templates['marque'] ?? null,
            default => null,
        };
        if (!is_array($template)) {
            return 0.0;
        }
        $hints = [];
        foreach ($template as $value) {
            if (is_string($value)) {
                $hints[] = $value;
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $sub) {
                    if (is_string($sub)) {
                        $hints[] = $sub;
                    }
                    if (is_array($sub)) {
                        foreach ($sub as $subValue) {
                            if (is_string($subValue)) {
                                $hints[] = $subValue;
                            }
                        }
                    }
                }
            }
        }
        if ($hints === []) {
            return 0.0;
        }
        $bodyLower = mb_strtolower($body);
        $matched = 0;
        foreach ($hints as $hint) {
            $sample = trim(mb_strtolower(mb_substr($hint, 0, 20)));
            if ($sample !== '' && mb_strpos($bodyLower, $sample) !== false) {
                $matched++;
            }
        }
        return $matched / max(1, count($hints));
    }

    private function daysSince(string $updatedAt): int
    {
        if ($updatedAt === '') {
            return PHP_INT_MAX;
        }
        try {
            $at = new \DateTimeImmutable($updatedAt);
            $now = new \DateTimeImmutable();
            return (int) $at->diff($now)->days;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }

    private function keywordCoverage(string $title, string $body): float
    {
        $title = mb_strtolower(trim($title));
        $body = mb_strtolower($body);
        if ($title === '' || $body === '') {
            return 0.0;
        }
        $titleTerms = preg_split('/\s+/', preg_replace('/[^a-z0-9 ]/i', ' ', $title) ?? '');
        if (!is_array($titleTerms)) {
            return 0.0;
        }
        $titleTerms = array_values(array_filter($titleTerms, static fn ($t): bool => is_string($t) && mb_strlen($t) >= 4));
        if ($titleTerms === []) {
            return 0.0;
        }
        $covered = 0;
        foreach ($titleTerms as $term) {
            if (mb_strpos($body, $term) !== false) {
                $covered++;
            }
        }
        return $covered / count($titleTerms);
    }

    private function readabilityScore(string $text): float
    {
        $clean = trim(strip_tags($text));
        if ($clean === '') {
            return 0.0;
        }
        $words = $this->wordCount($clean);
        $sentences = max(1, $this->countOccurrences($clean, ['.', '!', '?']));
        $avgSentenceLength = $words / $sentences;
        if ($avgSentenceLength <= 16) {
            return 85.0;
        }
        if ($avgSentenceLength <= 22) {
            return 65.0;
        }
        if ($avgSentenceLength <= 30) {
            return 45.0;
        }
        return 25.0;
    }
}
