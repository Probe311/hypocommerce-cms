CREATE TABLE IF NOT EXISTS admin_tax_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    country_code VARCHAR(2) NOT NULL,
    region_code VARCHAR(16) NULL,
    tax_type VARCHAR(40) NOT NULL DEFAULT 'vat',
    rate DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_tax_rule (country_code, region_code, tax_type)
);

CREATE TABLE IF NOT EXISTS admin_shipping_carriers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carrier_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    zones JSON NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_payment_methods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    method_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    mode ENUM('sandbox','live') NOT NULL DEFAULT 'sandbox',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

INSERT INTO admin_tax_rules (country_code, region_code, tax_type, rate, is_enabled, is_default, metadata, created_at, updated_at)
SELECT 'FR', NULL, 'vat', 20.000, 1, 1, JSON_OBJECT('label', 'TVA France standard'), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_tax_rules WHERE country_code = 'FR' AND region_code IS NULL AND tax_type = 'vat');

INSERT INTO admin_shipping_carriers (carrier_key, label, zones, is_enabled, priority, metadata, created_at, updated_at)
SELECT 'boxtal', 'Boxtal', JSON_ARRAY('FR','EU'), 0, 30, JSON_OBJECT('source', 'plugin'), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_shipping_carriers WHERE carrier_key = 'boxtal');

INSERT INTO admin_shipping_carriers (carrier_key, label, zones, is_enabled, priority, metadata, created_at, updated_at)
SELECT 'dhl', 'DHL', JSON_ARRAY('EU','INTL'), 1, 20, JSON_OBJECT('source', 'native'), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_shipping_carriers WHERE carrier_key = 'dhl');

INSERT INTO admin_payment_methods (method_key, label, provider, mode, is_enabled, priority, metadata, created_at, updated_at)
SELECT 'stripe_card', 'Stripe Card', 'stripe', 'sandbox', 0, 10, JSON_OBJECT('source', 'plugin'), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_payment_methods WHERE method_key = 'stripe_card');

INSERT INTO admin_payment_methods (method_key, label, provider, mode, is_enabled, priority, metadata, created_at, updated_at)
SELECT 'paypal_wallet', 'PayPal', 'paypal', 'sandbox', 0, 20, JSON_OBJECT('source', 'plugin'), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_payment_methods WHERE method_key = 'paypal_wallet');
