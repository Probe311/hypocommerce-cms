# Audit backend admin - couverture et ecarts

## Matrice de couverture

| Domaine | Etat | Constat |
| --- | --- | --- |
| Dashboard | Partiel | KPIs et activite recente presentes, peu d'actions operables et pas de personnalisation.
| Orders | Partiel | Recherche et listing disponibles, flux avances (refund/split/shipment) exposes API mais UX admin incomplète.
| Products | Partiel | Recherche et listing OK, edition detaillee et medias unifies manquants.
| Customers | Partiel | Recherche et listing disponibles, absence d'actions CRM detaillees cote UI.
| Content | Partiel | Upsert pages/blog FAQ/legal present, workflow editorial incomplet en UI.
| Settings | Partiel | Coupons seulement, parametres techniques (paiement/transport/plugins) disperses.
| Plugins | Manquant | Aucune persistance dediee, aucun endpoint admin dedie, aucune page UI dediee avant cette livraison.

## Ecarts architecture / securite

- **Partiel**: coexistence API admin centralisee `/api/v1/admin/*` et scripts legacy `public/admin/api/*` qui augmentent le risque de divergence de comportements.
- **Partiel**: mode bypass login cote UI admin utile pour revue visuelle, mais a maintenir strictement en temporaire.
- **Partiel**: providers paiement/transport non pilotes de maniere centralisee par une couche plugins persistante.
- **Manquant**: gouvernance des options non secretes de providers (mode, priorite, labels) avec validation stricte en entree.

## Backlog priorise

1. P0 (cette livraison): infra plugins db-backed + endpoints + page Plugins + Boxtal minimal.
2. P1: finaliser UX fonctionnelle Orders/Products/Content sur les actions deja exposees API.
3. P2: encapsuler puis decommissionner progressivement les scripts legacy admin.
