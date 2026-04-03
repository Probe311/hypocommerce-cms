# Convergence legacy vs API admin moderne

## Legacy `public/admin/api/*`

| Legacy endpoint | Capacite | Equivalence moderne | Statut |
| --- | --- | --- | --- |
| `upload-image.php` | Upload image produit | Pas d'endpoint moderne multipart | Equivalence partielle (fallback legacy controle via `api.js`) |
| `delete-image.php` | Suppression image produit | Pas d'endpoint moderne dedie suppression media | Equivalence partielle (fallback legacy controle via `api.js`) |
| `reorder-images.php` | Reordonnancement images produit | Pas d'endpoint moderne dedie reorder media | Equivalence partielle (fallback legacy controle via `api.js`) |

## API admin moderne ajoutee pour convergence

- `products/images/list` (listing media produit) dans `CmsAdminController`.
- Couche unifiee front dans `backend/public/admin/services/api.js`:
  - chemins modernes priorises.
  - fallback legacy media seulement quand necessaire.
  - journal local des appels (diagnostic Settings/Admin Tools).

## Strategie de deprecation

1. Conserver fallback legacy pour media tant que l'upload multipart n'est pas expose via API admin moderne.
2. Introduire ensuite endpoints modernes media upload/delete/reorder.
3. Supprimer fallback legacy et deprecier `public/admin/api/*`.
