<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

final class RecommendationEngine
{
    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $score
     * @return array<int,array<string,string>>
     */
    public function build(array $content, array $score): array
    {
        $recommendations = [];
        $signals = is_array($score['signals'] ?? null) ? $score['signals'] : [];
        $entityType = (string) ($content['entityType'] ?? '');
        $title = (string) ($content['title'] ?? '');
        $author = (string) ($content['author'] ?? '');
        $globalScore = (float) ($score['scoreGlobal'] ?? 0.0);
        $trustSignals = is_array($content['trustSignals'] ?? null) ? $content['trustSignals'] : [];

        if ($title === '') {
            $recommendations[] = $this->make(
                'missing_title',
                'critical',
                'high',
                'low',
                'Le contenu n’a pas de titre principal.',
                'Ajouter un titre H1 unique aligné à l’intention de recherche.'
            );
        }
        if (($signals['meta_title'] ?? '') !== 'optimal') {
            $recommendations[] = $this->make(
                'meta_title_quality',
                'high',
                'high',
                'low',
                'Le meta title est absent ou hors plage optimale.',
                'Rédiger un meta title entre 20 et 65 caractères avec mot-clé principal.'
            );
        }
        if (($signals['meta_description'] ?? '') !== 'optimal') {
            $recommendations[] = $this->make(
                'meta_description_quality',
                'high',
                'high',
                'low',
                'La meta description est absente ou hors plage optimale.',
                'Rédiger une meta description entre 70 et 180 caractères avec proposition de valeur claire.'
            );
        }
        if (($signals['content_depth'] ?? '') === 'low') {
            $recommendations[] = $this->make(
                'thin_content',
                'critical',
                'high',
                'medium',
                'Le contenu est trop court pour répondre à l’intention utilisateur.',
                'Enrichir avec sections FAQ, preuves concrètes, comparatifs et informations utiles.'
            );
        }
        if ($author === '') {
            $recommendations[] = $this->make(
                'missing_author',
                'medium',
                'medium',
                'low',
                'Aucun auteur n’est renseigné pour ce contenu.',
                'Ajouter auteur, rôle et bio courte pour renforcer expertise et confiance.'
            );
        }
        if (($signals['freshness'] ?? '') === 'missing') {
            $recommendations[] = $this->make(
                'freshness_signal',
                'medium',
                'medium',
                'low',
                'Signal de fraîcheur insuffisant sur ce contenu.',
                'Programmer une revue éditoriale et mettre à jour la date de révision.'
            );
        }
        if (($signals['stale_content'] ?? '') === 'yes') {
            $recommendations[] = $this->make(
                'stale_content',
                'high',
                'high',
                'medium',
                'Le contenu est ancien et risque une perte de pertinence SEO.',
                'Mettre à jour les données, exemples et sections clés puis republier.'
            );
        }
        if (($signals['duplicate_title'] ?? '') === 'yes') {
            $recommendations[] = $this->make(
                'duplicate_title',
                'high',
                'high',
                'low',
                'Le titre semble dupliqué avec d’autres contenus.',
                'Rendre le H1 unique avec un angle spécifique et une intention distincte.'
            );
        }
        if (($signals['duplicate_meta_title'] ?? '') === 'yes') {
            $recommendations[] = $this->make(
                'duplicate_meta_title',
                'high',
                'high',
                'low',
                'Le meta title semble dupliqué.',
                'Créer un meta title unique par page pour limiter la cannibalisation SEO.'
            );
        }
        if (($signals['internal_links'] ?? '') === 'missing') {
            $recommendations[] = $this->make(
                'internal_linking',
                'medium',
                'medium',
                'low',
                'Le maillage interne est insuffisant.',
                'Ajouter 2 à 5 liens internes vers pages complémentaires à forte intention.'
            );
        }
        if (($signals['keyword_coverage'] ?? '') === 'low') {
            $recommendations[] = $this->make(
                'keyword_coverage',
                'medium',
                'medium',
                'low',
                'La couverture des termes clés principaux est faible.',
                'Renforcer les sections clés avec les termes importants du sujet sans sur-optimisation.'
            );
        }
        if (($signals['readability'] ?? '') === 'low') {
            $recommendations[] = $this->make(
                'readability',
                'medium',
                'medium',
                'low',
                'Le texte est difficile à lire (phrases trop longues).',
                'Raccourcir les phrases, ajouter intertitres et listes pour fluidifier la lecture.'
            );
        }
        if (($signals['canonical'] ?? '') === 'missing') {
            $recommendations[] = $this->make(
                'missing_canonical',
                'high',
                'high',
                'low',
                'Aucune URL canonique n’est définie pour ce contenu.',
                'Définir une canonical stable pour éviter la duplication technique.'
            );
        }
        if (($signals['policy_missing'] ?? '') === 'yes') {
            $recommendations[] = $this->make(
                'single_policy_gap',
                'high',
                'high',
                'medium',
                'Le contenu ne respecte pas la matrice minimale SEO/EEAT de son type.',
                'Compléter les champs obligatoires du type de single puis recalculer le score.'
            );
        }
        if (($signals['below_min_target'] ?? '') === 'yes') {
            $recommendations[] = $this->make(
                'below_min_score_target',
                'medium',
                'high',
                'medium',
                'Le score global est sous le seuil minimum défini pour ce type de single.',
                'Traiter en priorité les recommandations critiques/hautes pour repasser au-dessus du seuil.'
            );
        }
        if ($entityType === 'product' && ($signals['faq_richness'] ?? '') !== 'good') {
            $recommendations[] = $this->make(
                'product_faq_missing',
                'medium',
                'medium',
                'low',
                'La fiche produit manque de FAQ orientée conversion/réassurance.',
                'Ajouter une mini-FAQ sur compatibilité, usage, livraison et garanties.'
            );
        }
        if ($globalScore < 45) {
            $recommendations[] = $this->make(
                'critical_rework',
                'critical',
                'high',
                'high',
                'Le score global est très faible et nécessite une refonte éditoriale.',
                'Reprendre structure Hn, angle EEAT, preuves, maillage, metadata et authoring.'
            );
        }
        if (($trustSignals['businessCritical'] ?? false) === true && $globalScore < 65) {
            $recommendations[] = $this->make(
                'business_priority',
                'critical',
                'high',
                'medium',
                'Contenu business-critique sous le seuil de performance SEO.',
                'Prioriser ce contenu dans le sprint SEO courant (P1) avec optimisation complète.'
            );
        }

        return $recommendations;
    }

    /**
     * @return array<string,string>
     */
    private function make(string $ruleCode, string $severity, string $impact, string $effort, string $message, string $fix): array
    {
        return [
            'ruleCode' => $ruleCode,
            'severity' => $severity,
            'impact' => $impact,
            'effort' => $effort,
            'message' => $message,
            'fixSuggestion' => $fix,
        ];
    }
}
