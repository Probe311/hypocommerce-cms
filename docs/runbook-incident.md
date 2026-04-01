# Runbook Incident Backend

## 1) Health check rapide
- `php bin/db_health.php`
- `php bin/preflight_prod.php`
- verifier `/health`

## 2) Collecte de preuves
- logs applicatifs: `var/log/`
- logs structures: `var/log/app-structured.log`
- erreurs webhook: table `webhook_events`

## 3) Mitigation immediate
- desactiver traffic non critique (jobs/imports)
- verifier quotas webhooks et paiement
- passer en mode degrade si DB indisponible

## 4) Sauvegarde / restauration
- sauvegarde: `php bin/backup_db.php`
- restauration: `php bin/restore_db.php <backup.sql>`

## 5) Donnees clients (RGPD)
- export: `php bin/rgpd_export_customer.php <customer_id_or_email>`
- anonymisation: `php bin/rgpd_anonymize_customer.php <customer_id_or_email>`

## 6) Validation retour a la normale
- `php bin/smoke_test.php`
- `php bin/integration_api_test.php`
- verification checkout + paiement + webhooks
