<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

final class SeoSinglePolicyMatrix
{
    /**
     * @return array<string,mixed>
     */
    public function forType(string $entityType): array
    {
        $map = $this->all();
        return $map[$entityType] ?? $map['default'];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return [
            'product' => ['minScore' => 70, 'schemaType' => 'Product', 'requiresAuthor' => false, 'requiresCanonical' => true],
            'category' => ['minScore' => 68, 'schemaType' => 'CollectionPage', 'requiresAuthor' => false, 'requiresCanonical' => true],
            'cms_page' => ['minScore' => 65, 'schemaType' => 'WebPage', 'requiresAuthor' => false, 'requiresCanonical' => true],
            'blog_article' => ['minScore' => 72, 'schemaType' => 'Article', 'requiresAuthor' => true, 'requiresCanonical' => true],
            'faq_item' => ['minScore' => 64, 'schemaType' => 'FAQPage', 'requiresAuthor' => false, 'requiresCanonical' => true],
            'legal_page' => ['minScore' => 62, 'schemaType' => 'WebPage', 'requiresAuthor' => false, 'requiresCanonical' => true],
            'default' => ['minScore' => 60, 'schemaType' => 'WebPage', 'requiresAuthor' => false, 'requiresCanonical' => true],
        ];
    }
}

