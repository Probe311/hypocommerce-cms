# Processus de release

## Avant release

1. Mettre à jour `CHANGELOG.md`.
2. Vérifier les migrations SQL.
3. Exécuter les contrôles:
   - `php bin/release_check.php`
   - `php bin/preflight_prod.php`
   - `php bin/smoke_test.php`
4. Vérifier le statut CI sur la branche:
   - `lint`, `test`, `analyse`, `security` au vert.
5. Vérifier les quality/security gates:
   - couverture >= seuil (`composer coverage:check`),
   - audit dépendances sans alerte critique,
   - scan secrets et scan container sans findings bloquants.

## CI

La CI GitHub Actions exécute:

- installation dépendances,
- lint et format,
- tests PHPUnit + seuil de couverture,
- analyse statique,
- audit dépendances + SAST + scans secrets/container.

## Rollback

- Restaurer la base via backup.
- Revenir au tag précédent côté application.
- Vérifier la santé avec `/health` et smoke tests.
