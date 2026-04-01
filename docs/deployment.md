# Déploiement

## Prérequis

- PHP 8.2+
- MySQL 8+
- Extensions PHP usuelles (`pdo_mysql`, `openssl`, `json`)

## Variables d'environnement minimales

- `APP_ENV=prod`
- `APP_DEBUG=0`
- `APP_URL`
- `APP_SECRET`
- `JWT_SECRET`
- `ADMIN_API_TOKEN`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- clés paiement selon provider

## Étapes

1. `composer install --no-dev --optimize-autoloader`
2. Copier `.env.example` vers `.env` et configurer les secrets.
3. Exécuter les migrations:
   - `php bin/migrate.php --create-db` (premier setup)
   - `php bin/migrate_versioned.php`
4. Vérifier l'environnement:
   - `php bin/preflight_prod.php`
5. Exposer `public/` via serveur HTTP.

## Démarrage via Docker Compose

```bash
docker compose up --build
```

Application disponible sur `http://localhost:8000`.

## Séparation dev / prod (obligatoire)

- `docker-compose.yml` est **dev-only** (debug actif, secrets faibles).
- Pour production, utilisez `docker-compose.prod.yml` + `.env.prod` (voir `.env.prod.example`).
- Ne jamais exposer les endpoints admin sans filtrage réseau.
- Utiliser un reverse proxy TLS (Nginx/Traefik/Caddy) avec HSTS activé.
- Exécuter le conteneur applicatif en utilisateur non-root.
- Activer les contraintes runtime (`read_only`, `no-new-privileges`, `cap_drop`).

Exemple:

```bash
cp .env.prod.example .env.prod
docker compose -f docker-compose.prod.yml up --build -d
```

## Durcissement conseillé

- Limiter l'accès réseau aux endpoints admin.
- Forcer HTTPS et rotation régulière des secrets.
- Activer sauvegardes DB + tests de restauration (`bin/backup_db.php`, `bin/restore_db.php`).
- Activer HSTS et headers de sécurité au niveau reverse proxy.
