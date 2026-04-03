# Admin UI autonome (backend)

Ce backend embarque une interface admin autonome servie depuis `public/admin`:

- UI: Preact leger (imports ESM CDN), routing hash (`#/...`)
- API: endpoints backend `/api/v1/admin/*` et `/api/v1/admin/seo/eeat/*`
- Legacy media: fallback `public/admin/api/*` pour upload/suppression/reorder image

## Routes UI

- `#/dashboard`
- `#/orders`
- `#/products`
- `#/customers`
- `#/cms` (et `#/cms/pages|blog|faq|legal|translations`)
- `#/marketing`
- `#/inventory`
- `#/promotions`
- `#/analytics`
- `#/seo`
- `#/settings`
- `#/plugins`

## Build et execution

Aucun framework lourd n'est requis pour executer l'admin:

- Le backend sert directement les fichiers statiques de `public/admin`.
- L'application Preact est chargee en modules ESM depuis `app.js`.

## Session admin

- Auth via `POST /api/v1/admin/auth/login`
- Headers admin:
  - `Authorization: Bearer <token>`
  - `X-CSRF-Token: <csrf>`
- Etat session stocke en `sessionStorage` (`hypocommerce_admin_state`).

## Design system interne

- Composants UI de base centralises dans `public/admin/components/ui.js` (boutons, badges, formulaires, tables, tags d'etat).
- Shell/layout et navigation fournis par `components/preact-shell.js` et `components/nav-config.js`.
- Client HTTP unique dans `services/api.js`:
  - Toutes les actions admin passent par `/api/v1/admin/*` ou `/api/v1/admin/seo/eeat/*`.
  - Les anciens endpoints `public/admin/api/*` sont reserves aux uploads d'images produits (compatibilite).

Les nouvelles pages admin doivent:

- Utiliser les composants de `ui.js` (boutons, inputs, tableaux) au lieu de generer du HTML ad-hoc.
- Utiliser `api.*` pour appeler le backend (et non `fetch` direct).


## Note de convergence

L'ancienne UI admin frontend a ete retiree de `frontend/src` pour eviter la double maintenance.
