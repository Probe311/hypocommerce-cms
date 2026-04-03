# Backend Evolution - SEO Produits

## But
Permettre un enrichissement SEO fiable a grande echelle (lots de 25) avec champs techniques et editorialises.

## Evolutions implementees
- Ajout colonnes produit:
  - `gtin`
  - `mpn`
  - `editorial_author`
  - `editorial_reviewer`
  - `reviewed_at`
- Exposition des champs dans le repository catalogue public.
- Support des champs dans les mutations/admin APIs (`adminUpsertProduct` en GraphQL et `POST /api/v1/admin/products/bulk` en REST).

## Evolutions recommandees suivantes
1. Ajouter une table d'avis reelle (reviews) avant tout `aggregateRating`.
2. Ajouter un journal d'execution batch (`seo_batch_runs`, `seo_batch_items`).
3. Ajouter un endpoint de validation pre-publication par lot.
4. Ajouter contraintes DB sur formats GTIN/MPN selon besoins metier.
