<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

final class SeoSettingsDefaultsApplier
{
    private SeoSinglePolicyMatrix $matrix;

    public function __construct()
    {
        $this->matrix = new SeoSinglePolicyMatrix();
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function apply(array $content, array $settings): array
    {
        $entityType = (string) ($content['entityType'] ?? 'default');
        $policy = $this->matrix->forType($entityType);
        $title = trim((string) ($content['title'] ?? ''));
        $slug = trim((string) ($content['entitySlug'] ?? $content['entityId'] ?? ''));

        $metaTitle = trim((string) ($content['metaTitle'] ?? ''));
        $metaDescription = trim((string) ($content['metaDescription'] ?? ''));
        $generated = [
            'metaTitle' => false,
            'metaDescription' => false,
            'canonicalUrl' => false,
        ];

        if ($metaTitle === '') {
            $template = (string) ($settings['site']['title_template'] ?? '{title} | {site_name}');
            $metaTitle = $this->renderTemplate($template, $title, $entityType, $settings);
            $generated['metaTitle'] = true;
        }
        if ($metaDescription === '') {
            $template = (string) ($settings['site']['meta_description_template'] ?? '{title} - {site_name}');
            $metaDescription = mb_substr($this->renderTemplate($template, $title, $entityType, $settings), 0, 180);
            $generated['metaDescription'] = true;
        }

        $canonical = trim((string) (($content['canonicalUrl'] ?? '')));
        if ($canonical === '' && ($policy['requiresCanonical'] ?? false) === true) {
            $base = rtrim((string) ($settings['site']['canonical_base'] ?? ''), '/');
            if ($base !== '' && $slug !== '') {
                $canonical = $base . '/' . ltrim($slug, '/');
                $generated['canonicalUrl'] = true;
            }
        }

        $trustSignals = is_array($content['trustSignals'] ?? null) ? $content['trustSignals'] : [];
        $trustSignals['generatedMetaTitle'] = $generated['metaTitle'];
        $trustSignals['generatedMetaDescription'] = $generated['metaDescription'];
        $trustSignals['generatedCanonicalUrl'] = $generated['canonicalUrl'];
        $trustSignals['hasCanonical'] = $canonical !== '';
        $trustSignals['schemaType'] = (string) ($policy['schemaType'] ?? 'WebPage');
        $trustSignals['requiresAuthor'] = (bool) ($policy['requiresAuthor'] ?? false);
        $trustSignals['minScoreTarget'] = (int) ($policy['minScore'] ?? 60);

        $content['metaTitle'] = $metaTitle;
        $content['metaDescription'] = $metaDescription;
        $content['canonicalUrl'] = $canonical;
        $content['trustSignals'] = $trustSignals;
        return $content;
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(string $entityType, array $settings): array
    {
        $sample = [
            'entityType' => $entityType,
            'entityId' => 'preview-1',
            'entitySlug' => 'sample-' . $entityType,
            'title' => 'Titre exemple',
            'metaTitle' => '',
            'metaDescription' => '',
            'trustSignals' => [],
        ];
        return $this->apply($sample, $settings);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function renderTemplate(string $template, string $title, string $entityType, array $settings): string
    {
        $siteName = (string) ($settings['site']['site_name'] ?? 'Site');
        return strtr($template, [
            '{title}' => $title !== '' ? $title : ucfirst(str_replace('_', ' ', $entityType)),
            '{site_name}' => $siteName,
            '{entity_type}' => $entityType,
        ]);
    }
}

