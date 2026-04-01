# Nexora CMS

Nexora est un backend e-commerce open source en PHP, conçu comme un CMS headless pour piloter catalogue, contenu et opérations (checkout, paiements, commandes, CRM) via GraphQL et REST.

![Version](https://img.shields.io/badge/version-1.0.0-2563eb)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479a1?logo=mysql&logoColor=white)
![GraphQL](https://img.shields.io/badge/GraphQL-API-e10098?logo=graphql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ed?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-16a34a)
![CI](https://img.shields.io/badge/CI-GitHub%20Actions-2088ff?logo=githubactions&logoColor=white)

---

## About

- **Produit**: CMS e-commerce headless orienté API
- **Positionnement**: léger, modulaire, lisible, prêt pour self-hosting
- **Public cible**: équipes produit/tech qui veulent un backend PHP maîtrisable sans framework monolithique
- **Nom du projet**: Nexora (`Nexora CMS`, `Nexora Core`)

## Fonctionnalités clés

- **CMS**: pages, blog, FAQ, contenus légaux, versions, scheduling, workflow admin
- **E-commerce**: catalogue, variantes, catégories, panier, checkout, coupons, commandes
- **Paiement**: orchestration Stripe/PayPal + webhooks + idempotence
- **Ops**: migrations, backup/restore, preflight prod, smoke tests, release checks
- **Sécurité**: JWT, CSRF admin, rate limit, headers HTTP, audit logs, CI security gates
- **Extensibilité**: hooks applicatifs + registre de providers paiement

## Use cases

- Lancer une boutique headless (frontend Next.js, Nuxt, app mobile)
- Centraliser contenu marketing + e-commerce dans une seule API
- Construire une base open source auto-hébergée pour une stack sur mesure

## Architecture (synthèse)

- `src/Domain`: modèle métier
- `src/Application`: cas d’usage/services
- `src/Infrastructure`: HTTP, persistence PDO, paiement, sécurité, logging
- `public`: entrypoints HTTP (`index.php`, webhooks, admin APIs)
- `config` + `migrations`: schéma SQL et évolution DB

Pour plus de détails: `docs/architecture.md`.

## Technologies

### Labels techno

![PHP](https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8+-4479a1?logo=mysql&logoColor=white)
![GraphQL](https://img.shields.io/badge/GraphQL-webonyx/graphql--php-e10098?logo=graphql&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-HTTP_Foundation%20%7C%20Mailer-000000?logo=symfony&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ed?logo=docker&logoColor=white)
![CI](https://img.shields.io/badge/CI-GitHub_Actions-2088ff?logo=githubactions&logoColor=white)

- PHP 8.2+
- MySQL 8+
- GraphQL (`webonyx/graphql-php`)
- Symfony components (`http-foundation`, `mailer`)
- Docker / Docker Compose (dev et prod)
- GitHub Actions (lint, tests, analyse, security scans)

## Dépendances

### Runtime (`require`)

- `ramsey/uuid`
- `vlucas/phpdotenv`
- `webonyx/graphql-php`
- `symfony/http-foundation`
- `symfony/mailer`
- `monolog/monolog`
- `stripe/stripe-php`

### Développement (`require-dev`)

- `phpunit/phpunit`
- `phpstan/phpstan`
- `friendsofphp/php-cs-fixer`

## Scripts utiles

- `composer test`
- `composer test:coverage`
- `composer coverage:check`
- `composer analyse`
- `composer format:check`
- `composer security:audit`
- `composer security:sast`

## Installation rapide (local)

1. Installer les dépendances:
```bash
composer install
```
2. Créer l’environnement local:
```bash
cp .env.example .env
```
3. Configurer la DB et les secrets dans `.env` (fichier local, non versionné).
4. Initialiser la base:
```bash
php bin/migrate.php --create-db
```
5. Lancer l’API:
```bash
php -S localhost:8000 -t public
```

Endpoints principaux:
- GraphQL: `http://localhost:8000/graphql`
- REST API: `http://localhost:8000/api/v1`
- CMS lecture: `/api/v1/pages/{slug}`, `/api/v1/articles`, `/api/v1/faq`, `/api/v1/legal/{slug}`
- CMS admin: `/api/v1/admin/*`

## Docker (dev vs prod)

- **Dev uniquement**:
```bash
docker compose up --build
```
`docker-compose.yml` contient des secrets de démonstration et ne doit pas servir en production.

- **Production**:
```bash
cp .env.prod.example .env.prod
docker compose -f docker-compose.prod.yml up --build -d
```

## Qualité, sécurité, release

- Tests: `phpunit.xml` + coverage gate
- Analyse statique: PHPStan
- Format: PHP CS Fixer
- Security gates CI: audit dépendances, SAST, secret scan, container scan
- Preflight prod:
```bash
php bin/preflight_prod.php
```
- Release check:
```bash
php bin/release_check.php
```

## Opérations

- Migrations incrémentales:
```bash
php bin/migrate_versioned.php
```
- Baseline migrations:
```bash
php bin/migrate_versioned.php --baseline-current
```
- Backup / restore:
  - `php bin/backup_db.php`
  - `php bin/restore_db.php`

## Roadmap courte

- Stabiliser l’écosystème plugins/public API
- Renforcer couverture de tests critiques
- Continuer l’industrialisation sécurité et observabilité

## Open source

- Licence: `LICENSE`
- Contribution: `CONTRIBUTING.md`
- Gouvernance: `GOVERNANCE.md`
- Sécurité: `SECURITY.md`
- Code de conduite: `CODE_OF_CONDUCT.md`
- Changelog: `CHANGELOG.md`
- Roadmap: `ROADMAP.md`

## Documentation

- Architecture: `docs/architecture.md`
- Déploiement: `docs/deployment.md`
- Release process: `docs/release-process.md`
- Extensibilité: `docs/extensibility.md`
- Versioning: `docs/versioning-policy.md`
- Support: `docs/support.md`
- Index docs: `docs/README.md`

