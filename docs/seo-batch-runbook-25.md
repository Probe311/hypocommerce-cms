# Runbook SEO - Lots de 25 produits

## Scope
Execution standard pour enrichir un lot de 25 produits avec traçabilite et validation.

## 1) Preparation
- Selectionner 25 produits prioritaires (CA, marge, volume, potentiel SEO).
- Exporter les donnees source dans un fichier de travail.
- Completer les champs manquants du contrat `Product SEO Ready`.

## 2) Enrichissement
- Meta: `seoTitle`, `seoDescription`.
- Schema Product: verifier `sku/price/availability/image/brand`.
- E-E-A-T: renseigner `editorialAuthor`, `editorialReviewer`, `reviewedAt`.

## 3) Mutation backend
- Utiliser les operations bulk admin (`/api/v1/admin/products/bulk`).
- Limiter les updates a 25 produits par execution.
- Conserver un fichier d'entree immutable par lot.

## 4) QA lot
- Executer validations JSON-LD (Product, ItemList, Breadcrumb).
- Verifier canonical/indexabilite.
- Controler 3 fiches produit + 1 page listing a la main.

## 5) Publication et suivi
- Publier le lot.
- Monitorer 14 jours: impressions, CTR, erreurs rich results.
- Capitaliser les learnings avant lot suivant.

## Definition of Done (lot)
- 25/25 produits conformes au contrat.
- 0 schema invalide critique.
- 0 meta dupliquee dans le lot.
- Rapport lot archive (inputs, outputs, KPI J+14).
