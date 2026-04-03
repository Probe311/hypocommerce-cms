-- Défaut et données existantes : branding « Hypocommerce CMS » (ne pas modifier 021_* ni 023_* versionnés).
ALTER TABLE seo_site_settings
    MODIFY COLUMN site_name VARCHAR(160) NOT NULL DEFAULT 'Hypocommerce CMS';

UPDATE seo_site_settings
SET site_name = 'Hypocommerce CMS'
WHERE site_name IN ('Nexora', 'HypoCommerce');
