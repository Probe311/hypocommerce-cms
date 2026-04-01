CREATE TABLE warehouses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(2) NOT NULL DEFAULT 'FR',
    city VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
);

ALTER TABLE inventory_items
    ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER variant_id,
    ADD CONSTRAINT fk_inventory_items_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL;

ALTER TABLE order_shipments
    ADD COLUMN warehouse_code VARCHAR(32) NULL AFTER order_id;

CREATE TABLE order_shipment_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    CONSTRAINT fk_order_shipment_items_shipment FOREIGN KEY (shipment_id) REFERENCES order_shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_shipment_items_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shipment_item (shipment_id, order_item_id)
);
