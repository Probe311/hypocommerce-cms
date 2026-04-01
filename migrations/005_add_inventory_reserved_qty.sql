ALTER TABLE inventory_items
    ADD COLUMN reserved_qty INT NOT NULL DEFAULT 0 AFTER stock_qty;
