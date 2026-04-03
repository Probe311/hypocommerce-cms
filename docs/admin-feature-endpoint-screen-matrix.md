# Matrice finale feature -> endpoint -> ecran -> statut

| Feature | Endpoint(s) | Ecran UI | Statut |
| --- | --- | --- | --- |
| Dashboard KPIs | `search/orders`, `search/products`, `search/customers`, `crm/segments` | `pages/dashboard.js` | Couvert |
| Commandes recherche | `search/orders` | `pages/orders.js` | Couvert |
| Commandes statut | `orders/status` | `pages/orders.js` | Couvert |
| Commandes shipment | `orders/shipments` | `pages/orders.js` | Couvert |
| Commandes refund | `orders/refunds` | `pages/orders.js` | Couvert |
| Commandes split shipment | `orders/split-shipments` | `pages/orders.js` | Couvert |
| Produits recherche/filtres | `search/products` | `pages/products.js` | Couvert |
| Produits bulk | `products/bulk` | `pages/products.js` | Couvert |
| Produits edition ciblee SEO/trad | `translations/upsert` | `pages/products.js` | Couvert partiel |
| Produits media listing | `products/images/list` | `pages/products.js` | Couvert |
| Produits media upload/delete/reorder | legacy `admin/api/*image*.php` via couche API unifiee | `pages/products.js` | Couvert (fallback legacy) |
| Clients recherche | `search/customers` | `pages/customers.js` | Couvert |
| Segments CRM | `crm/segments` | `pages/customers.js` | Couvert |
| CMS pages | `pages` | `pages/content.js` | Couvert |
| Blog | `blog/articles` | `pages/content.js` | Couvert |
| FAQ | `faq/items` | `pages/content.js` | Couvert |
| Legal | `legal/pages` | `pages/content.js` | Couvert |
| Traductions | `translations/upsert` | `pages/content.js` | Couvert |
| Coupons | `coupons` | `pages/settings.js` | Couvert |
| Plugins list/toggle/config | `plugins/list`, `plugins/toggle`, `plugins/config` | `pages/plugins.js` | Couvert |
| Taxes | `settings/taxes/list`, `settings/taxes/upsert`, `settings/taxes/toggle` | `pages/settings.js` | Couvert |
| Transporteurs | `settings/shipping/list`, `settings/shipping/upsert`, `settings/shipping/toggle` | `pages/settings.js` | Couvert |
| Paiements | `settings/payments/list`, `settings/payments/upsert`, `settings/payments/toggle` | `pages/settings.js` | Couvert |
| Admin diagnostics | journal local API + test connectivite | `pages/settings.js` (Admin Tools) | Couvert |
