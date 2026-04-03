-- Types de tags historiquement nommés « catégorie » alors qu’il s’agit de marques (navigation à facettes).
UPDATE product_tags
SET type = 'marque', updated_at = NOW()
WHERE LOWER(TRIM(type)) IN (
    'categorie',
    'catégorie',
    'category',
    'categories',
    'catégories'
);
