'use strict';

// Baseline measurement for the table filter hot path (Bab 12.2, Contoh 26 pattern:
// mark/measure before reaching for a full profiler). Benchmarks InventoryUi.matchesFilter,
// the per-row cost that dominates InventoryTables' filter() loop in public/assets/js/tables.js,
// since that loop cannot run outside a browser DOM.
//
// Run with: node tests/JavaScript/filter-baseline.bench.js

const {performance} = require('node:perf_hooks');
const {matchesFilter} = require('../../public/assets/js/ui-helpers.js');

function generateRows(count) {
    const skus = ['SKU-ALPHA', 'SKU-BETA', 'SKU-GAMMA', 'SKU-DELTA'];
    const statuses = ['In stock', 'Low stock', 'Backordered'];
    const rows = [];
    for (let i = 0; i < count; i += 1) {
        rows.push(`${skus[i % skus.length]}-${i} ${statuses[i % statuses.length]} Warehouse ${i % 12}`);
    }
    return rows;
}

function runOnce(rows, term) {
    const start = performance.now();
    let visible = 0;
    for (const row of rows) {
        if (matchesFilter(row, term)) visible += 1;
    }
    const duration = performance.now() - start;
    return {duration, visible};
}

function benchmark(rowCount, term, iterations = 20) {
    const rows = generateRows(rowCount);
    // Warm up once so JIT compilation is not counted in the reported figure.
    runOnce(rows, term);

    const durations = [];
    let visible = 0;
    for (let i = 0; i < iterations; i += 1) {
        const result = runOnce(rows, term);
        durations.push(result.duration);
        visible = result.visible;
    }
    durations.sort((a, b) => a - b);
    const median = durations[Math.floor(durations.length / 2)];

    return {
        rows: rowCount,
        matches: visible,
        medianMs: Number(median.toFixed(3)),
        maxMs: Number(durations[durations.length - 1].toFixed(3)),
    };
}

const results = [100, 1000, 5000].map((rowCount) => benchmark(rowCount, 'low stock'));

console.table(results);
console.log(
    'Baseline recorded on', new Date().toISOString().slice(0, 10),
    '— matchesFilter is the per-row cost inside InventoryTables.filter(). No optimization has been',
    'applied yet; this is the reference to compare against before changing the algorithm.'
);
