# MySQL Ops Checklist

## Observabilite minimale

- Logger les erreurs SQL applicatives avec contexte endpoint et identifiant de requete.
- Suivre la latence des routes critiques: `/graphql`, `/api/v1/articles`, `/api/v1/pages/{slug}`, checkout.
- Surveiller connexions actives, temps de lock et erreurs InnoDB.

## Index et performance

- Verifier periodiquement l'utilisation des index via `EXPLAIN` sur les requetes les plus lentes.
- Maintenir les index de slugs/statuts/dates (pages, blog_articles, faq_items, legal_pages, orders).
- Controler la croissance des tables d'audit et planifier une retention.

## Sauvegarde et restauration

- Backup quotidien complet + sauvegardes incrementales (binlogs) selon RPO.
- Tester une restauration complete au moins une fois par mois.
- Documenter les identifiants, host, port, version MySQL et charset de la cible.

## Rollback migrations

- Chaque migration SQL doit avoir une procedure de rollback documentee.
- En production, executer migrations hors pic, avec snapshot pre-migration.
- En cas d'echec: rollback transactionnel si possible, sinon restore depuis backup recent.

## Securite

- Utiliser un utilisateur DB a privileges minimaux pour l'application.
- Activer SSL (`DB_SSL_VERIFY=1`, `DB_SSL_CA=...`) en environnements exposes.
- Ne jamais versionner les credentials DB ni tokens d'administration.
