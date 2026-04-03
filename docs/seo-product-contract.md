# Product SEO Ready Contract

## Objectif
Uniformiser les donnees minimales obligatoires pour publier un produit avec un niveau SEO exploitable (meta, schema, E-E-A-T).

## Champs obligatoires (publication)
- `id`, `slug`, `sku`
- `name`, `description`
- `price`, `priceCurrency`, `status=published`
- `brandSlug`, `categorySlug`
- `imageUrl` (image principale) et `imageAlt`
- `seoTitle`, `seoDescription`

## Champs recommandes (phase 2)
- `gtin`
- `mpn`
- `editorialAuthor`
- `editorialReviewer`
- `reviewedAt` (ISO date/time)

## Regles de qualite
- `seoTitle`: unique par produit, 45-65 caracteres.
- `seoDescription`: unique par produit, 130-160 caracteres.
- `description`: texte utile non duplique, orientee usage/utilisateur.
- `imageAlt`: descriptif utile (pas de bourrage mots-cles).
- `brand/category`: cohérents avec le produit reel.

## QA checklist (go/no-go)
1. Metadata page: title, description, canonical, OG/Twitter.
2. JSON-LD valide: Product + Offer + Breadcrumb.
3. Pas de donnees inventees (ex: ratings fictifs).
4. Robots/indexabilite conformes.
5. Verification echantillon manuel sur 3 produits du lot.
