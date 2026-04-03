# Hypocommerce CMS

Backend e-commerce headless open-source en PHP 8.2+.
Hypocommerce CMS fournit une API REST + GraphQL pour catalogue, CMS, checkout, paiements et opérations admin.
Version cible de cette release: `1.1.0`.

![Version](https://img.shields.io/badge/version-1.1.0-2563eb)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479a1?logo=mysql&logoColor=white)
![GraphQL](https://img.shields.io/badge/GraphQL-API-e10098?logo=graphql&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-16a34a)
![CI](https://img.shields.io/badge/CI-GitHub%20Actions-2088ff?logo=githubactions&logoColor=white)

## Sommaire

- [Fonctionnalités](#fonctionnalites)
- [Architecture](#architecture)
- [Quickstart (5 min)](#quickstart-5-min)
- [API](#api)
- [Sécurité](#securite)
- [Qualité et validation](#qualite-et-validation)
- [Contribution](#contribution)
- [Release et versioning](#release-et-versioning)
- [Roadmap](#roadmap)
- [Licence et gouvernance](#licence-et-gouvernance)
- [Documentation](#documentation)

## Fonctionnalites

- CMS headless: pages, blog, FAQ, contenus légaux, menus/navigation.
- E-commerce: produits, catégories, panier, checkout, coupons, commandes.
- Paiement: Stripe/PayPal via orchestrateur + webhooks idempotents.
- Admin: endpoints sécurisés (Bearer JWT + CSRF), audit logs, rate limiting.
- Extensibilité: plugins/providers (paiement, shipping) et hooks applicatifs.

## Architecture

- `src/Domain`: modèle métier.
- `src/Application`: cas d’usage.
- `src/Infrastructure`: HTTP, persistance PDO, sécurité, logging, intégrations.
- `public`: front controller HTTP (`public/index.php`).
- `config` + `migrations`: schéma et migrations versionnées.

Référence détaillée: `docs/architecture.md`.

## Quickstart (5 min)

### 1) Prérequis

- PHP `>= 8.2`
- MySQL `>= 8`
- Composer

### 2) Installation

```bash
composer install
cp .env.example .env
```

PowerShell:

```powershell
composer install
Copy-Item .env.example .env
```

### 3) Configuration minimale

Dans `.env`, vérifier au minimum:

- `APP_ENV=dev`
- `APP_URL=http://localhost:8000`
- `APP_VERSION=1.1.0`
- `APP_SECRET` (fort)
- `JWT_SECRET` (fort)
- `DB_*`

### 4) Initialiser la base

```bash
php bin/migrate.php --create-db
php bin/migrate_versioned.php
```

### 5) Lancer l’API

```bash
php -S localhost:8000 -t public
```

### 6) Vérification rapide

Healthcheck:

```bash
curl http://localhost:8000/health
```

GraphQL:

```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -d "{\"query\":\"{ health }\"}"
```

REST:

```bash
curl http://localhost:8000/api/v1/products
```

## API

### Endpoints canoniques

- REST base (catalogue/CMS/admin): `http://localhost:8000/api/v1`
- REST storefront (Next.js): `http://localhost:8000/` (`/auth/*`, `/checkout/*`, `/account/*`, `/payments/*`)
- GraphQL: `http://localhost:8000/graphql`

### Authentification

- **Public**: pas d’auth sur endpoints lecture.
- **Storefront client**:
  - En REST: `Authorization: Bearer <customer_jwt>` sur `GET /account/orders` (tokén issu de `/auth/login` ou `/auth/register`).
  - En GraphQL: argument `customerToken` sur les queries/mutations concernées.
- **Admin REST**: `Authorization: Bearer <admin_jwt>` + `X-CSRF-Token`.
- **GraphQL admin**: argument `adminToken` sur mutations/queries admin.

Le fallback legacy `X-Admin-Token` est désactivé par défaut (`ALLOW_LEGACY_ADMIN_TOKEN=0`).

### Exemples

Liste produits:

```bash
curl http://localhost:8000/api/v1/products
```

Mutation GraphQL checkout:

```bash
curl -X POST http://localhost:8000/graphql \
  -H "Content-Type: application/json" \
  -d "{\"query\":\"mutation($sid:String!,$email:String!,$phone:String!,$fn:String!,$ln:String!,$sl1:String!,$sc:String!,$sp:String!,$sco:String!,$bl1:String!,$bc:String!,$bp:String!,$bco:String!,$pm:String!){ checkout(sessionId:$sid,contactEmail:$email,contactPhone:$phone,contactFirstName:$fn,contactLastName:$ln,shippingLine1:$sl1,shippingCity:$sc,shippingPostcode:$sp,shippingCountry:$sco,billingLine1:$bl1,billingCity:$bc,billingPostcode:$bp,billingCountry:$bco,paymentMethod:$pm){ id number status }}\",\"variables\":{\"sid\":\"demo-session\",\"email\":\"demo@example.com\",\"phone\":\"0102030405\",\"fn\":\"Demo\",\"ln\":\"User\",\"sl1\":\"1 rue test\",\"sc\":\"Paris\",\"sp\":\"75001\",\"sco\":\"FR\",\"bl1\":\"1 rue test\",\"bc\":\"Paris\",\"bp\":\"75001\",\"bco\":\"FR\",\"pm\":\"stripe\"}}"
```

Schéma complet: `docs/graphql-schema.md`.

### REST storefront (extraits)

Enregistrer un client:

```bash
curl -X POST http://localhost:8000/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@example.com","password":"Passw0rd!123"}'
```

Connexion client:

```bash
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@example.com","password":"Passw0rd!123"}'
```

Quotation panier:

```bash
curl -X POST http://localhost:8000/checkout/quote \
  -H "Content-Type: application/json" \
  -d '{"sessionId":"demo-session"}'
```

Création d’une commande (sans démarrer la session de paiement):

```bash
curl -X POST http://localhost:8000/checkout/orders \
  -H "Content-Type: application/json" \
  -d '{"sessionId":"demo-session","paymentMethod":"stripe"}'
```

## Securite

- Headers HTTP de sécurité appliqués au niveau kernel (CSP, XFO, HSTS sur HTTPS).
- JWT signé HMAC (`HS256`) avec vérifications strictes.
- CSRF requis sur endpoints admin mutation.
- Webhooks paiement vérifiés + idempotence.
- Endpoint `/health`: détails d’erreur DB masqués en production.

### Variables critiques

- `APP_SECRET`
- `JWT_SECRET`
- `APP_VERSION`
- `ADMIN_UI_REVIEW_ALLOWED` (laisser `0` en production)
- `ALLOW_LEGACY_ADMIN_TOKEN` (laisser `0` en production)

## Configuration CMS et plugins

- CMS / contenu:
  - Parametres generaux via table `site_settings` (navigation, tracking, SEO global).
  - Medias sous `/uploads/cms/` (stockes dans `var/uploads` cote backend).
- Plugins:
  - Paiement: Stripe/PayPal activables via admin (`/admin` > Settings > Payments).
  - Shipping: carriers activables via admin (`/admin` > Settings > Shipping).
  - Plugins generiques: liste et activation via `/admin` > Plugins.

Pour des besoins specifiques projet, preferer l'ajout de plugins ou de hooks (`docs/extensibility.md`) plutot que des forks lourds du core.

Politique sécurité: `SECURITY.md`.

## Qualite et validation

Commandes principales:

```bash
composer test
composer analyse
composer format:check
composer security:audit
php bin/preflight_prod.php
php bin/release_check.php
```

Migrations:

```bash
php bin/migrate_versioned.php
php bin/migrate_versioned.php --baseline-current
```

## Contribution

Avant PR, exécuter au minimum:

```bash
composer test
composer analyse
composer format:check
composer security:audit
```

Références:

- `CONTRIBUTING.md`
- `CODE_OF_CONDUCT.md`
- `GOVERNANCE.md`

## Release et versioning

- SemVer: `docs/versioning-policy.md`
- Changelog: `CHANGELOG.md`
- Process release: `docs/release-process.md`
- Valeur runtime exposée: `APP_VERSION` (fichiers `.env*`)

Checklist recommandée avant tag:

1. Tests + analyse + preflight passants.
2. `CHANGELOG.md` à jour.
3. `APP_VERSION` alignée avec la release.
4. Smoke API (`/health`, REST clé, GraphQL clé).

## Roadmap

- Stabilisation API publique et plugins.
- Renforcement couverture tests critiques.
- Observabilité et sécurité opérationnelle.

Détail: `ROADMAP.md`.

## Licence et gouvernance

- Licence: `LICENSE`
- Sécurité: `SECURITY.md`
- Gouvernance: `GOVERNANCE.md`

## Documentation

- Index: `docs/README.md`
- Architecture: `docs/architecture.md`
- Déploiement: `docs/deployment.md`
- Extensibilité: `docs/extensibility.md`
- Versioning: `docs/versioning-policy.md`
- Support: `docs/support.md`

