CREATE TABLE IF NOT EXISTS purchase_order_closures (
 purchase_order_id INT UNSIGNED PRIMARY KEY, closed_by INT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id), FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sales_order_rejections (
 sales_order_id INT UNSIGNED PRIMARY KEY, rejected_by INT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id), FOREIGN KEY (rejected_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS inventory_operations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('Adjustment','Transfer','SupplierReturn','CustomerReturn') NOT NULL,
 status ENUM('PendingApproval','Approved','Rejected','Posted','Cancelled') NOT NULL DEFAULT 'PendingApproval',
 warehouse_id INT UNSIGNED NOT NULL, destination_id INT UNSIGNED NULL,
 reason VARCHAR(500) NOT NULL, decision_reason VARCHAR(500) NULL,
 condition_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
 created_by INT UNSIGNED NOT NULL, approved_by INT UNSIGNED NULL, posted_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, approved_at TIMESTAMP NULL, posted_at TIMESTAMP NULL,
 KEY idx_inventory_operations_status_time (status,created_at),
 FOREIGN KEY (warehouse_id) REFERENCES warehouses(id), FOREIGN KEY (destination_id) REFERENCES warehouses(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 CONSTRAINT chk_return_condition CHECK (kind<>'CustomerReturn' OR condition_confirmed=TRUE),
 CONSTRAINT chk_operation_independent_approval CHECK (approved_by IS NULL OR approved_by <> created_by),
 CONSTRAINT chk_operation_transfer_destination CHECK ((kind='Transfer' AND destination_id IS NOT NULL AND destination_id<>warehouse_id) OR (kind<>'Transfer' AND destination_id IS NULL))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS inventory_operation_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operation_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL,
 baseline INT UNSIGNED NULL, source_ledger_id BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_operation_product (operation_id,product_id), KEY idx_operation_source (source_ledger_id),
 FOREIGN KEY (operation_id) REFERENCES inventory_operations(id), FOREIGN KEY (product_id) REFERENCES products(id),
 FOREIGN KEY (source_ledger_id) REFERENCES stock_ledger(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_return_totals (
 source_ledger_id BIGINT UNSIGNED PRIMARY KEY, returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (source_ledger_id) REFERENCES stock_ledger(id)
) ENGINE=InnoDB;
