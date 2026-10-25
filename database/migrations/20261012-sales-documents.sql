-- D-19 / ADR-025: customer price lists with tiers, quotations, delivery notes (surat jalan) and invoices with PPN.
-- Additive and idempotent DDL only; scripts/migrate-sales-documents.php runs it, then creates delivery notes for the
-- historical shipments in one transaction (App\Repository\MySql\DeliveryNoteMigration), verifies every invariant and
-- records schema version sales-documents-1. Existing rows keep their values: SO lines become price_source = 'MASTER'
-- (they were priced from the master, ADR-015/019), no invoice is created for history.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

-- ADR-017 sequence prefixes: QT (quotation), DO (delivery note), INV (invoice). A superset of the old set.
SET @has := (SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_document_sequences_prefix' AND check_clause LIKE '%INV%');
SET @ddl := IF(@has = 0, 'ALTER TABLE document_sequences DROP CHECK chk_document_sequences_prefix, ADD CONSTRAINT chk_document_sequences_prefix CHECK (prefix IN (''PO'', ''SO'', ''QT'', ''DO'', ''INV''))', 'DO 0');
PREPARE sales_doc_step FROM @ddl; EXECUTE sales_doc_step; DEALLOCATE PREPARE sales_doc_step;

CREATE TABLE IF NOT EXISTS price_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATE NULL DEFAULT NULL,
    valid_to DATE NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_price_lists_name (name),
    CONSTRAINT chk_price_lists_validity CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS price_list_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    price_list_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_code VARCHAR(30) NOT NULL,
    min_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_price_list_items_tier (price_list_id, product_id, unit_code, min_quantity),
    KEY idx_price_list_items_product (product_id, unit_code),
    CONSTRAINT fk_price_list_items_list FOREIGN KEY (price_list_id) REFERENCES price_lists (id),
    CONSTRAINT fk_price_list_items_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_price_list_items_min CHECK (min_quantity >= 1),
    CONSTRAINT chk_price_list_items_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'price_list_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE customers ADD COLUMN price_list_id INT UNSIGNED NULL DEFAULT NULL AFTER address, ADD COLUMN npwp VARCHAR(16) NULL DEFAULT NULL AFTER price_list_id, ADD COLUMN nik VARCHAR(16) NULL DEFAULT NULL AFTER npwp, ADD COLUMN tax_name VARCHAR(120) NULL DEFAULT NULL AFTER nik, ADD COLUMN tax_address VARCHAR(255) NULL DEFAULT NULL AFTER tax_name, ADD KEY idx_customers_price_list (price_list_id), ADD CONSTRAINT fk_customers_price_list FOREIGN KEY (price_list_id) REFERENCES price_lists (id), ADD CONSTRAINT chk_customers_npwp CHECK (npwp IS NULL OR npwp REGEXP ''^[0-9]{15,16}$''), ADD CONSTRAINT chk_customers_nik CHECK (nik IS NULL OR nik REGEXP ''^[0-9]{16}$'')', 'DO 0');
PREPARE sales_doc_step FROM @ddl; EXECUTE sales_doc_step; DEALLOCATE PREPARE sales_doc_step;

CREATE TABLE IF NOT EXISTS quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_number VARCHAR(50) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    source_warehouse_id INT UNSIGNED NOT NULL,
    status ENUM('Draft', 'Sent', 'Accepted', 'Rejected', 'Cancelled') NOT NULL DEFAULT 'Draft',
    quote_date DATE NOT NULL,
    valid_until DATE NOT NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NOT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    decided_by INT UNSIGNED NULL DEFAULT NULL,
    decided_at TIMESTAMP NULL DEFAULT NULL,
    rejection_reason VARCHAR(500) NULL DEFAULT NULL,
    cancelled_by INT UNSIGNED NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quotations_number (quotation_number),
    KEY idx_quotations_status_date (status, quote_date),
    KEY idx_quotations_customer (customer_id),
    KEY idx_quotations_created_by (created_by),
    CONSTRAINT fk_quotations_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_quotations_warehouse FOREIGN KEY (source_warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_quotations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_quotations_decided_by FOREIGN KEY (decided_by) REFERENCES users (id),
    CONSTRAINT fk_quotations_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id),
    CONSTRAINT chk_quotations_validity CHECK (valid_until >= quote_date),
    CONSTRAINT chk_quotations_sent CHECK (status IN ('Draft', 'Cancelled') OR sent_at IS NOT NULL),
    CONSTRAINT chk_quotations_decided CHECK (status NOT IN ('Accepted', 'Rejected') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
    CONSTRAINT chk_quotations_rejected CHECK ((status = 'Rejected') = (rejection_reason IS NOT NULL)),
    CONSTRAINT chk_quotations_cancelled CHECK ((status = 'Cancelled') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotation_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_code VARCHAR(30) NOT NULL,
    unit_factor INT UNSIGNED NOT NULL DEFAULT 1,
    unit_quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    list_price DECIMAL(14,2) NOT NULL,
    price_reason VARCHAR(255) NULL DEFAULT NULL,
    price_source ENUM('MASTER', 'PRICE_LIST') NOT NULL DEFAULT 'MASTER',
    price_list_id INT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uq_quotation_items_product (quotation_id, product_id),
    KEY idx_quotation_items_product (product_id),
    CONSTRAINT fk_quotation_items_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id),
    CONSTRAINT fk_quotation_items_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_quotation_items_price_list FOREIGN KEY (price_list_id) REFERENCES price_lists (id),
    CONSTRAINT chk_quotation_items_unit CHECK (unit_factor >= 1 AND unit_quantity > 0 AND quantity = unit_quantity * unit_factor),
    CONSTRAINT chk_quotation_items_prices CHECK (unit_price >= 0 AND list_price >= 0),
    CONSTRAINT chk_quotation_items_reason CHECK ((unit_price = list_price AND price_reason IS NULL) OR (unit_price <> list_price AND price_reason IS NOT NULL AND CHAR_LENGTH(TRIM(price_reason)) >= 3)),
    CONSTRAINT chk_quotation_items_source CHECK ((price_source = 'MASTER' AND price_list_id IS NULL) OR (price_source = 'PRICE_LIST' AND price_list_id IS NOT NULL))
) ENGINE=InnoDB;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_orders' AND column_name = 'quotation_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE sales_orders ADD COLUMN quotation_id INT UNSIGNED NULL DEFAULT NULL AFTER approved_at, ADD UNIQUE KEY uq_sales_orders_quotation (quotation_id), ADD CONSTRAINT fk_sales_orders_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id)', 'DO 0');
PREPARE sales_doc_step FROM @ddl; EXECUTE sales_doc_step; DEALLOCATE PREPARE sales_doc_step;

SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_order_items' AND column_name = 'price_source');
SET @ddl := IF(@has = 0, 'ALTER TABLE sales_order_items ADD COLUMN price_source ENUM(''MASTER'', ''PRICE_LIST'') NOT NULL DEFAULT ''MASTER'' AFTER price_reason, ADD COLUMN price_list_id INT UNSIGNED NULL DEFAULT NULL AFTER price_source, ADD COLUMN quotation_item_id INT UNSIGNED NULL DEFAULT NULL AFTER price_list_id, ADD UNIQUE KEY uq_sales_order_items_quotation_item (quotation_item_id), ADD CONSTRAINT fk_sales_order_items_price_list FOREIGN KEY (price_list_id) REFERENCES price_lists (id), ADD CONSTRAINT fk_sales_order_items_quotation_item FOREIGN KEY (quotation_item_id) REFERENCES quotation_items (id), ADD CONSTRAINT chk_sales_order_items_price_source CHECK ((price_source = ''MASTER'' AND price_list_id IS NULL) OR (price_source = ''PRICE_LIST'' AND price_list_id IS NOT NULL))', 'DO 0');
PREPARE sales_doc_step FROM @ddl; EXECUTE sales_doc_step; DEALLOCATE PREPARE sales_doc_step;

CREATE TABLE IF NOT EXISTS delivery_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_number VARCHAR(50) NOT NULL,
    sales_order_id INT UNSIGNED NOT NULL,
    shipment_number INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    ship_to_name VARCHAR(120) NOT NULL,
    ship_to_address VARCHAR(255) NOT NULL DEFAULT '',
    vehicle VARCHAR(40) NULL DEFAULT NULL,
    driver VARCHAR(80) NULL DEFAULT NULL,
    prepared_by INT UNSIGNED NOT NULL,
    shipped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    origin ENUM('Issue', 'Migration') NOT NULL DEFAULT 'Issue',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_notes_number (delivery_number),
    UNIQUE KEY uq_delivery_notes_shipment (sales_order_id, shipment_number),
    KEY idx_delivery_notes_shipped (shipped_at),
    KEY idx_delivery_notes_customer (customer_id),
    CONSTRAINT fk_delivery_notes_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id),
    CONSTRAINT fk_delivery_notes_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_delivery_notes_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_delivery_notes_prepared_by FOREIGN KEY (prepared_by) REFERENCES users (id),
    CONSTRAINT chk_delivery_notes_shipment CHECK (shipment_number >= 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_note_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_note_id INT UNSIGNED NOT NULL,
    sales_order_item_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_code VARCHAR(30) NOT NULL,
    unit_factor INT UNSIGNED NOT NULL DEFAULT 1,
    unit_quantity INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_delivery_note_lines_item (delivery_note_id, sales_order_item_id),
    KEY idx_delivery_note_lines_item (sales_order_item_id),
    CONSTRAINT fk_delivery_note_lines_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id),
    CONSTRAINT fk_delivery_note_lines_item FOREIGN KEY (sales_order_item_id) REFERENCES sales_order_items (id),
    CONSTRAINT fk_delivery_note_lines_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_delivery_note_lines_unit CHECK (unit_factor >= 1 AND unit_quantity > 0 AND quantity = unit_quantity * unit_factor)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_note_lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_note_id INT UNSIGNED NOT NULL,
    delivery_note_line_id INT UNSIGNED NOT NULL,
    ledger_id BIGINT UNSIGNED NOT NULL,
    lot_id INT UNSIGNED NULL DEFAULT NULL,
    lot_code VARCHAR(60) NULL DEFAULT NULL,
    expiry_date DATE NULL DEFAULT NULL,
    quantity INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_delivery_note_lots_ledger (ledger_id),
    KEY idx_delivery_note_lots_line (delivery_note_line_id),
    KEY idx_delivery_note_lots_note (delivery_note_id),
    CONSTRAINT fk_delivery_note_lots_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id),
    CONSTRAINT fk_delivery_note_lots_line FOREIGN KEY (delivery_note_line_id) REFERENCES delivery_note_lines (id),
    CONSTRAINT fk_delivery_note_lots_ledger FOREIGN KEY (ledger_id) REFERENCES stock_ledger (id),
    CONSTRAINT fk_delivery_note_lots_lot FOREIGN KEY (lot_id) REFERENCES stock_lots (id),
    CONSTRAINT chk_delivery_note_lots_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

-- PPN (assumptions in ADR-025, to be verified by a tax adviser): DPP = round_half_up(subtotal x num / den) and
-- PPN = round_half_up(DPP x rate / 100), both whole Rupiah per invoice, total = subtotal + PPN. The CHECKs recompute
-- them from the snapshot settings in integer arithmetic. active_delivery_note_id: at most one non-void invoice per note.
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    delivery_note_id INT UNSIGNED NOT NULL,
    sales_order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_address VARCHAR(255) NOT NULL DEFAULT '',
    customer_npwp VARCHAR(16) NULL DEFAULT NULL,
    customer_nik VARCHAR(16) NULL DEFAULT NULL,
    customer_tax_name VARCHAR(120) NULL DEFAULT NULL,
    customer_tax_address VARCHAR(255) NULL DEFAULT NULL,
    subtotal DECIMAL(16,2) NOT NULL,
    dpp_amount DECIMAL(16,2) NOT NULL,
    vat_amount DECIMAL(16,2) NOT NULL,
    total_amount DECIMAL(16,2) NOT NULL,
    vat_rate_percent TINYINT UNSIGNED NOT NULL,
    vat_dpp_numerator SMALLINT UNSIGNED NOT NULL,
    vat_dpp_denominator SMALLINT UNSIGNED NOT NULL,
    paid_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_by INT UNSIGNED NULL DEFAULT NULL,
    voided_at TIMESTAMP NULL DEFAULT NULL,
    void_reason VARCHAR(500) NULL DEFAULT NULL,
    active_delivery_note_id INT UNSIGNED GENERATED ALWAYS AS (IF(voided_at IS NULL, delivery_note_id, NULL)) STORED,
    UNIQUE KEY uq_invoices_number (invoice_number),
    UNIQUE KEY uq_invoices_active_delivery_note (active_delivery_note_id),
    KEY idx_invoices_delivery_note (delivery_note_id),
    KEY idx_invoices_customer_date (customer_id, invoice_date),
    KEY idx_invoices_sales_order (sales_order_id),
    KEY idx_invoices_date (invoice_date),
    CONSTRAINT fk_invoices_delivery_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes (id),
    CONSTRAINT fk_invoices_sales_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id),
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_invoices_voided_by FOREIGN KEY (voided_by) REFERENCES users (id),
    CONSTRAINT chk_invoices_amounts CHECK (subtotal >= 0 AND dpp_amount = ROUND(dpp_amount) AND vat_amount = ROUND(vat_amount)),
    CONSTRAINT chk_invoices_tax_settings CHECK (vat_rate_percent <= 100 AND vat_dpp_numerator >= 1 AND vat_dpp_numerator <= vat_dpp_denominator),
    CONSTRAINT chk_invoices_dpp CHECK (dpp_amount = (CAST(ROUND(subtotal * 100) AS SIGNED) * vat_dpp_numerator + 50 * vat_dpp_denominator) DIV (100 * vat_dpp_denominator)),
    CONSTRAINT chk_invoices_vat CHECK (vat_amount = (CAST(dpp_amount AS SIGNED) * vat_rate_percent + 50) DIV 100),
    CONSTRAINT chk_invoices_total CHECK (total_amount = subtotal + vat_amount),
    CONSTRAINT chk_invoices_paid CHECK (paid_amount >= 0 AND paid_amount <= total_amount),
    CONSTRAINT chk_invoices_due CHECK (due_date >= invoice_date),
    CONSTRAINT chk_invoices_void CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL) AND (voided_at IS NULL OR paid_amount = 0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    delivery_note_line_id INT UNSIGNED NOT NULL,
    sales_order_item_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_code VARCHAR(30) NOT NULL,
    unit_quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    UNIQUE KEY uq_invoice_lines_note_line (invoice_id, delivery_note_line_id),
    KEY idx_invoice_lines_note_line (delivery_note_line_id),
    CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_invoice_lines_note_line FOREIGN KEY (delivery_note_line_id) REFERENCES delivery_note_lines (id),
    CONSTRAINT fk_invoice_lines_item FOREIGN KEY (sales_order_item_id) REFERENCES sales_order_items (id),
    CONSTRAINT fk_invoice_lines_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_invoice_lines_amount CHECK (unit_quantity > 0 AND unit_price >= 0 AND amount = unit_quantity * unit_price)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    paid_on DATE NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    method ENUM('Transfer', 'Cash', 'Giro', 'Other') NOT NULL,
    reference VARCHAR(100) NOT NULL DEFAULT '',
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_payments_invoice (invoice_id),
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_invoice_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT chk_invoice_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;
