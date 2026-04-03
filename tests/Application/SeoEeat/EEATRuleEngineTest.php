<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use App\Application\SeoEeat\EEATRuleEngine;
use PHPUnit\Framework\TestCase;

final class EEATRuleEngineTest extends TestCase
{
    public function testScoresRichContentWithGoodGrade(): void
    {
        $body = str_repeat('Contenu pertinent et détaillé. ', 80);
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'blog_article',
            'title' => 'Guide complet produit',
            'metaTitle' => 'Guide complet produit pour bien choisir en 2026',
            'metaDescription' => 'Analyse détaillée des critères de choix, des usages concrets et des points de confiance avant achat.',
            'body' => $body,
            'author' => 'Equipe éditoriale',
            'trustSignals' => ['hasAuthor' => true, 'hasMeta' => true, 'hasFreshness' => true],
        ]);

        self::assertGreaterThanOrEqual(55, (float) $result['scoreGlobal']);
        self::assertContains((string) $result['grade'], ['A', 'B', 'C']);
        self::assertIsArray($result['signals']);
    }

    public function testScoresThinContentWithLowGrade(): void
    {
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'product',
            'title' => '',
            'metaTitle' => '',
            'metaDescription' => '',
            'body' => 'court',
            'author' => '',
            'trustSignals' => ['hasAuthor' => false, 'hasMeta' => false, 'hasFreshness' => false],
        ]);

        self::assertLessThan(55, (float) $result['scoreGlobal']);
        self::assertSame('D', (string) $result['grade']);
        self::assertGreaterThan(0, (int) $result['blockersCount']);
    }

    public function testTemplateCoverageSignalIsProvidedForProduct(): void
    {
        $body = 'Pack prioritaire pour gagner du temps de mise en peinture avec une logique orientee resultat visible rapidement.';
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'product',
            'title' => 'Produit test',
            'metaTitle' => 'Meta title de test pour un produit complet',
            'metaDescription' => 'Meta description de test avec une longueur suffisamment riche pour rentrer dans les bornes.',
            'body' => $body,
            'author' => '',
            'trustSignals' => ['hasAuthor' => false, 'hasMeta' => true, 'hasFreshness' => true],
        ]);

        self::assertArrayHasKey('template_hint_coverage', $result['signals']);
    }

    public function testDuplicateSignalsAffectScoring(): void
    {
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'blog_article',
            'title' => 'Titre duplique',
            'metaTitle' => 'Meta title dupliquee pour test',
            'metaDescription' => 'Description meta suffisamment longue pour valider les bornes minimales de test unitaire.',
            'body' => str_repeat('Contenu riche ', 60),
            'author' => 'Auteur',
            'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'trustSignals' => [
                'hasAuthor' => true,
                'hasMeta' => true,
                'hasFreshness' => true,
                'duplicateTitle' => true,
                'duplicateMetaTitle' => true,
            ],
        ]);

        self::assertSame('yes', (string) ($result['signals']['duplicate_title'] ?? ''));
        self::assertSame('yes', (string) ($result['signals']['duplicate_meta_title'] ?? ''));
    }

    public function testAddsKeywordAndReadabilitySignals(): void
    {
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'cms_page',
            'title' => 'Guide peinture figurines debutant',
            'metaTitle' => 'Guide peinture figurines debutant rapide',
            'metaDescription' => 'Guide complet pour debutant avec étapes claires, erreurs fréquentes et conseils pratiques.',
            'body' => 'Ce guide peinture figurines debutant propose des etapes courtes. Vous progressez vite. Les conseils sont concrets.',
            'author' => 'Equipe',
            'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'trustSignals' => ['hasAuthor' => true, 'hasMeta' => true, 'hasFreshness' => true],
        ]);

        self::assertArrayHasKey('keyword_coverage', $result['signals']);
        self::assertArrayHasKey('readability', $result['signals']);
    }

    public function testIncludesGeneratedSourceAndPolicySignals(): void
    {
        $result = (new EEATRuleEngine())->score([
            'entityType' => 'blog_article',
            'title' => 'Titre test',
            'metaTitle' => 'Meta title de longueur valide pour test',
            'metaDescription' => 'Description meta valide avec une longueur suffisamment importante pour respecter les contraintes.',
            'body' => str_repeat('Phrase courte. ', 40),
            'author' => '',
            'trustSignals' => [
                'hasAuthor' => false,
                'hasMeta' => true,
                'hasFreshness' => true,
                'generatedMetaTitle' => true,
                'generatedMetaDescription' => false,
                'hasCanonical' => false,
                'requiresAuthor' => true,
                'minScoreTarget' => 70,
                'schemaType' => 'Article',
            ],
        ]);

        self::assertSame('generated', (string) ($result['signals']['meta_title_source'] ?? ''));
        self::assertSame('explicit', (string) ($result['signals']['meta_description_source'] ?? ''));
        self::assertSame('missing', (string) ($result['signals']['canonical'] ?? ''));
        self::assertSame('yes', (string) ($result['signals']['policy_missing'] ?? ''));
    }
}
