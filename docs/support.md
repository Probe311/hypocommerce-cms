# Support production

## Santé applicative

- Endpoint: `GET /health`
- Vérification locale: `php bin/smoke_test.php`

## Incidents

- Procédure: `docs/runbook-incident.md`
- Journalisation: `var/log/` + logs structurés applicatifs.

## Maintenance

- Sauvegarde DB: `php bin/backup_db.php`
- Restauration DB: `php bin/restore_db.php`
- Contrôles d'index: `php bin/check_db_indexes.php`
