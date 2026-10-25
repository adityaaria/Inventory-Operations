-- D-18 / ADR-024: barcode lookup and stock count sessions. Additive and idempotent DDL only; the runner
-- scripts/migrate-stock-counts.php verifies the tables and constraints and then records schema version stock-count-1.
-- No existing row is changed. MySQL 8.0 has no ADD CONSTRAINT IF NOT EXISTS, so the lot source CHECK is replaced only
-- when its clause does not yet allow STOCK_COUNT (a superset of the old set: no existing row can violate it).

SET @has := (SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_stock_lots_source' AND check_clause LIKE '%STOCK_COUNT%');
SET @ddl := IF(@has = 0, 'ALTER TABLE stock_lots DROP CHECK chk_stock_lots_source, ADD CONSTRAINT chk_stock_lots_source CHECK (source_type IN (''PO'', ''TRANSFER'', ''ADJUSTMENT'', ''CUSTOMER_RETURN'', ''LEGACY'', ''RECEIPT'', ''STOCK_COUNT''))', 'DO 0');
PREPARE count_step FROM @ddl; EXECUTE count_step; DEALLOCATE PREPARE count_step;

-- D-18 / ADR-024: stock count sessions (user decision, not trainer; not a PO/SO status). A count document has its own
-- lifecycle Draft -> Counting -> Submitted -> (RecountRequested -> Counting -> Submitted) -> Approved -> Posted, with
-- Cancelled before posting. Starting the count snapshots every lot with stock of the scope together with the ledger
-- watermark snapshot_ledger_id; a count line remembers the watermark of its count (count_ledger_id), so
-- expected = snapshot + Σ ledger rows of the lot with snapshot_ledger_id < id <= count_ledger_id and
-- variance = counted - expected. Posting applies the approved variance as a delta to the current lot quantity
-- (StockService, ledger Adjustment rows with reference STOCK_COUNT), never "set to counted".
CREATE TABLE IF NOT EXISTS stock_counts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT UNSIGNED NOT NULL,
    scope ENUM('All', 'Category', 'Products') NOT NULL,
    category_id INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('Draft', 'Counting', 'Submitted', 'RecountRequested', 'Approved', 'Posted', 'Cancelled') NOT NULL DEFAULT 'Draft',
    reason VARCHAR(500) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_by INT UNSIGNED NULL DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    snapshot_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
    submitted_by INT UNSIGNED NULL DEFAULT NULL,
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    recount_reason VARCHAR(500) NULL DEFAULT NULL,
    recount_requested_by INT UNSIGNED NULL DEFAULT NULL,
    recount_requested_at TIMESTAMP NULL DEFAULT NULL,
    -- Recount round: 0 = first count; each recount request raises it, and the lines flagged in that request carry it.
    recount_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
    approved_by INT UNSIGNED NULL DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    approval_note VARCHAR(500) NULL DEFAULT NULL,
    posted_by INT UNSIGNED NULL DEFAULT NULL,
    posted_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_by INT UNSIGNED NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancel_reason VARCHAR(500) NULL DEFAULT NULL,
    KEY idx_stock_counts_status_time (status, created_at),
    KEY idx_stock_counts_warehouse_time (warehouse_id, created_at),
    CONSTRAINT fk_stock_counts_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_stock_counts_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_stock_counts_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_started_by FOREIGN KEY (started_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_recount_by FOREIGN KEY (recount_requested_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_posted_by FOREIGN KEY (posted_by) REFERENCES users (id),
    CONSTRAINT fk_stock_counts_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id),
    CONSTRAINT chk_stock_counts_recount CHECK ((recount_round = 0) = (recount_requested_at IS NULL)),
    CONSTRAINT chk_stock_counts_scope CHECK ((scope = 'Category') = (category_id IS NOT NULL)),
    CONSTRAINT chk_stock_counts_snapshot CHECK (status IN ('Draft', 'Cancelled') OR (snapshot_ledger_id IS NOT NULL AND started_by IS NOT NULL AND started_at IS NOT NULL)),
    CONSTRAINT chk_stock_counts_approved CHECK (status NOT IN ('Approved', 'Posted') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CONSTRAINT chk_stock_counts_posted CHECK ((status = 'Posted') = (posted_by IS NOT NULL AND posted_at IS NOT NULL)),
    CONSTRAINT chk_stock_counts_cancelled CHECK ((status = 'Cancelled') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))
) ENGINE=InnoDB;

-- Products in the scope of a count: the selection of a 'Products' count (written at creation) and, for every scope, the
-- materialized product list at start. Two open counts of one warehouse never share a product (checked at start under
-- the pair locks), so one physical discrepancy is never corrected twice.
CREATE TABLE IF NOT EXISTS stock_count_products (
    count_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (count_id, product_id),
    KEY idx_stock_count_products_product (product_id),
    CONSTRAINT fk_stock_count_products_count FOREIGN KEY (count_id) REFERENCES stock_counts (id),
    CONSTRAINT fk_stock_count_products_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- Users assigned to count (Admin or WarehouseStaff accounts). Only assigned counters may count or recount.
CREATE TABLE IF NOT EXISTS stock_count_counters (
    count_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (count_id, user_id),
    KEY idx_stock_count_counters_user (user_id),
    CONSTRAINT fk_stock_count_counters_count FOREIGN KEY (count_id) REFERENCES stock_counts (id),
    CONSTRAINT fk_stock_count_counters_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_stock_count_counters_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB;

-- One line per lot of the scope with stock at the snapshot ('Snapshot'), or a lot entered by a counter ('Found': an
-- existing lot without stock at the snapshot, or an unknown lot that posting creates). All quantities in base units.
CREATE TABLE IF NOT EXISTS stock_count_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    count_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    lot_id INT UNSIGNED NULL DEFAULT NULL,
    lot_code VARCHAR(60) NOT NULL,
    expiry_date DATE NULL DEFAULT NULL,
    origin ENUM('Snapshot', 'Found') NOT NULL DEFAULT 'Snapshot',
    line_key VARCHAR(100) GENERATED ALWAYS AS (IF(lot_id IS NULL, CONCAT('F:', UPPER(lot_code), '|', COALESCE(expiry_date, '-')), CONCAT('L:', lot_id))) STORED,
    snapshot_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    counted_quantity INT UNSIGNED NULL DEFAULT NULL,
    counted_unit_code VARCHAR(30) NULL DEFAULT NULL,
    counted_unit_quantity INT UNSIGNED NULL DEFAULT NULL,
    counted_by INT UNSIGNED NULL DEFAULT NULL,
    counted_at TIMESTAMP NULL DEFAULT NULL,
    count_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
    expected_quantity BIGINT NULL DEFAULT NULL,
    recount_requested TINYINT UNSIGNED NOT NULL DEFAULT 0,
    recounted_quantity INT UNSIGNED NULL DEFAULT NULL,
    recounted_unit_code VARCHAR(30) NULL DEFAULT NULL,
    recounted_unit_quantity INT UNSIGNED NULL DEFAULT NULL,
    recounted_by INT UNSIGNED NULL DEFAULT NULL,
    recounted_at TIMESTAMP NULL DEFAULT NULL,
    recount_ledger_id BIGINT UNSIGNED NULL DEFAULT NULL,
    recount_expected_quantity BIGINT NULL DEFAULT NULL,
    variance BIGINT GENERATED ALWAYS AS (CAST(COALESCE(recounted_quantity, counted_quantity) AS SIGNED) - COALESCE(recount_expected_quantity, expected_quantity)) STORED,
    approved_variance BIGINT NULL DEFAULT NULL,
    posted_lot_id INT UNSIGNED NULL DEFAULT NULL,
    posted_delta BIGINT NULL DEFAULT NULL,
    posted_unit_cost DECIMAL(14,2) NULL DEFAULT NULL,
    pair_quantity_after INT UNSIGNED NULL DEFAULT NULL,
    pair_reserved_at_post INT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uq_stock_count_lines_key (count_id, product_id, line_key),
    KEY idx_stock_count_lines_lot (lot_id),
    KEY idx_stock_count_lines_posted_lot (posted_lot_id),
    CONSTRAINT fk_stock_count_lines_count FOREIGN KEY (count_id) REFERENCES stock_counts (id),
    CONSTRAINT fk_stock_count_lines_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_stock_count_lines_lot FOREIGN KEY (lot_id) REFERENCES stock_lots (id),
    CONSTRAINT fk_stock_count_lines_posted_lot FOREIGN KEY (posted_lot_id) REFERENCES stock_lots (id),
    CONSTRAINT fk_stock_count_lines_counted_by FOREIGN KEY (counted_by) REFERENCES users (id),
    CONSTRAINT fk_stock_count_lines_recounted_by FOREIGN KEY (recounted_by) REFERENCES users (id),
    CONSTRAINT chk_stock_count_lines_code CHECK (CHAR_LENGTH(TRIM(lot_code)) >= 1),
    CONSTRAINT chk_stock_count_lines_origin CHECK (origin = 'Found' OR lot_id IS NOT NULL),
    CONSTRAINT chk_stock_count_lines_new_lot CHECK (lot_id IS NOT NULL OR snapshot_quantity = 0),
    CONSTRAINT chk_stock_count_lines_count CHECK ((counted_quantity IS NULL) = (counted_by IS NULL) AND (counted_quantity IS NULL) = (counted_at IS NULL)
        AND (counted_quantity IS NULL) = (count_ledger_id IS NULL) AND (counted_quantity IS NULL) = (expected_quantity IS NULL)),
    CONSTRAINT chk_stock_count_lines_recount CHECK ((recounted_quantity IS NULL) = (recounted_by IS NULL) AND (recounted_quantity IS NULL) = (recounted_at IS NULL)
        AND (recounted_quantity IS NULL) = (recount_ledger_id IS NULL) AND (recounted_quantity IS NULL) = (recount_expected_quantity IS NULL)
        AND (recounted_quantity IS NULL OR (counted_quantity IS NOT NULL AND recount_requested >= 1))),
    -- A recount is always made by a different user than the first count (D-18 decision: enforced, not only warned).
    CONSTRAINT chk_stock_count_lines_recounter CHECK (recounted_by IS NULL OR recounted_by <> counted_by),
    CONSTRAINT chk_stock_count_lines_expected CHECK ((expected_quantity IS NULL OR expected_quantity >= 0) AND (recount_expected_quantity IS NULL OR recount_expected_quantity >= 0)),
    CONSTRAINT chk_stock_count_lines_approved CHECK (approved_variance IS NULL OR approved_variance = variance),
    CONSTRAINT chk_stock_count_lines_posted CHECK (posted_delta IS NULL OR (approved_variance IS NOT NULL AND posted_delta = approved_variance)),
    -- No posted count may leave the product below its reservation (D-12 / ADR-018).
    CONSTRAINT chk_stock_count_lines_reservation CHECK (pair_quantity_after IS NULL OR (pair_reserved_at_post IS NOT NULL AND pair_quantity_after >= pair_reserved_at_post))
) ENGINE=InnoDB;
