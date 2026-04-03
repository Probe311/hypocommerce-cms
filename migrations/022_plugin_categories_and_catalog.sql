-- Catégories de plugins et extension du catalogue (admin_plugins)

CREATE TABLE IF NOT EXISTS plugin_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'payment', 'Paiement', 10, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'payment');

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'shipping_domestic', 'Livraison nationale', 20, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'shipping_domestic');

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'shipping_relay', 'Points relais', 30, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'shipping_relay');

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'shipping_international', 'Livraison internationale', 40, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'shipping_international');

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'shipping_aggregator', 'Agrégateurs transport', 50, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'shipping_aggregator');

INSERT INTO plugin_categories (slug, label, sort_order, created_at, updated_at)
SELECT 'other', 'Autre', 999, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM plugin_categories WHERE slug = 'other');

ALTER TABLE admin_plugins
    ADD COLUMN category_id INT UNSIGNED NULL AFTER plugin_type,
    ADD COLUMN description_long TEXT NULL AFTER description,
    ADD COLUMN docs_url VARCHAR(512) NULL AFTER description_long;

UPDATE admin_plugins ap
INNER JOIN plugin_categories pc ON pc.slug = CASE
    WHEN ap.plugin_key IN ('stripe', 'paypal') THEN 'payment'
    WHEN ap.plugin_key = 'boxtal' THEN 'shipping_aggregator'
    ELSE 'other'
END
SET ap.category_id = pc.id
WHERE ap.category_id IS NULL;

UPDATE admin_plugins ap
INNER JOIN plugin_categories pc ON pc.slug = 'other'
SET ap.category_id = pc.id
WHERE ap.category_id IS NULL;

ALTER TABLE admin_plugins
    ADD CONSTRAINT fk_admin_plugins_category FOREIGN KEY (category_id) REFERENCES plugin_categories(id) ON DELETE RESTRICT;

ALTER TABLE admin_plugins MODIFY COLUMN category_id INT UNSIGNED NOT NULL;

UPDATE admin_plugins
SET description_long = 'Acceptez les cartes bancaires via Stripe Checkout. Webhooks et idempotence côté HypoCommerce.',
    docs_url = 'https://docs.stripe.com/api'
WHERE plugin_key = 'stripe';

UPDATE admin_plugins
SET description_long = 'Proposez le paiement PayPal (wallet). Configurez les identifiants API REST sandbox ou production.',
    docs_url = 'https://developer.paypal.com/docs/api/overview/'
WHERE plugin_key = 'paypal';

UPDATE admin_plugins
SET description_long = 'Multi-transporteurs via Boxtal (étiquettes, suivi). Phase d’intégration : configurez vos clés API dans l’environnement.',
    docs_url = 'https://developer.boxtal.com/'
WHERE plugin_key = 'boxtal';

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'colissimo', 'shipping', pc.id, 'Colissimo', 'La Poste — Colissimo domicile', 'Génération d’étiquettes et suivi via les Web Services Colissimo (compte La Poste / Colissimo).', 'https://developer.laposte.fr/', 0, 35, 'sandbox', JSON_OBJECT('publicLabel', 'Colissimo', 'priority', 35), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_domestic'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'colissimo');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'mondial_relay', 'shipping', pc.id, 'Mondial Relay', 'Livraison en point relais', 'API Mondial Relay pour la sélection de relais et les étiquettes (compte partenaire requis).', 'https://www.mondialrelay.fr/fonctionnalites/api/', 0, 36, 'sandbox', JSON_OBJECT('publicLabel', 'Mondial Relay', 'priority', 36), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_relay'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'mondial_relay');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'relais_colis', 'shipping', pc.id, 'Relais Colis', 'Réseau Relais Colis', 'Intégration API partenaire Relais Colis pour la livraison en point relais en France.', 'https://www.relaiscolis.com/', 0, 37, 'sandbox', JSON_OBJECT('publicLabel', 'Relais Colis', 'priority', 37), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_relay'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'relais_colis');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'chronopost', 'shipping', pc.id, 'Chronopost', 'Express La Poste', 'Chronopost (groupe La Poste) : offres express et relais selon votre contrat et les API Chronopost.', 'https://www.chronopost.fr/fr/particuliers', 0, 38, 'sandbox', JSON_OBJECT('publicLabel', 'Chronopost', 'priority', 38), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_domestic'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'chronopost');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'tnt_fedex', 'shipping', pc.id, 'TNT / FedEx', 'Transport express Europe', 'TNT est intégré au réseau FedEx : utilisez les APIs développeur FedEx pour expéditions et suivi.', 'https://developer.fedex.com/', 0, 39, 'sandbox', JSON_OBJECT('publicLabel', 'TNT FedEx', 'priority', 39), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_international'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'tnt_fedex');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'dhl_express', 'shipping', pc.id, 'DHL Express', 'Livraison internationale', 'MyDHL API / DHL Developer pour expéditions express et suivi colis.', 'https://developer.dhl.com/', 0, 40, 'sandbox', JSON_OBJECT('publicLabel', 'DHL Express', 'priority', 40), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_international'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'dhl_express');

INSERT INTO admin_plugins (plugin_key, plugin_type, category_id, label, description, description_long, docs_url, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'sendcloud', 'shipping', pc.id, 'Sendcloud', 'Agrégateur EU', 'Sendcloud : multi-transporteurs, étiquettes et suivi (alternative / complément à Boxtal).', 'https://api.sendcloud.dev/', 0, 41, 'sandbox', JSON_OBJECT('publicLabel', 'Sendcloud', 'priority', 41), NOW(), NOW()
FROM plugin_categories pc WHERE pc.slug = 'shipping_aggregator'
AND NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'sendcloud');
