# Uniformisation Catalogue Produits

## Objectif

Uniformiser les produits publiés sur 5 axes:

- titre fournisseur francisé;
- catégorie principale;
- sous-catégorie;
- tags normalisés;
- couleur dominante.

## Pipeline backend

Le script batch est `backend/bin/uniformize_catalog_products.php`.

Il applique:

1. normalisation du titre (`normalized_name_fr`);
2. classification catégorie/sous-catégorie;
3. mise à jour des pivots catégorie/tag;
4. création des catégories/tags manquants;
5. mise à jour de la traduction `fr` dans `product_translations`.

## Schéma

La migration `025_catalog_product_normalization.sql` ajoute sur `products`:

- `normalized_name_fr`;
- `main_category_id`;
- `sub_category_id`;
- `normalized_color`.

## Exécution

```bash
php backend/bin/migrate_versioned.php
php backend/bin/uniformize_catalog_products.php
```

Mode simulation:

```bash
php backend/bin/uniformize_catalog_products.php --dry-run --report=backend/var/reports/catalog_uniformization_dry_run.json
```

## Rapport

Le script produit un JSON avec:

- nombre de produits traités;
- nombre de produits modifiés;
- erreurs par produit.

Par défaut: `backend/var/reports/catalog_uniformization_report.json`.

