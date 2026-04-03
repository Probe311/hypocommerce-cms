# Architecture backend

Le backend suit une séparation en couches:

- `Domain`: objets métier et invariants.
- `Application`: orchestration des cas d'usage.
- `Infrastructure`: HTTP, persistance PDO, paiements, logging.

## Flux principal checkout

1. Le frontend appelle soit GraphQL (`/graphql`), soit les routes REST storefront (`/checkout/quote`, `/checkout/orders`, `/payments/*`).
2. `SchemaFactory` route vers les services applicatifs.
3. `CartService` et `PdoOrderRepository` créent la commande.
4. `PaymentOrchestrator` délègue au provider (`stripe` ou `paypal`) via un registre.
5. Les webhooks mettent à jour le statut de commande.

## CMS admin

- Endpoint: `/api/v1/admin/*`
- Contrôleur: `CmsAdminController`
- Auth: Bearer JWT admin (ou token admin legacy)
- CSRF: header `X-CSRF-Token` requis sur mutations admin (token fourni au login admin)
- Audit: écritures tracées dans `audit_logs`
- Hooks d'extension: `cms.admin.before_mutation`, `cms.admin.after_mutation`
