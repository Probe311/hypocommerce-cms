<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$pages = [
    ['slug' => 'accueil', 'title' => "Faconner l'atmosphere", 'template' => 'home', 'meta_title' => 'Accueil - Atelier tactile', 'meta_description' => 'Univers editorial et boutique.'],
    ['slug' => 'boutique', 'title' => 'Boutique', 'template' => 'boutique', 'meta_title' => 'Boutique', 'meta_description' => 'Catalogue produits dynamique.'],
    ['slug' => 'blog', 'title' => 'Journal', 'template' => 'blog', 'meta_title' => 'Journal', 'meta_description' => 'Guides et analyses atelier.'],
    ['slug' => 'contact', 'title' => 'Contact', 'template' => 'contact', 'meta_title' => 'Contact', 'meta_description' => 'Coordonnees et support client.'],
    ['slug' => 'faq', 'title' => 'Questions frequentes', 'template' => 'faq', 'meta_title' => 'FAQ', 'meta_description' => 'Questions frequentes: livraison, paiement, retours.'],
    ['slug' => 'a-propos', 'title' => 'A propos', 'template' => 'generic', 'meta_title' => 'A propos', 'meta_description' => "L'histoire et la philosophie de l'atelier."],
    ['slug' => 'livraison-retours', 'title' => 'Livraison et retours', 'template' => 'generic', 'meta_title' => 'Livraison et retours', 'meta_description' => 'Delais, transporteurs et retours.'],
    ['slug' => 'services', 'title' => 'Services', 'template' => 'generic', 'meta_title' => 'Services', 'meta_description' => "Accompagnement et prestations de l'atelier."],
    ['slug' => 'auteurs', 'title' => 'Auteurs', 'template' => 'generic', 'meta_title' => 'Auteurs', 'meta_description' => 'Equipe editoriale.'],
    ['slug' => 'plan-du-site', 'title' => 'Plan du site', 'template' => 'generic', 'meta_title' => 'Plan du site', 'meta_description' => 'Navigation complete du site.'],
    ['slug' => 'recherche', 'title' => 'Recherche', 'template' => 'generic', 'meta_title' => 'Recherche', 'meta_description' => 'Trouver produits et articles.'],
    ['slug' => 'commande', 'title' => 'Commande', 'template' => 'generic', 'meta_title' => 'Commande', 'meta_description' => 'Etapes de commande.'],
    ['slug' => 'panier', 'title' => 'Panier', 'template' => 'generic', 'meta_title' => 'Panier', 'meta_description' => 'Votre panier en cours.'],
    ['slug' => 'compte', 'title' => 'Compte', 'template' => 'generic', 'meta_title' => 'Compte', 'meta_description' => 'Espace client et historique.'],
    ['slug' => 'configurateur-socles', 'title' => 'Configurateur de socles', 'template' => 'generic', 'meta_title' => 'Configurateur de socles', 'meta_description' => 'Creer un socle personnalise.'],
];

$faqItems = [
    ['question' => 'Quels sont les delais de livraison ?', 'answer' => 'Une estimation est affichee au panier avant validation.', 'order_index' => 1],
    ['question' => 'Puis-je retourner un article ?', 'answer' => 'Oui, selon les conditions legales de retractation.', 'order_index' => 2],
    ['question' => 'Les prix incluent-ils la TVA ?', 'answer' => 'Les prix affiches sont TTC sauf mention contraire.', 'order_index' => 3],
];

$legalPages = [
    [
        'slug' => 'mentions-legales',
        'title' => 'Mentions legales',
        'paragraphs' => [
            'Editeur du site : Societe Exemple SARL.',
            'Directeur de publication : Nom Prenom.',
            'Contact : contact@example.com',
        ],
    ],
    [
        'slug' => 'cgv',
        'title' => 'Conditions generales de vente',
        'paragraphs' => [
            'Les presentes CGV regissent les ventes conclues sur la boutique en ligne.',
            'Prix TTC, modalites de paiement et delais de livraison indiques a la commande.',
            'Droit de retractation : 14 jours sous conditions.',
        ],
    ],
    [
        'slug' => 'confidentialite',
        'title' => 'Politique de confidentialite',
        'paragraphs' => [
            'Donnees collectees : identification, commandes, navigation.',
            'Finalites : traitement des commandes, support client, amelioration du service.',
            'Droits RGPD : acces, rectification, effacement.',
        ],
    ],
];

$blogCategories = [
    ['slug' => 'guides', 'name' => 'Guides'],
    ['slug' => 'atelier', 'name' => 'Atelier'],
    ['slug' => 'lore', 'name' => 'Univers'],
    ['slug' => 'showcase', 'name' => 'Vitrine'],
    ['slug' => 'news', 'name' => 'Actualites'],
];

$blogArticles = [
    [
        'category_slug' => 'guides',
        'slug' => 'choisir-mobilier-durable',
        'title' => 'Comment choisir un mobilier durable',
        'excerpt' => 'Les criteres matiere, finition et cycle de vie pour un achat eclaire.',
        'body' => "Le mobilier durable combine esthetique et empreinte maitrisee.\n\nPrivilegiez des essences certifiees et des pieces reparables.",
        'author_name' => 'Claire Martin',
        'author_job_title' => 'Responsable contenu',
    ],
    [
        'category_slug' => 'atelier',
        'slug' => 'eclairer-son-interieur',
        'title' => 'Eclairer son interieur sans gaspiller',
        'excerpt' => 'Temperature de couleur, flux lumineux et points d accent pour un rendu equilibre.',
        'body' => "Une bonne strategie lumineuse combine lumiere generale, d appoint et d accentuation.\n\nPensez aux variateurs et aux zones de circulation.",
        'author_name' => 'Claire Martin',
        'author_job_title' => 'Responsable contenu',
    ],
];

try {
    if ($dryRun) {
        echo "Dry-run: aucune ecriture en base.\n";
        echo 'pages=' . count($pages) . "\n";
        echo 'faq_items=' . count($faqItems) . "\n";
        echo 'legal_pages=' . count($legalPages) . "\n";
        echo 'blog_articles=' . count($blogArticles) . "\n";
        exit(0);
    }

    $pdo = pdoFromArgv($argv);

    $pdo->beginTransaction();

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DELETE FROM cms_page_sections');
    $pdo->exec('DELETE FROM cms_pages');
    $pdo->exec('DELETE FROM faq_items');
    $pdo->exec('DELETE FROM faq_categories');
    $pdo->exec('DELETE FROM blog_article_blocks');
    $pdo->exec('DELETE FROM blog_articles');
    $pdo->exec('DELETE FROM blog_categories');
    $pdo->exec('DELETE FROM legal_pages');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $insertPage = $pdo->prepare(
        'INSERT INTO cms_pages (slug, title, template, status, meta_title, meta_description, published_at, created_at, updated_at)
         VALUES (:slug, :title, :template, :status, :meta_title, :meta_description, :published_at, :created_at, :updated_at)'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO cms_page_sections (page_id, section_key, section_type, order_index, payload, created_at, updated_at)
         VALUES (:page_id, :section_key, :section_type, :order_index, :payload, :created_at, :updated_at)'
    );

    foreach ($pages as $page) {
        $insertPage->execute([
            'slug' => $page['slug'],
            'title' => $page['title'],
            'template' => $page['template'],
            'status' => 'published',
            'meta_title' => $page['meta_title'],
            'meta_description' => $page['meta_description'],
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pageId = (int) $pdo->lastInsertId();

        $hero = [
            'title' => $page['title'],
            'subtitle' => $page['meta_description'],
        ];
        $insertSection->execute([
            'page_id' => $pageId,
            'section_key' => 'hero',
            'section_type' => 'hero',
            'order_index' => 1,
            'payload' => json_encode($hero, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $insertFaqCategory = $pdo->prepare(
        'INSERT INTO faq_categories (slug, name, created_at, updated_at) VALUES (:slug, :name, :created_at, :updated_at)'
    );
    $insertFaqCategory->execute(['slug' => 'general', 'name' => 'General', 'created_at' => $now, 'updated_at' => $now]);
    $faqCategoryId = (int) $pdo->lastInsertId();
    $insertFaq = $pdo->prepare(
        'INSERT INTO faq_items (category_id, question, answer, order_index, status, created_at, updated_at)
         VALUES (:category_id, :question, :answer, :order_index, :status, :created_at, :updated_at)'
    );
    foreach ($faqItems as $item) {
        $insertFaq->execute([
            'category_id' => $faqCategoryId,
            'question' => $item['question'],
            'answer' => $item['answer'],
            'order_index' => $item['order_index'],
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $insertLegal = $pdo->prepare(
        'INSERT INTO legal_pages (slug, title, paragraphs, version, status, published_at, created_at, updated_at)
         VALUES (:slug, :title, :paragraphs, :version, :status, :published_at, :created_at, :updated_at)'
    );
    foreach ($legalPages as $page) {
        $insertLegal->execute([
            'slug' => $page['slug'],
            'title' => $page['title'],
            'paragraphs' => json_encode($page['paragraphs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'version' => 1,
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $insertBlogCategory = $pdo->prepare(
        'INSERT INTO blog_categories (slug, name, description, created_at, updated_at)
         VALUES (:slug, :name, NULL, :created_at, :updated_at)'
    );
    $categoryIds = [];
    foreach ($blogCategories as $category) {
        $insertBlogCategory->execute([
            'slug' => $category['slug'],
            'name' => $category['name'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryIds[$category['slug']] = (int) $pdo->lastInsertId();
    }

    $insertArticle = $pdo->prepare(
        'INSERT INTO blog_articles
         (category_id, slug, title, excerpt, body, author_name, author_job_title, status, meta_title, meta_description, published_at, created_at, updated_at)
         VALUES
         (:category_id, :slug, :title, :excerpt, :body, :author_name, :author_job_title, :status, :meta_title, :meta_description, :published_at, :created_at, :updated_at)'
    );
    foreach ($blogArticles as $article) {
        $categoryId = $categoryIds[$article['category_slug']] ?? null;
        if ($categoryId === null) {
            continue;
        }
        $insertArticle->execute([
            'category_id' => $categoryId,
            'slug' => $article['slug'],
            'title' => $article['title'],
            'excerpt' => $article['excerpt'],
            'body' => $article['body'],
            'author_name' => $article['author_name'],
            'author_job_title' => $article['author_job_title'],
            'status' => 'published',
            'meta_title' => $article['title'],
            'meta_description' => $article['excerpt'],
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $pdo->commit();

    echo "CMS backfill termine.\n";
    echo 'pages=' . count($pages) . "\n";
    echo 'faq_items=' . count($faqItems) . "\n";
    echo 'legal_pages=' . count($legalPages) . "\n";
    echo 'blog_articles=' . count($blogArticles) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Backfill echec: ' . $e->getMessage() . "\n");
    exit(1);
}
