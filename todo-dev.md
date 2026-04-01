# TODO développement backend PHP (CMS e-commerce complet)

Feuille de route backend e-commerce PHP (hors frontend Next), alignée sur un niveau CMS complet type Medusa/WooCommerce.

## Légende
- Priorité: `P0` (critique go-live), `P1` (important post go-live), `P2` (avancé/scale).
- Livraison: `MVP` (go-live), `Phase2` (post go-live).
- Dépendances: prérequis techniques/fonctionnels pour exécuter la tâche sans blocage.
- Statut: `[x]` fait, `[ ]` à faire.

## Domaine A - Fondations techniques & sécurité

- [x] `P0 | MVP | Dépendances: aucune` Finaliser le schéma SQL complet (tables, contraintes, index).
- [x] `P0 | MVP | Dépendances: schéma SQL` Mettre en place les migrations (`config/schema.sql` + migrations versionnées).
- [x] `P0 | MVP | Dépendances: aucune` Ajouter la configuration centralisée (`.env`, paramètres typés app/DB/paiement/mail).
- [x] `P0 | MVP | Dépendances: aucune` Mettre en place les logs applicatifs via Monolog.
- [x] `P0 | MVP | Dépendances: aucune` Centraliser la validation des inputs (DTO + validators) pour endpoints sensibles (checkout, admin). Reste a etendre au futur module auth client/admin.
- [x] `P0 | MVP | Dépendances: config + sécurité` Ajouter l'idempotence pour checkout et webhooks paiement (checkout GraphQL via `idempotencyKey`, webhook Stripe dedoublonne par `event.id`).
- [x] `P1 | Phase2 | Dépendances: auth` Ajouter du rate limiting (login client/admin, reset password, webhooks Stripe/PayPal).
- [x] `P1 | Phase2 | Dépendances: logging` Standardiser les logs structurés (request_id, actor_id, corrélation) via `Kernel` + `StructuredLogger`.
- [x] `P1 | Phase2 | Dépendances: routeur/API` Renforcer `/health` (DB, version, mode, dépendances critiques).
- [x] `P1 | Phase2 | Dépendances: back-office` Ajouter la protection CSRF pour formulaires back-office non GraphQL (header `X-CSRF-Token`, validation sur endpoints admin REST, token renvoyé au login admin).

## Domaine B - Catalogue & contenu CMS

- [x] `P0 | MVP | Dépendances: schéma SQL` Modéliser les entités catalogue (`Product`, `ProductVariant`, `Category`, `Attribute`, `AttributeValue`, `ProductImage`, `RelatedProduct`).
- [x] `P0 | MVP | Dépendances: entités catalogue` Implémenter les repositories PDO catalogue (CRUD).
- [x] `P0 | MVP | Dépendances: entités catalogue` Gérer les types produits (simple, variable, digital, bundle/packs).
- [x] `P0 | MVP | Dépendances: catégories` Gérer l'arborescence catégories / sous-catégories.
- [x] `P0 | MVP | Dépendances: produits` Gérer la SEO produit (slug, meta title, meta description).
- [x] `P1 | MVP | Dépendances: produits` Gérer les relations produits liés / upsell / cross-sell (domaine + persistance).
- [x] `P0 | MVP | Dépendances: API CMS` Exposer la lecture CMS (pages, blog, FAQ, légal).
- [x] `P0 | MVP | Dépendances: sécurité admin` Exposer l'API admin CMS (pages, articles, FAQ, légal) avec token admin.
- [x] `P0 | MVP | Dépendances: stockage fichiers` Créer upload/suppression/reordonnancement des images produit.
- [ ] `P0 | MVP | Dépendances: upload images` Ajouter génération de miniatures + variantes de format.
- [x] `P1 | Phase2 | Dépendances: CMS admin` Implémenter workflow éditorial CMS (draft, review, publish, scheduling, versioning) sur `cms_pages` (statuts étendus, `scheduledAt`, `reviewNote`, table `cms_page_versions`, job `bin/publish_scheduled_cms.php`).
- [x] `P1 | Phase2 | Dépendances: catalogue` Ajouter import massif + édition bulk catalogue (PIM light) via `bin/import_catalog_bulk.php` et endpoint admin `products/bulk`.
- [x] `P2 | Phase2 | Dépendances: CMS + catalogue` Ajouter multi-langue pour contenu CMS et catalogue (tables de traduction + fallback `lang` sur APIs + endpoint admin `translations/upsert`).

## Domaine C - Panier, checkout & paiements

- [x] `P0 | MVP | Dépendances: schéma SQL` Modéliser les entités `Cart`, `CartItem`, `Order`, `OrderItem`, `OrderAddress`, `OrderPayment`, `OrderShipment`.
- [x] `P0 | MVP | Dépendances: entités panier/commande` Implémenter la persistance PDO pour panier et commandes.
- [x] `P0 | MVP | Dépendances: persistance panier` Gérer panier persistant (`cartId`, liaison possible `customer_id`).
- [x] `P0 | MVP | Dépendances: service panier` Gérer le flux panier (add/update/remove/recalcul).
- [x] `P0 | MVP | Dépendances: panier` Implémenter pricing de base (sous-total, TVA, remises, frais de port).
- [x] `P0 | MVP | Dépendances: panier + commande` Créer la commande depuis panier (statut initial `pending`).
- [x] `P0 | MVP | Dépendances: panier + validation` Finaliser checkout complet v1 (adresses billing/shipping, contact, moyen de paiement) via mutation GraphQL `checkout`.
- [x] `P0 | MVP | Dépendances: checkout` Supporter checkout invité (sans compte) avec données de contact en commande (customerId optionnel).
- [x] `P0 | MVP | Dépendances: modèle de commande` Mettre en place machine d'etats commande unifiée (`pending`, `authorized`, `paid`, `fulfilled`, `cancelled`, `refunded`) et l'appliquer sur le passage webhook Stripe vers `paid`.
- [x] `P0 | MVP | Dépendances: commande + provider` Finaliser `StripePaymentProvider` (multi-lignes, devise, metadata, modes) via `createCheckoutSessionAdvanced`.
- [x] `P0 | MVP | Dépendances: provider Stripe + PayPal` Implémenter un orchestrateur de paiement (Stripe/PayPal) basé sur `Order` avec mutation GraphQL `startOrderPayment`.
- [x] `P0 | MVP | Dépendances: orchestrateur paiement` Créer webhook PayPal v1 et aligner callbacks Stripe/PayPal sur la meme logique de statut (transitions + idempotence).
- [x] `P0 | MVP | Dépendances: webhooks` Renforcer webhooks v1 (idempotence + journal des evenements + metadata de retry `attempt_count/next_retry_at`; signature Stripe active, signature PayPal a finaliser).
- [x] `P1 | Phase2 | Dépendances: orchestrateur paiement` Gérer remboursements partiels/total (admin `orders/refunds` + persistance `order_refunds` + reconciliation `bin/reconcile_refunds.php`).
- [x] `P1 | Phase2 | Dépendances: comptes clients` Exposer l'historique commande client via API sécurisée (`customerOrderHistory` avec token client).

## Domaine D - Livraison, stock & logistique

- [x] `P0 | MVP | Dépendances: checkout adresses` Définir modèles livraison v1 (methodes + zones FR/EU/INTL; tranches simplifiees).
- [x] `P0 | MVP | Dépendances: modèles livraison` Implémenter calcul des frais de port v1 (fixe + seuil livraison offerte par zone).
- [x] `P0 | MVP | Dépendances: calcul livraison` Exposer les méthodes de livraison disponibles par panier/adresse (query GraphQL `shippingMethods`).
- [x] `P0 | MVP | Dépendances: commandes` Gérer suivi colis v1 (carrier + tracking_number + statut via admin `orders/shipments`; URL derivee front a ajouter).
- [x] `P1 | MVP | Dépendances: paiement + commandes` Implémenter stock transactionnel (`InventoryItem`, `InventoryMovement`, reservation au checkout, decrement a la confirmation paiement, compensation au refund PayPal).
- [x] `P1 | Phase2 | Dépendances: stock` Ré-incrémenter stock selon politique en cas de remboursement/annulation (Stripe: `charge.refunded` compense, `checkout.session.expired` libere la reservation; PayPal refund compense).
- [x] `P1 | Phase2 | Dépendances: stock` Gérer alertes rupture via CLI (`bin/alert_low_stock.php`) avec option d'envoi email.
- [x] `P1 | Phase2 | Dépendances: checkout + stock` Gérer backorder (`backorder_allowed`) et règles checkout associées (controle sur reservation stock).
- [x] `P2 | Phase2 | Dépendances: fulfillment` Ajouter split shipment / multi-entrepôt (tables `warehouses`, `order_shipment_items`, endpoint admin `orders/split-shipments` + service `SplitShipmentService`).

## Domaine E - Comptes clients & marketing

- [x] `P0 | MVP | Dépendances: schéma SQL` Implémenter `Customer` et `CustomerAddress` (domain models + persistance PDO).
- [x] `P0 | MVP | Dépendances: customer` Implémenter inscription client avec hash bcrypt (`password_hash`) via mutation GraphQL `registerCustomer`.
- [x] `P0 | MVP | Dépendances: customer + sécurité` Implémenter login (JWT) + garde d'auth pour mutations/queries client via token.
- [x] `P0 | MVP | Dépendances: auth` Gérer profil client et CRUD adresses (queries/mutations GraphQL `customerProfile`, `customerAddresses`, `updateCustomerProfile`, `upsertCustomerAddress`, `deleteCustomerAddress`).
- [x] `P0 | MVP | Dépendances: auth + mail` Implémenter mot de passe oublié (token + reset sécurisé). Envoi email a brancher.
- [x] `P0 | MVP | Dépendances: auth` Implémenter roles/permissions (`Customer`, `AdminUser`: `super_admin`, `admin`, `manager`) avec RBAC sur ressources admin.
- [x] `P1 | Phase2 | Dépendances: panier + pricing` Implémenter coupons/codes promo (creation admin `/api/v1/admin/coupons`, validation et application panier via GraphQL `validateCoupon` / `applyCouponToCart` / `checkout.couponCode`).
- [x] `P1 | Phase2 | Dépendances: coupons` Gérer règles de réduction (global, categorie, produit, minimum panier, dates, usages globaux et par client, enregistrement des redemptions).
- [x] `P1 | Phase2 | Dépendances: événements métier` Mettre en place emails transactionnels (compte, commande, paiement, expédition, reset) via `TransactionalEmailService`.
- [x] `P2 | Phase2 | Dépendances: CRM` Ajouter segmentation clients et relance panier abandonné (`customer_segments`, `abandoned_cart_reminders`, jobs `bin/segment_customers.php` et `bin/send_abandoned_cart_reminders.php`, endpoint admin `crm/segments`).
- [x] `P2 | Phase2 | Dépendances: marketing` Implémenter newsletter basique (`newsletter_subscribers` + mutations GraphQL `subscribeNewsletter` / `unsubscribeNewsletter`).

## Domaine F - Back-office & API

- [x] `P0 | MVP | Dépendances: auth admin` Protéger l'accès back-office avec authentification admin robuste (login admin + Bearer JWT + fallback token legacy).
- [ ] `P0 | MVP | Dépendances: API métier` Créer écrans principaux (Dashboard, Produits, Commandes, Clients, Pages, Paramètres).
- [ ] `P0 | MVP | Dépendances: écrans admin` Brancher écrans sur API réelle (REST/GraphQL).
- [x] `P0 | MVP | Dépendances: commandes + livraison` Ajouter gestion statuts commande et suivi colis depuis admin (`orders/shipments` + `orders/status`).
- [x] `P1 | Phase2 | Dépendances: API admin` Ajouter filtres/recherche avancés (produits, commandes, clients) via endpoints admin REST `search/products`, `search/orders`, `search/customers`.
- [x] `P1 | Phase2 | Dépendances: audit` Ajouter audit logs admin enrichis (`qui`, `quand`, `quoi`, IP, avant/après`) avec snapshots `before/after` sur endpoints admin REST.
- [x] `P0 | MVP | Dépendances: schéma GraphQL` Étendre API GraphQL: produits, catégories, panier, checkout, commandes, comptes clients.
- [x] `P0 | MVP | Dépendances: schéma GraphQL` Ajouter queries front Next (catalogue, détail produit, catégories, compte, historique commandes).
- [x] `P0 | MVP | Dépendances: schéma GraphQL` Ajouter mutations: panier, checkout, auth client, profil, adresses.
- [x] `P1 | Phase2 | Dépendances: auth admin` Ajouter mutations admin sécurisées (CRUD produits/catégories/coupons/paramètres) via GraphQL (`adminUpsert*` / `adminDelete*`) avec vérification de rôle.
- [x] `P1 | Phase2 | Dépendances: schéma GraphQL` Documenter le schéma GraphQL (descriptions, champs requis, conventions d'erreurs) via `docs/graphql-schema.md`.

## Domaine G - SEO, qualité, observabilité & conformité

- [x] `P0 | MVP | Dépendances: catalogue + CMS` Finaliser les slugs propres produits/catégories/pages (unicité + canonical) via check CLI `bin/check_slug_canonical.php` + champs `canonicalUrl` dans APIs catalogue/CMS/blog.
- [x] `P0 | MVP | Dépendances: slugs` Compléter le sitemap (produits, catégories, pages CMS, blog).
- [x] `P1 | MVP | Dépendances: API catalogue` Exposer metas dynamiques + données schema.org `Product` dans l'API catalogue (`canonicalUrl`, `seoTitle`, `seoDescription`, `schemaOrgProduct`).
- [x] `P1 | Phase2 | Dépendances: trafic` Mettre en place cache applicatif simple (catégories, pages, produits populaires) via `FileCache` sur catalogue/CMS.
- [x] `P1 | MVP | Dépendances: DB` Vérifier et optimiser index DB (slug, sku, email, status, dates) via migration `006_add_performance_indexes.sql` + check CLI `bin/check_db_indexes.php`.
- [x] `P0 | MVP | Dépendances: CI` Ajouter suite de tests minimum (smoke + integration API + parcours checkout/auth admin). Webhooks: couverture end-to-end restante a automatiser.
- [x] `P1 | Phase2 | Dépendances: exploitation` Ajouter sauvegardes/restauration + runbook incident (`bin/backup_db.php`, `bin/restore_db.php`, `docs/runbook-incident.md`).
- [x] `P1 | Phase2 | Dépendances: conformité` Implémenter RGPD (export/anonymisation via `bin/rgpd_export_customer.php` et `bin/rgpd_anonymize_customer.php`).

## Ordre d'exécution recommandé

### MVP (go-live)
1. Domaine A: validation centralisée, idempotence, sécurité de base.
2. Domaine C: checkout complet + orchestrateur paiements + webhooks robustes.
3. Domaine D: livraison calculée + tracking minimal + stock transactionnel.
4. Domaine E: comptes clients complets (inscription/login/reset/adresses).
5. Domaine F: back-office opérationnel commandes/produits/contenu + GraphQL essentiel.
6. Domaine G: tests d'intégration critiques + sitemap/slugs cohérents.

### Phase2 (post go-live)
1. Promotions avancées, remboursements complets, segmentation marketing.
2. CMS workflow éditorial complet + bulk operations + multi-langue.
3. Logistique avancée (backorder robuste, split shipment, multi-entrepôt).
4. Observabilité renforcée, RGPD complet, performance/cache avancé.

