-- Aligner le défaut SQL du nom de site avec le branding HypoCommerce (lignes futures + cohérence schéma).
ALTER TABLE seo_site_settings
    MODIFY COLUMN site_name VARCHAR(160) NOT NULL DEFAULT 'HypoCommerce';
