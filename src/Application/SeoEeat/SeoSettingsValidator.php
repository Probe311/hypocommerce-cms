<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

final class SeoSettingsValidator
{
    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    public function validate(array $payload): array
    {
        $errors = [];
        $site = is_array($payload['site'] ?? null) ? $payload['site'] : [];
        $social = is_array($payload['social'] ?? null) ? $payload['social'] : [];
        $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
        $eeat = is_array($payload['eeat'] ?? null) ? $payload['eeat'] : [];
        $indexationRules = is_array($payload['indexationRules'] ?? null) ? $payload['indexationRules'] : [];

        $this->checkLength($errors, (string) ($site['title_template'] ?? ''), 255, 'invalid_title_template_length');
        $this->checkLength($errors, (string) ($site['meta_description_template'] ?? ''), 255, 'invalid_meta_description_template_length');

        $robots = strtolower(trim((string) ($site['robots_default'] ?? 'index,follow')));
        if ($robots !== '' && !in_array($robots, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true)) {
            $errors[] = 'invalid_robots_default';
        }

        foreach ([
            'site.canonical_base' => (string) ($site['canonical_base'] ?? ''),
            'social.default_og_image_url' => (string) ($social['default_og_image_url'] ?? ''),
            'schema.organization_url' => (string) ($schema['organization_url'] ?? ''),
            'schema.organization_logo_url' => (string) ($schema['organization_logo_url'] ?? ''),
            'schema.website_url' => (string) ($schema['website_url'] ?? ''),
        ] as $field => $url) {
            if (trim($url) !== '' && !$this->isValidHttpUrl($url)) {
                $errors[] = 'invalid_url_' . str_replace('.', '_', $field);
            }
        }

        $searchTpl = trim((string) ($schema['search_url_template'] ?? ''));
        if ($searchTpl !== '') {
            if (!$this->isValidHttpUrl(str_replace('{query}', 'test', $searchTpl))) {
                $errors[] = 'invalid_search_url_template';
            }
            if (!str_contains($searchTpl, '{query}')) {
                $errors[] = 'missing_search_url_template_query_placeholder';
            }
        }

        $minScore = isset($eeat['min_score_default']) ? (int) $eeat['min_score_default'] : 60;
        if ($minScore < 40 || $minScore > 95) {
            $errors[] = 'invalid_min_score_default_range';
        }

        foreach ($indexationRules as $idx => $rule) {
            if (!is_array($rule)) {
                $errors[] = 'invalid_indexation_rule_' . $idx;
                continue;
            }
            $entityType = trim((string) ($rule['entity_type'] ?? ''));
            if (!in_array($entityType, ['product', 'category', 'cms_page', 'blog_article', 'faq_item', 'legal_page'], true)) {
                $errors[] = 'invalid_indexation_entity_type_' . $idx;
            }
            $directive = strtolower(trim((string) ($rule['robots_directive'] ?? 'index,follow')));
            if (!in_array($directive, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true)) {
                $errors[] = 'invalid_indexation_robots_directive_' . $idx;
            }
        }

        return $errors;
    }

    /**
     * @param array<int,string> $errors
     */
    private function checkLength(array &$errors, string $value, int $max, string $code): void
    {
        if ($value !== '' && mb_strlen($value) > $max) {
            $errors[] = $code;
        }
    }

    private function isValidHttpUrl(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        $validated = filter_var($trimmed, FILTER_VALIDATE_URL);
        if (!is_string($validated)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($validated, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}

