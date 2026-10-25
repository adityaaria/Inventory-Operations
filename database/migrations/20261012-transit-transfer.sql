-- D-15 / ADR-021: two-step warehouse transfers with goods in transit. Additive and idempotent.
--   inventory_operations.status        gains 'Dispatched' and 'Received' (operation-document statuses, user decision;
--                                       the brief's PO/SO status sets are unchanged);
--   inventory_operations                dispatched_by/at, received_by/at, receipt_reason (discrepancy reason);
--   inventory_operation_items           dispatched_quantity, received_quantity, damaged_quantity, short_quantity.
-- Existing data: a Posted transfer was applied atomically (source -qty and destination +qty in one transaction), so it
-- becomes Received with dispatched = received = quantity and nothing written off; dispatched/received by/at copy
-- posted_by/posted_at. Approved-but-not-posted transfers stay Approved and follow the new flow. Stock quantities and
-- ledger rows are never changed. scripts/migrate-transit-transfer.php verifies the transit invariants and records schema
-- version 'transit-transfer-1' only when they hold.
-- MySQL 8.0 has no ADD COLUMN IF NOT EXISTS, so each step checks information_schema and runs a no-op when present.

SET @has_status := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_operations' AND column_name = 'status' AND column_type LIKE '%''Received''%');
SET @ddl := IF(@has_status = 0,
    'ALTER TABLE inventory_operations MODIFY COLUMN status ENUM(''PendingApproval'',''Approved'',''Rejected'',''Posted'',''Cancelled'',''Dispatched'',''Received'') NOT NULL DEFAULT ''PendingApproval''',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

SET @has_header := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_operations' AND column_name = 'dispatched_by');
SET @ddl := IF(@has_header = 0,
    'ALTER TABLE inventory_operations ADD COLUMN dispatched_by INT UNSIGNED NULL, ADD COLUMN dispatched_at TIMESTAMP NULL, ADD COLUMN received_by INT UNSIGNED NULL, ADD COLUMN received_at TIMESTAMP NULL, ADD COLUMN receipt_reason VARCHAR(500) NULL, ADD CONSTRAINT fk_inventory_operations_dispatched_by FOREIGN KEY (dispatched_by) REFERENCES users(id), ADD CONSTRAINT fk_inventory_operations_received_by FOREIGN KEY (received_by) REFERENCES users(id)',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

SET @has_items := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_operation_items' AND column_name = 'dispatched_quantity');
SET @ddl := IF(@has_items = 0,
    'ALTER TABLE inventory_operation_items ADD COLUMN dispatched_quantity INT UNSIGNED NULL, ADD COLUMN received_quantity INT UNSIGNED NULL, ADD COLUMN damaged_quantity INT UNSIGNED NULL, ADD COLUMN short_quantity INT UNSIGNED NULL',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

-- Historical Posted transfers: already applied in full, so they are complete receipts without write-off.
UPDATE inventory_operation_items i INNER JOIN inventory_operations o ON o.id = i.operation_id
SET i.dispatched_quantity = i.quantity, i.received_quantity = i.quantity, i.damaged_quantity = 0, i.short_quantity = 0
WHERE o.kind = 'Transfer' AND o.status = 'Posted' AND i.dispatched_quantity IS NULL;

UPDATE inventory_operations
SET status = 'Received', dispatched_by = posted_by, dispatched_at = posted_at, received_by = posted_by, received_at = posted_at
WHERE kind = 'Transfer' AND status = 'Posted';

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'inventory_operations' AND constraint_name = 'chk_operation_transit_status');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE inventory_operations ADD CONSTRAINT chk_operation_transit_status CHECK ((kind=''Transfer'' AND status<>''Posted'') OR (kind<>''Transfer'' AND status NOT IN (''Dispatched'',''Received'')))',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'inventory_operations' AND constraint_name = 'chk_operation_transit_stamps');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE inventory_operations ADD CONSTRAINT chk_operation_transit_stamps CHECK (((status IN (''Dispatched'',''Received'')) = (dispatched_by IS NOT NULL AND dispatched_at IS NOT NULL)) AND ((status=''Received'') = (received_by IS NOT NULL AND received_at IS NOT NULL)))',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'inventory_operation_items' AND constraint_name = 'chk_operation_items_dispatched');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE inventory_operation_items ADD CONSTRAINT chk_operation_items_dispatched CHECK (dispatched_quantity IS NULL OR dispatched_quantity = quantity)',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;

SET @has_check := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'inventory_operation_items' AND constraint_name = 'chk_operation_items_receipt');
SET @ddl := IF(@has_check = 0,
    'ALTER TABLE inventory_operation_items ADD CONSTRAINT chk_operation_items_receipt CHECK ((received_quantity IS NULL AND damaged_quantity IS NULL AND short_quantity IS NULL) OR (dispatched_quantity IS NOT NULL AND received_quantity IS NOT NULL AND damaged_quantity IS NOT NULL AND short_quantity IS NOT NULL AND received_quantity + damaged_quantity + short_quantity = dispatched_quantity))',
    'DO 0');
PREPARE transit_step FROM @ddl;
EXECUTE transit_step;
DEALLOCATE PREPARE transit_step;
