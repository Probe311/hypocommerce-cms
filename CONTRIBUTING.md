# Contribuer

Merci pour votre contribution.

## Workflow

- Créez une branche dédiée depuis `main`.
- Faites des commits atomiques et explicites.
- Ouvrez une Pull Request avec contexte, impact et plan de test.

## Standards

- PHP 8.2+, typage strict, PSR-4.
- Préférez des changements petits et testables.
- Ajoutez ou mettez à jour les tests pour toute évolution métier.

## Vérifications locales minimales

```bash
php -l bin/release_check.php
php bin/release_check.php
```

## Sécurité

Ne publiez jamais de secrets (`.env`, clés API, tokens). Pour signaler une faille, suivez `SECURITY.md`.
