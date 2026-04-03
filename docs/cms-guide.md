# Guide CMS et backoffice

## Modele de donnees CMS

- Pages (`cms_pages` + `cms_page_sections`)
- Blog (`blog_articles`, categories)
- FAQ (`cms_faq_items`)
- Legal (`cms_legal_pages`)
- Medias (`cms_media_library` ou `media_assets` selon migration)

Les sections de page sont stockees en JSON (`payload`) et normalisees par `PdoCmsV2Repository`.

## Workflow editorial

- Etats de page: draft, review, scheduled, published, archived.
- Transitions controlees par `App\Application\Cms\WorkflowTransitionGuard`.
- Versioning via `cms_page_versions`.
- Publication planifiee:
  - Champ `scheduled_at` sur les pages.
  - Job CLI `php bin/publish_scheduled_cms.php`.

## Scripts utiles

- Import/seeding contenu:
  - `php bin/backfill_cms_content.php`
  - `php bin/import_seo_pages_from_source.php` (selon projet)
- Media:
  - `php bin/normalize_existing_cms_images_remote.php`
  - `php bin/fill_cms_hero_images_remote.php`
  - `php bin/sync_local_cms_uploads_ftp.php`

## Admin CMS

- UI servie par `public/admin/app.js`.
- Pages CMS gerees par `public/admin/pages/content.js`.
- API:
  - Lecture publique: `/api/v1/cms/*` (via `CmsPublicController`).
  - Admin: `/api/v1/admin/cms/*` (via `CmsV2AdminController`).

## Donnees de demo (recommande pour OSS)

Pour peupler rapidement un environnement de demo:

1. Importer/creer un catalogue minimal (quelques produits et categories).
2. Executer `php bin/backfill_cms_content.php` pour remplir des pages generiques.
3. Utiliser l'admin (`/admin`) pour ajuster:
   - Page d'accueil
   - Navigation header/footer
   - Quelques articles de blog et FAQ.

Ce setup suffit pour tester le CMS headless sans dependance a un projet client specifique.

