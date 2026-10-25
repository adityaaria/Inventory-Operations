const test = require('node:test');
const assert = require('node:assert/strict');
const scan = require('../../public/assets/js/barcode-scan.js');

// D-18 / ADR-024: barcode scanning for receiving, picking and stock counts.
const scanOf = (overrides = {}) => ({
    barcode: '8991000202636',
    product: { id: 10, sku: 'SKU-010', name: 'Susu UHT', is_active: true, base_unit: 'pcs', tracks_expiry: true },
    unit: { code: 'karton', factor_to_base: 24, is_base: false, is_active: true },
    usable: true,
    ...overrides,
});
const pcs = scanOf({ barcode: '8991000202612', unit: { code: 'pcs', factor_to_base: 1, is_base: true, is_active: true } });

test('barcode validation mirrors the server: alphabet, length and the EAN-13 check digit', () => {
    assert.equal(scan.ean13CheckDigit('899100020261'), 2);
    assert.deepEqual(scan.validateBarcode(' 8991000202612 '), { ok: true, code: '8991000202612' });
    assert.equal(scan.validateBarcode('SOAP-BOX-12').ok, true);
    for (const invalid of ['', 'abc', '12 34', 'x'.repeat(65), 'Ü-1234']) assert.equal(scan.validateBarcode(invalid).ok, false, invalid);
    const misread = scan.validateBarcode('8991000202613');
    assert.equal(misread.ok, false);
    assert.match(misread.message, /check digit/);
});

test('unit conversion is exact only: a karton fills a pcs line, a piece never fills a karton line', () => {
    assert.equal(scan.lineUnits(24, 1), 24);
    assert.equal(scan.lineUnits(24, 12), 2);
    assert.equal(scan.lineUnits(24, 24), 1);
    assert.equal(scan.lineUnits(1, 24), null);
    assert.equal(scan.lineUnits(12, 24), null);
});

test('receive scans increment the matching line in its unit and refuse unknown products, inexact units and over-receipt', () => {
    const lines = [{ key: '7', productId: '10', factor: '24', unitCode: 'karton', quantity: '', max: '3' }, { key: '8', productId: '11', factor: '1', unitCode: 'pcs', quantity: '0', max: '50' }];
    const first = scan.applyLineScan(lines, scanOf(), 'receive');
    assert.deepEqual([first.ok, first.key, first.quantity, first.tone], [true, '7', 1, 'success']);
    assert.match(first.message, /→ 1 of 3 karton to receive/);
    const piece = scan.applyLineScan(lines, pcs, 'receive');
    assert.equal(piece.ok, false);
    assert.match(piece.message, /not a whole karton/);
    const unknown = scan.applyLineScan(lines, scanOf({ product: { ...scanOf().product, id: 99, sku: 'SKU-099' } }), 'receive');
    assert.equal(unknown.ok, false);
    assert.match(unknown.message, /not on this purchase order. Nothing was changed/);
    const full = scan.applyLineScan([{ ...lines[0], quantity: '3' }], scanOf(), 'receive');
    assert.equal(full.ok, false);
    assert.match(full.message, /only 3 karton remain/);
    const inactive = scan.applyLineScan(lines, scanOf({ usable: false, unit: { ...scanOf().unit, is_active: false } }), 'receive');
    assert.equal(inactive.ok, false);
    assert.match(inactive.message, /unit karton is inactive/);
});

test('pick scans count per line, warn on over-pick and flag wrong products', () => {
    const lines = [{ key: '3', productId: 10, factor: 1, unitCode: 'pcs', quantity: 0, max: 30 }];
    const counted = scan.applyLineScan(lines, scanOf(), 'pick');
    assert.deepEqual([counted.ok, counted.quantity, counted.tone], [true, 24, 'success'], 'one karton = 24 pcs picked');
    const over = scan.applyLineScan([{ ...lines[0], quantity: 24 }], scanOf(), 'pick');
    assert.deepEqual([over.ok, over.quantity, over.tone], [true, 48, 'warning']);
    assert.match(over.message, /Over-pick.*Put 18 pcs back; the issue quantity stays at 30/);
    const wrong = scan.applyLineScan(lines, scanOf({ product: { ...scanOf().product, id: 5, sku: 'SKU-005', name: 'Kopi' } }), 'pick');
    assert.equal(wrong.ok, false);
    assert.match(wrong.message, /^Wrong product: SKU-005/);
});

test('count scans add to the only lot line, ask for a lot when there are several, and stay exact across units', () => {
    const base = { productId: 10, unitCode: 'karton', unitFactor: 24, baseCode: 'pcs' };
    const single = scan.applyCountScan([{ key: '1', lot: 'A-1', base: 48, ...base }], scanOf(), null);
    assert.deepEqual([single.ok, single.quantity, single.unitCode, single.base], [true, 3, 'karton', 72]);
    const switched = scan.applyCountScan([{ key: '1', lot: 'A-1', base: 48, ...base }], pcs, null);
    assert.deepEqual([switched.ok, switched.quantity, switched.unitCode, switched.switched, switched.tone], [true, 49, 'pcs', true, 'warning']);
    const lines = [{ key: '1', lot: 'A-1', base: null, ...base }, { key: '2', lot: 'B-1', base: null, ...base }];
    const ambiguous = scan.applyCountScan(lines, scanOf(), null);
    assert.equal(ambiguous.ok, false);
    assert.deepEqual(ambiguous.candidates, ['1', '2']);
    const chosen = scan.applyCountScan(lines, scanOf(), '2');
    assert.deepEqual([chosen.ok, chosen.key, chosen.quantity], [true, '2', 1]);
    const missing = scan.applyCountScan(lines, scanOf({ product: { ...scanOf().product, id: 11 } }), null);
    assert.equal(missing.ok, false);
    assert.equal(missing.productId, 11);
    assert.match(missing.message, /found lot/);
});

test('lookup maps API statuses to clear messages and survives network errors', async () => {
    const respond = (status, body) => async () => ({ ok: status === 200, status, json: async () => body });
    let requested = null;
    const ok = await scan.lookup('8991000202636', async (url, options) => { requested = [url, options.headers['X-Requested-With']]; return { ok: true, status: 200, json: async () => scanOf() }; });
    assert.deepEqual(requested, ['/api/barcodes/8991000202636', 'fetch']);
    assert.equal(ok.ok, true);
    assert.equal(ok.data.unit.factor_to_base, 24);
    assert.match((await scan.lookup('X-1234', respond(404, { error: 'Barcode not found.' }))).message, /Unknown barcode X-1234/);
    assert.match((await scan.lookup('X-1234', respond(401, {}))).message, /session has ended/);
    assert.match((await scan.lookup('X-1234', respond(403, {}))).message, /cannot scan/);
    assert.match((await scan.lookup('X-1234', respond(422, { error: 'Bad code.' }))).message, /Bad code/);
    assert.match((await scan.lookup('X-1234', async () => { throw new Error('offline'); })).message, /Network error/);
});

/** Minimal element: an EventTarget with dataset, value and querySelector over named children. */
function element(children = {}) {
    const node = new EventTarget();
    node.dataset = {};
    node.value = '';
    node.textContent = '';
    node.hidden = true;
    node.querySelector = selector => children[selector] || null;
    node.setAttribute = () => {};
    return node;
}

test('the scanner component accepts keyboard-wedge input with Enter, announces in the live region and dispatches events', async () => {
    const input = element();
    const status = element();
    const panel = element({ '[data-scan-input]': input, '[data-scan-status]': status });
    const seen = [];
    panel.addEventListener('barcodescan', event => seen.push(['scan', event.detail.unit.code]));
    panel.addEventListener('barcodeerror', event => seen.push(['error', event.detail.code]));
    const scanner = scan.createScanner(panel, { lookup: async code => (code === '8991000202636' ? { ok: true, data: scanOf() } : { ok: false, message: 'Unknown barcode ' + code + '. Nothing was changed.' }), scope: {} });
    let prevented = false;
    input.value = '8991000202636';
    input.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Enter', preventDefault: () => { prevented = true; } }));
    assert.equal(input.value, '', 'the field is cleared for the next scan');
    assert.equal(prevented, true, 'Enter never submits a surrounding form');
    await scanner.handle('8991000202629');
    await scanner.handle('12 34');
    assert.deepEqual(seen, [['scan', 'karton'], ['error', '8991000202629'], ['error', '12 34']]);
    assert.equal(status.dataset.tone, 'error');
    assert.match(status.textContent, /4–64 letters/);
    input.value = 'ignored';
    input.dispatchEvent(Object.assign(new Event('keydown'), { key: 'a' }));
    assert.equal(input.value, 'ignored', 'other keys are typed normally');
});

test('the camera is offered only when BarcodeDetector and getUserMedia exist (Safari/iOS fall back to the scanner)', () => {
    assert.equal(scan.cameraSupported({}), false);
    assert.equal(scan.cameraSupported({ BarcodeDetector: class {}, navigator: {} }), false);
    assert.equal(scan.cameraSupported({ BarcodeDetector: class {}, navigator: { mediaDevices: { getUserMedia: () => {} } } }), true);
    const button = element();
    const video = element();
    const panel = element({ '[data-scan-camera]': button, '[data-scan-video]': video });
    scan.createScanner(panel, { lookup: async () => ({ ok: false, message: 'x' }), scope: {} });
    assert.equal(button.hidden, true, 'no camera button without BarcodeDetector');
});
