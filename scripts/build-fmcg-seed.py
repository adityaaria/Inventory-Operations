#!/usr/bin/env python3
"""Rebuilds the generated FMCG section of database/schema-and-seed.sql.

Runs scripts/generate-fmcg-seed.php in the quality test container against the disposable test database (all stock
through StockService and the ledger), then dumps the data below the marker. Deterministic: same code, same output.
"""
import argparse
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEED = ROOT / 'database' / 'schema-and-seed.sql'
MARKER = '-- ==== Generated FMCG seed'
TABLES = ['users', 'categories', 'warehouses', 'suppliers', 'customers', 'products', 'product_units', 'product_stocks', 'purchase_orders',
          'purchase_order_items', 'sales_orders', 'sales_order_items', 'stock_ledger', 'audit_logs',
          'purchase_order_closures', 'sales_order_rejections', 'sales_order_closures', 'document_sequences',
          'inventory_operations', 'inventory_operation_items', 'stock_lots', 'inventory_operation_lots',
          'stock_counts', 'stock_count_products', 'stock_count_counters', 'stock_count_lines',
          'price_lists', 'price_list_items', 'quotations', 'quotation_items', 'delivery_notes', 'delivery_note_lines',
          'delivery_note_lots', 'invoices', 'invoice_lines', 'invoice_payments']


def run(command, **kwargs):
    return subprocess.run(command, cwd=ROOT, check=True, **kwargs)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default='docker')
    args = parser.parse_args()
    compose = [args.docker, 'compose', '--profile', 'quality']
    text = SEED.read_text()
    if MARKER not in text:
        sys.exit('Marker missing in database/schema-and-seed.sql')
    head = text[:text.index(MARKER)]
    # The generator reads the schema part of the file, so the image must contain the current version.
    SEED.write_text(head + MARKER + ' (scripts/build-fmcg-seed.py) ====\n')
    run(compose + ['up', '-d', '--wait', 'test-db'])
    run(compose + ['run', '--build', '--rm', 'test', 'php', 'scripts/generate-fmcg-seed.php'])
    dump = run(compose + ['exec', '-T', 'test-db', 'sh', '-c',
                          'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --user=root --no-create-info --skip-triggers '
                          '--complete-insert --compact --skip-tz-utc --net-buffer-length=65536 "$MYSQL_DATABASE" ' + ' '.join(TABLES)],
               stdout=subprocess.PIPE).stdout.decode()
    SEED.write_text(head + MARKER + ' (scripts/build-fmcg-seed.py) ====\nSET FOREIGN_KEY_CHECKS=0;\n' + dump.strip() + '\nSET FOREIGN_KEY_CHECKS=1;\n')
    print(f'Wrote {SEED.relative_to(ROOT)} ({SEED.stat().st_size // 1024} KiB)')


if __name__ == '__main__':
    main()
