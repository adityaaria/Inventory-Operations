-- D-11 / ADR-017: server-assigned order numbers PREFIX-YYMM-NNNN. Additive and idempotent: creates the counter table
-- when missing and raises each counter to the highest existing number of the exact generated pattern. Existing order
-- numbers (including legacy/manual ones such as PO-20261009141927) are never changed. Counters are never lowered.
CREATE TABLE IF NOT EXISTS document_sequences (
    prefix VARCHAR(10) NOT NULL,
    period CHAR(4) NOT NULL,
    last_number INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (prefix, period),
    CONSTRAINT chk_document_sequences_prefix CHECK (prefix IN ('PO', 'SO')),
    CONSTRAINT chk_document_sequences_period CHECK (period REGEXP '^[0-9]{4}$')
) ENGINE=InnoDB;

-- Matching is case-insensitive (like the order_number collation), so a legacy "po-2610-0040" also raises the PO
-- counter and can never collide with a generated "PO-2610-0040". 4-10 digits keeps the value inside INT UNSIGNED;
-- LEAST() clamps the extreme 10-digit case so the counter saturates (next claim fails) instead of wrapping.
INSERT INTO document_sequences (prefix, period, last_number)
SELECT existing.prefix, existing.period, existing.max_value FROM (
    SELECT matched.prefix, matched.period, MAX(matched.num) AS max_value FROM (
        SELECT 'PO' AS prefix, SUBSTRING(order_number, 4, 4) AS period,
               LEAST(CAST(SUBSTRING(order_number, 9) AS UNSIGNED), 4294967295) AS num
        FROM purchase_orders WHERE REGEXP_LIKE(order_number, '^PO-[0-9]{4}-[0-9]{4,10}$', 'i')
        UNION ALL
        SELECT 'SO', SUBSTRING(order_number, 4, 4),
               LEAST(CAST(SUBSTRING(order_number, 9) AS UNSIGNED), 4294967295)
        FROM sales_orders WHERE REGEXP_LIKE(order_number, '^SO-[0-9]{4}-[0-9]{4,10}$', 'i')
    ) matched GROUP BY matched.prefix, matched.period
) existing
ON DUPLICATE KEY UPDATE last_number = GREATEST(document_sequences.last_number, existing.max_value);

INSERT INTO schema_versions (version, description)
VALUES ('order-number-1', 'Server-assigned order numbers PO/SO-YYMM-NNNN (D-11, ADR-017)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
