-- Inventory & Order Management System
-- Phase 0 schema and seed baseline.
-- Later phases add users, master data, purchase orders, sales orders, product stock, and stock ledger tables.

CREATE TABLE IF NOT EXISTS schema_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES ('phase-0', 'Bootstrap schema baseline')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Sales', 'WarehouseStaff') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

INSERT INTO users (name, email, password_hash, role, is_active)
VALUES
    ('Admin Demo', 'admin@example.test', '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS', 'Admin', 1),
    ('Sales Demo One', 'sales1@example.test', '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS', 'Sales', 1),
    ('Sales Demo Two', 'sales2@example.test', '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS', 'Sales', 1),
    ('Warehouse Demo One', 'warehouse1@example.test', '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS', 'WarehouseStaff', 1),
    ('Warehouse Demo Two', 'warehouse2@example.test', '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS', 'WarehouseStaff', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = VALUES(is_active);

INSERT INTO schema_versions (version, description)
VALUES ('phase-1', 'Authentication, authorization, and user management baseline')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name),
    KEY idx_categories_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    location VARCHAR(190) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_warehouses_name (name),
    KEY idx_warehouses_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    sku VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    reorder_point INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category_active (category_id, is_active),
    KEY idx_products_name (name),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT chk_products_purchase_price_non_negative CHECK (purchase_price >= 0),
    CONSTRAINT chk_products_selling_price_non_negative CHECK (selling_price >= 0),
    CONSTRAINT chk_products_price_non_negative CHECK (price >= 0),
    CONSTRAINT chk_products_reorder_non_negative CHECK (reorder_point >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_stocks (
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, warehouse_id),
    UNIQUE KEY uq_product_stocks_product_warehouse (product_id, warehouse_id),
    KEY idx_product_stocks_warehouse (warehouse_id),
    CONSTRAINT fk_product_stocks_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_product_stocks_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT chk_product_stocks_quantity_non_negative CHECK (quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_name (name),
    KEY idx_suppliers_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_name (name),
    KEY idx_customers_active (is_active)
) ENGINE=InnoDB;

INSERT INTO categories (name, description, is_active)
VALUES
    ('Raw Materials', 'Material inputs tracked before production or resale.', 1),
    ('Finished Goods', 'Ready-to-sell inventory items.', 1)
ON DUPLICATE KEY UPDATE description = VALUES(description), is_active = VALUES(is_active);

INSERT INTO warehouses (name, location, is_active)
VALUES
    ('Main Warehouse', 'Jakarta', 1),
    ('Secondary Warehouse', 'Bandung', 1)
ON DUPLICATE KEY UPDATE location = VALUES(location), is_active = VALUES(is_active);

INSERT INTO products (category_id, sku, name, unit, purchase_price, selling_price, price, reorder_point, is_active)
SELECT c.id, 'SKU-DEMO-001', 'Demo Finished Product', 'pcs', 20000.00, 25000.00, 25000.00, 10, 1
FROM categories c
WHERE c.name = 'Finished Goods'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    unit = VALUES(unit),
    purchase_price = VALUES(purchase_price),
    selling_price = VALUES(selling_price),
    price = VALUES(price),
    reorder_point = VALUES(reorder_point),
    is_active = VALUES(is_active);

INSERT INTO products (category_id, sku, name, unit, purchase_price, selling_price, price, reorder_point, is_active)
SELECT c.id, 'SKU-DEMO-002', 'Demo Raw Material', 'kg', 12000.00, 15000.00, 15000.00, 20, 1
FROM categories c
WHERE c.name = 'Raw Materials'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    unit = VALUES(unit),
    purchase_price = VALUES(purchase_price),
    selling_price = VALUES(selling_price),
    price = VALUES(price),
    reorder_point = VALUES(reorder_point),
    is_active = VALUES(is_active);

INSERT INTO product_stocks (product_id, warehouse_id, quantity)
SELECT p.id, w.id, CASE WHEN w.name = 'Main Warehouse' THEN 25 ELSE 4 END
FROM products p
INNER JOIN warehouses w ON w.name IN ('Main Warehouse', 'Secondary Warehouse')
WHERE p.sku = 'SKU-DEMO-001'
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO product_stocks (product_id, warehouse_id, quantity)
SELECT p.id, w.id, CASE WHEN w.name = 'Main Warehouse' THEN 50 ELSE 18 END
FROM products p
INNER JOIN warehouses w ON w.name IN ('Main Warehouse', 'Secondary Warehouse')
WHERE p.sku = 'SKU-DEMO-002'
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO suppliers (name, email, phone, address, is_active)
VALUES
    ('Demo Supplier One', 'supplier1@example.test', '021-0001', 'Jl. Supplier Raya 1, Jakarta', 1),
    ('Demo Supplier Two', 'supplier2@example.test', '021-0002', 'Jl. Supplier Raya 2, Bandung', 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), phone = VALUES(phone), address = VALUES(address), is_active = VALUES(is_active);

INSERT INTO customers (name, email, phone, address, is_active)
VALUES
    ('Demo Customer One', 'customer1@example.test', '022-0001', 'Jl. Customer Raya 1, Jakarta', 1),
    ('Demo Customer Two', 'customer2@example.test', '022-0002', 'Jl. Customer Raya 2, Surabaya', 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), phone = VALUES(phone), address = VALUES(address), is_active = VALUES(is_active);

INSERT INTO schema_versions (version, description)
VALUES ('phase-2', 'Master data and read-only multi-warehouse stock baseline')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    destination_warehouse_id INT UNSIGNED NOT NULL,
    status ENUM('Draft', 'Ordered', 'PartiallyReceived', 'Received', 'Cancelled') NOT NULL DEFAULT 'Draft',
    order_date DATE NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_orders_order_number (order_number),
    KEY idx_purchase_orders_status_date (status, order_date),
    KEY idx_purchase_orders_supplier (supplier_id),
    KEY idx_purchase_orders_warehouse (destination_warehouse_id),
    CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_purchase_orders_warehouse FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_purchase_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    received_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_order_items_po_product (purchase_order_id, product_id),
    KEY idx_purchase_order_items_product (product_id),
    CONSTRAINT fk_purchase_order_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_order_items_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_purchase_order_items_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_purchase_order_items_received_non_negative CHECK (received_quantity >= 0),
    CONSTRAINT chk_purchase_order_items_price_non_negative CHECK (purchase_price >= 0),
    CONSTRAINT chk_purchase_order_items_received_lte_quantity CHECK (received_quantity <= quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    movement_type ENUM('Receipt', 'Issue', 'Adjustment') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    quantity_delta BIGINT NULL,
    reference_type VARCHAR(20) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_ledger_product_warehouse (product_id, warehouse_id),
    KEY idx_stock_ledger_reference (reference_type, reference_id),
    CONSTRAINT fk_stock_ledger_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_stock_ledger_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_stock_ledger_performed_by FOREIGN KEY (performed_by) REFERENCES users (id),
    CONSTRAINT chk_stock_ledger_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_stock_ledger_direction CHECK ((movement_type='Adjustment' AND quantity_delta IS NOT NULL AND ABS(quantity_delta)=quantity) OR (movement_type<>'Adjustment' AND quantity_delta IS NULL))
) ENGINE=InnoDB;

ALTER TABLE stock_ledger MODIFY movement_type ENUM('Receipt', 'Issue', 'Adjustment') NOT NULL;

INSERT INTO schema_versions (version, description)
VALUES ('phase-3', 'Purchase order receipt workflow and receipt ledger baseline')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id INT UNSIGNED NULL,
    status ENUM('success', 'failure', 'blocked') NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    metadata_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_actor_time (actor_id, created_at),
    KEY idx_audit_logs_entity_time (entity_type, entity_id, created_at),
    KEY idx_audit_logs_action_time (action, created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_subject_time (email, ip_address, created_at),
    KEY idx_login_attempts_created_at (created_at)
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES ('enterprise-security-1', 'Audit log and login attempt tables')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS sales_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    source_warehouse_id INT UNSIGNED NOT NULL,
    status ENUM('Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled') NOT NULL DEFAULT 'Draft',
    order_date DATE NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_orders_order_number (order_number),
    KEY idx_sales_orders_status_date (status, order_date),
    KEY idx_sales_orders_customer (customer_id),
    KEY idx_sales_orders_warehouse (source_warehouse_id),
    KEY idx_sales_orders_created_by (created_by),
    CONSTRAINT fk_sales_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_sales_orders_warehouse FOREIGN KEY (source_warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_sales_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_sales_orders_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    selling_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_order_items_so_product (sales_order_id, product_id),
    KEY idx_sales_order_items_product (product_id),
    CONSTRAINT fk_sales_order_items_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_order_items_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_sales_order_items_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_sales_order_items_price_non_negative CHECK (selling_price >= 0)
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES ('phase-4', 'Sales order approval and concurrency-safe issue baseline')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO products (category_id, sku, name, unit, purchase_price, selling_price, price, reorder_point, is_active)
SELECT c.id, CONCAT('SKU-SEED-', LPAD(seq.n, 3, '0')), CONCAT('Seed Product ', LPAD(seq.n, 3, '0')), 'pcs',
       9000.00 + (seq.n * 100), 12000.00 + (seq.n * 100), 12000.00 + (seq.n * 100), 8 + (seq.n % 7), 1
FROM (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
    UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
    UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
    UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28
) seq
INNER JOIN categories c ON c.name = CASE WHEN seq.n % 2 = 0 THEN 'Finished Goods' ELSE 'Raw Materials' END
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    unit = VALUES(unit),
    purchase_price = VALUES(purchase_price),
    selling_price = VALUES(selling_price),
    price = VALUES(price),
    reorder_point = VALUES(reorder_point),
    is_active = VALUES(is_active);

INSERT INTO product_stocks (product_id, warehouse_id, quantity)
SELECT p.id, w.id,
       CASE WHEN w.name = 'Secondary Warehouse' AND CAST(RIGHT(p.sku, 3) AS UNSIGNED) % 5 = 0 THEN 2
            ELSE 20 + CAST(RIGHT(p.sku, 3) AS UNSIGNED)
       END
FROM products p
INNER JOIN warehouses w ON w.name IN ('Main Warehouse', 'Secondary Warehouse')
WHERE p.sku LIKE 'SKU-SEED-%'
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO purchase_orders (order_number, supplier_id, destination_warehouse_id, status, order_date, created_by)
SELECT CONCAT('PO-SEED-', LPAD(seq.n, 3, '0')), s.id, w.id,
       CASE WHEN seq.n % 4 = 0 THEN 'Received' WHEN seq.n % 3 = 0 THEN 'PartiallyReceived' WHEN seq.n % 2 = 0 THEN 'Ordered' ELSE 'Draft' END,
       DATE_SUB(CURRENT_DATE, INTERVAL seq.n DAY), u.id
FROM (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
) seq
INNER JOIN suppliers s ON s.name = 'Demo Supplier One'
INNER JOIN warehouses w ON w.name = 'Main Warehouse'
INNER JOIN users u ON u.email = 'admin@example.test'
ON DUPLICATE KEY UPDATE status = VALUES(status), order_date = VALUES(order_date);

INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, received_quantity, purchase_price)
SELECT po.id, p.id, 5, CASE WHEN po.status = 'Received' THEN 5 WHEN po.status = 'PartiallyReceived' THEN 2 ELSE 0 END, p.purchase_price
FROM purchase_orders po
INNER JOIN products p ON p.sku = CONCAT('SKU-SEED-', RIGHT(po.order_number, 3))
WHERE po.order_number LIKE 'PO-SEED-%'
ON DUPLICATE KEY UPDATE received_quantity = VALUES(received_quantity), purchase_price = VALUES(purchase_price);

INSERT INTO sales_orders (order_number, customer_id, source_warehouse_id, status, order_date, created_by, approved_by, approved_at)
SELECT CONCAT('SO-SEED-', LPAD(seq.n, 3, '0')), c.id, w.id,
       CASE WHEN seq.n % 6 = 0 THEN 'Cancelled' WHEN seq.n % 5 = 0 THEN 'Fulfilled' WHEN seq.n % 3 = 0 THEN 'Approved' WHEN seq.n % 2 = 0 THEN 'PendingApproval' ELSE 'Draft' END,
       DATE_SUB(CURRENT_DATE, INTERVAL seq.n DAY), sales.id,
       CASE WHEN seq.n % 3 = 0 OR seq.n % 5 = 0 THEN admin.id ELSE NULL END,
       CASE WHEN seq.n % 3 = 0 OR seq.n % 5 = 0 THEN CURRENT_TIMESTAMP ELSE NULL END
FROM (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12
) seq
INNER JOIN customers c ON c.name = 'Demo Customer One'
INNER JOIN warehouses w ON w.name = 'Main Warehouse'
INNER JOIN users sales ON sales.email = CASE WHEN seq.n % 2 = 0 THEN 'sales1@example.test' ELSE 'sales2@example.test' END
INNER JOIN users admin ON admin.email = 'admin@example.test'
ON DUPLICATE KEY UPDATE status = VALUES(status), order_date = VALUES(order_date), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at);

INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price)
SELECT so.id, p.id, 1 + (CAST(RIGHT(so.order_number, 3) AS UNSIGNED) % 3), p.selling_price
FROM sales_orders so
INNER JOIN products p ON p.sku = CONCAT('SKU-SEED-', RIGHT(so.order_number, 3))
WHERE so.order_number LIKE 'SO-SEED-%'
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), selling_price = VALUES(selling_price);

-- Opening zero balances complete the product/warehouse matrix without inventing stock movements.
-- Preserve all populated seed balances. Runtime initialization is owned by StockService.
INSERT INTO product_stocks (product_id, warehouse_id, quantity)
SELECT p.id, w.id, 0
FROM products p
CROSS JOIN warehouses w
LEFT JOIN product_stocks ps ON ps.product_id = p.id AND ps.warehouse_id = w.id
WHERE ps.product_id IS NULL;

CREATE TABLE IF NOT EXISTS operation_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operation_request_actor_key (actor_id, request_key),
    CONSTRAINT fk_operation_request_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

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
