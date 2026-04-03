CREATE TABLE IF NOT EXISTS admin_plugins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plugin_key VARCHAR(64) NOT NULL UNIQUE,
    plugin_type ENUM('payment','shipping','other') NOT NULL DEFAULT 'other',
    label VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    mode ENUM('sandbox','live') NOT NULL DEFAULT 'sandbox',
    config JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

INSERT INTO admin_plugins (plugin_key, plugin_type, label, description, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'stripe', 'payment', 'Stripe', 'Paiement carte via Stripe Checkout', 0, 10, 'sandbox', JSON_OBJECT('publicLabel', 'Carte bancaire', 'priority', 10), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'stripe');

INSERT INTO admin_plugins (plugin_key, plugin_type, label, description, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'paypal', 'payment', 'PayPal', 'Paiement wallet PayPal', 0, 20, 'sandbox', JSON_OBJECT('publicLabel', 'PayPal', 'priority', 20), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'paypal');

INSERT INTO admin_plugins (plugin_key, plugin_type, label, description, is_enabled, sort_order, mode, config, created_at, updated_at)
SELECT 'boxtal', 'shipping', 'Boxtal', 'Agregateur transporteur Boxtal', 0, 30, 'sandbox', JSON_OBJECT('publicLabel', 'Livraison Boxtal', 'priority', 30), NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM admin_plugins WHERE plugin_key = 'boxtal');
