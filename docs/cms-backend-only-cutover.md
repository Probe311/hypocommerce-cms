# CMS Backend-Only Cutover

## Objectif
Supprimer les fallbacks frontend statiques et utiliser uniquement les APIs CMS backend comme source de vérité.

## Endpoints backend à consommer
- `GET /api/v1/cms/pages/{slug}`
- `GET /api/v1/cms/articles?category={slug}`
- `GET /api/v1/cms/article-categories`
- `GET /api/v1/cms/navigation/header`
- `GET /api/v1/cms/navigation/footer`
- `GET /api/v1/cms/navigation/secondary`
- `GET /api/v1/cms/social-links`
- `GET /api/v1/cms/footer`

## Source canonique
- Canonique: endpoints CMS V2 sous `/api/v1/cms/*`.
- Compatibilité legacy maintenue temporairement (`/api/v1/pages/*`, `/api/v1/articles`, `/api/v1/faq`, `/api/v1/legal/*`) pour transition progressive.

## Plan de bascule sans downtime
1. Ajouter des flags frontend pour basculer route par route vers les endpoints backend.
2. Activer d'abord en environnement staging avec snapshots visuels.
3. Monitorer erreurs API + logs de rendu.
4. Activer en production progressivement (header/footer/articles/pages).
5. Retirer les fallbacks locaux uniquement après validation.

## Validation
- Vérifier navigation, footer et icônes sociales sur toutes les pages.
- Vérifier rendu des pages CMS sectionnées et articles blog.
- Vérifier cache invalide après publication admin.
