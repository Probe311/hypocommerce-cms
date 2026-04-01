# Nexora CMS

Nexora est un CMS e-commerce open source en PHP 8.2, sans framework lourd, avec API GraphQL et endpoints REST pour catalogue, CMS et administration.

## Stack

- PHP 8.2+
- MySQL (compatible o2switch)
- GraphQL via `webonyx/graphql-php`
- Autoload PSR-4 (`App\\` -> `src/`)
- PHPUnit (tests unitaires)

## Démarrage rapide

1. Installer les dépendances :

```bash
composer install
```

2. Copier le fichier d'exemple d'environnement :

```bash
cp .env.example .env
```

3. Configurer la base de données et les clés API dans `.env` (fichier local non versionné).

4. Créer la base si besoin et appliquer le schéma :

```bash
php bin/migrate.php --create-db
```

5. Lancer un serveur de dev PHP :

```bash
php -S localhost:8000 -t public
```

6. Les endpoints seront disponibles sur :
   - GraphQL : `http://localhost:8000/graphql`
   - REST (utilisée par le front) : `http://localhost:8000/api/v1`
   - CMS lecture : `http://localhost:8000/api/v1/pages/{slug}`, `/api/v1/articles`, `/api/v1/faq`, `/api/v1/legal/{slug}`
   - CMS admin (header `X-Admin-Token`) : `/api/v1/admin/pages`, `/api/v1/admin/blog/articles`, `/api/v1/admin/faq/items`, `/api/v1/admin/legal/pages`

## Démarrage Docker

```bash
docker compose up --build
```

Pour production, utiliser `docker-compose.prod.yml` et `.env.prod` (cf. `.env.prod.example`).
Le fichier `docker-compose.yml` est strictement réservé au développement local (secrets de démonstration).

## Migrations versionnées

- Dossier des migrations SQL : `migrations/`
- Exécuter les migrations incrémentales :

```bash
php bin/migrate_versioned.php
```

- Environnement déjà en place (baseline sans exécution SQL) :

```bash
php bin/migrate_versioned.php --baseline-current
```

## Imports et backfill

- Import produits SEO :
```bash
php bin/import_seo_products.php --dry-run
php bin/import_seo_products.php
```

- Import pages SEO :
```bash
php bin/import_seo_pages.php --dry-run
php bin/import_seo_pages.php
```

- Backfill CMS :
```bash
php bin/backfill_cms_content.php --dry-run
php bin/backfill_cms_content.php
```

## Smoke tests backend

```bash
php bin/smoke_test.php
```

## Preflight production

Avant un déploiement, exécuter les vérifications de sécurité/configuration:

```bash
php bin/preflight_prod.php
```

Ce script vérifie notamment:
- variables d'environnement critiques présentes,
- `APP_DEBUG=0`,
- secrets non par défaut (`JWT_SECRET`, `ADMIN_API_TOKEN`),
- connexion MySQL,
- présence des tables e-commerce/CMS et de `schema_migrations`.

## Release check (pipeline local)

```bash
php bin/release_check.php
```

Ce script enchaîne:
- lint PHP des scripts critiques,
- preflight prod,
- smoke test en mode dégradé (`--without-db`) si dépendances applicatives absentes.

## Tests

```bash
vendor/bin/phpunit --configuration phpunit.xml
```

## Structure des dossiers

- `public/` : point d'entrée HTTP (`index.php`), assets back-office
- `src/` : code applicatif (`Domain`, `Application`, `Infrastructure`, `Presentation`)
- `config/` : configuration applicative
- `var/` : logs, cache, fichiers temporaires

## Frontend Next.js

Le frontend Next.js consommera l'API via l'endpoint `/graphql` (POST). Le schéma est pensé pour :

- Catalogue (produits, catégories)
- Panier & checkout
- Comptes clients

## Open source

- Licence: `LICENSE`
- Contribution: `CONTRIBUTING.md`
- Gouvernance: `GOVERNANCE.md`
- Sécurité: `SECURITY.md`
- Code de conduite: `CODE_OF_CONDUCT.md`
- Changelog: `CHANGELOG.md`
- Roadmap: `ROADMAP.md`

## Documentation technique

- Architecture: `docs/architecture.md`
- Déploiement: `docs/deployment.md`
- Release: `docs/release-process.md`
- Extensibilité: `docs/extensibility.md`
- Versioning: `docs/versioning-policy.md`
- Support: `docs/support.md`
- Index docs: `docs/README.md`

