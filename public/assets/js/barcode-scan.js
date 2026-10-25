'use strict';

/**
 * Barcode scanning (D-18, ADR-024). Primary input: USB/Bluetooth keyboard-wedge scanners, which type the code into the
 * focused field and press Enter. Optional: the camera through the native BarcodeDetector API, offered only when the
 * browser has it (Safari/iOS do not) and failing gracefully. A scan is resolved by GET /api/barcodes/{code} and announced
 * in an aria-live region; pages react to the `barcodescan` event:
 *
 * - [data-scan-receive] (PO detail): increments the matching line's receive quantity in the line's unit;
 * - [data-scan-pick] (SO detail, "scan to pick"): counts picked units per line and prefills the issue quantities;
 * - [data-scan-count] (stock count sheet): adds the scanned units to the counted quantity of the lot line.
 *
 * Conversions between units are exact only: a scanned unit is applied when its factor is a whole multiple of the line
 * unit's factor (a karton of 24 pcs on a pcs line adds 24; a pcs on a karton line is refused with a message). The server
 * stays authoritative for every document (receipt, FEFO issue, reservation, counts). Scan counts are never stored as form
 * drafts (ADR-014): the receive/issue forms have no drafts and the count sheet saves counts on the server.
 */
(function exposeBarcodeScan(root) {
    const PATTERN = /^[A-Za-z0-9-]{4,64}$/;

    function ean13CheckDigit(twelve) {
        let sum = 0;
        for (let index = 0; index < 12; index++) sum += Number(twelve[index]) * (index % 2 === 0 ? 1 : 3);
        return (10 - (sum % 10)) % 10;
    }

    /** Mirrors BarcodeService::normalize() on the server. */
    function validateBarcode(raw) {
        const code = String(raw ?? '').trim();
        if (!PATTERN.test(code)) return { ok: false, code, message: 'A barcode is 4–64 letters, digits or dashes.' };
        if (/^\d{13}$/.test(code) && ean13CheckDigit(code.slice(0, 12)) !== Number(code[12])) {
            return { ok: false, code, message: 'Barcode ' + code + ' has an invalid EAN-13 check digit; scan it again.' };
        }
        return { ok: true, code };
    }

    /** Whole line units added by one scanned unit, or null when the conversion is not exact. */
    function lineUnits(scannedFactor, lineFactor) {
        const scanned = Number(scannedFactor) || 1;
        const line = Number(lineFactor) || 1;
        return scanned % line === 0 ? scanned / line : null;
    }

    function productLabel(scan) {
        return scan.product.sku + ' — ' + scan.product.name;
    }

    function unusable(scan) {
        if (scan.usable) return null;
        return { ok: false, tone: 'error', message: productLabel(scan) + ': ' + (scan.product.is_active ? 'unit ' + scan.unit.code + ' is inactive' : 'the product is inactive') + '. Nothing was changed.' };
    }

    /**
     * One scan applied to order lines [{key, productId, factor, unitCode, quantity, max}] (quantities in line units).
     * 'receive' refuses beyond the remaining quantity; 'pick' counts beyond it with an over-pick warning.
     */
    function applyLineScan(lines, scan, mode) {
        const refused = unusable(scan);
        if (refused) return refused;
        const line = lines.find(candidate => Number(candidate.productId) === Number(scan.product.id));
        if (!line) {
            return { ok: false, tone: 'error', message: mode === 'pick'
                ? 'Wrong product: ' + productLabel(scan) + ' is not on this order. Do not pick it; nothing was counted.'
                : productLabel(scan) + ' is not on this purchase order. Nothing was changed.' };
        }
        const add = lineUnits(scan.unit.factor_to_base, line.factor);
        if (add === null) {
            return { ok: false, tone: 'error', key: line.key, message: '1 ' + scan.unit.code + ' (' + scan.unit.factor_to_base + ' ' + scan.product.base_unit + ') is not a whole ' + line.unitCode
                + ' (' + line.factor + ' ' + scan.product.base_unit + '). Scan the ' + line.unitCode + ' barcode or enter the quantity by hand; nothing was changed.' };
        }
        const next = (Number(line.quantity) || 0) + add;
        const max = Number(line.max);
        if (mode === 'receive' && next > max) {
            return { ok: false, tone: 'error', key: line.key, message: productLabel(scan) + ': only ' + max + ' ' + line.unitCode + ' remain to be received and ' + (Number(line.quantity) || 0) + ' are already scanned. Nothing was changed.' };
        }
        const unitText = add === 1 ? '' : ' (' + add + ' ' + line.unitCode + ')';
        if (mode === 'pick' && next > max) {
            return { ok: true, tone: 'warning', key: line.key, quantity: next, message: 'Over-pick: ' + productLabel(scan) + ' now ' + next + ' of ' + max + ' ' + line.unitCode + '. Put ' + (next - max) + ' ' + line.unitCode + ' back; the issue quantity stays at ' + max + '.' };
        }
        return { ok: true, tone: 'success', key: line.key, quantity: next, message: productLabel(scan) + ': +1 ' + scan.unit.code + unitText + ' → ' + next + ' of ' + max + ' ' + line.unitCode + (mode === 'pick' ? ' picked.' : ' to receive.') };
    }

    /**
     * One scan on a count sheet: lines [{key, productId, base (counted base units or null), unitCode, unitFactor, baseCode}].
     * Goes to the only lot line of the product, or to the selected one when the product has several lots. The total is
     * kept in the line's unit when exact, otherwise the line switches to the base unit (always exact).
     */
    function applyCountScan(lines, scan, selectedKey) {
        const refused = unusable(scan);
        if (refused) return refused;
        const matches = lines.filter(line => Number(line.productId) === Number(scan.product.id));
        if (matches.length === 0) {
            return { ok: false, tone: 'warning', productId: Number(scan.product.id), message: productLabel(scan) + ' has no lot on this count sheet. If you found it, enter it below as a found lot.' };
        }
        const target = matches.length === 1 ? matches[0] : matches.find(line => line.key === selectedKey);
        if (!target) {
            return { ok: false, tone: 'warning', candidates: matches.map(line => line.key), message: matches.length + ' lots of ' + productLabel(scan) + ' are on the sheet: select the quantity field of the lot you are counting, then scan again.' };
        }
        const base = (Number(target.base) || 0) + Number(scan.unit.factor_to_base);
        const factor = Number(target.unitFactor) || 1;
        const exact = base % factor === 0;
        const quantity = exact ? base / factor : base;
        const unitCode = exact ? target.unitCode : target.baseCode;
        return { ok: true, tone: exact ? 'success' : 'warning', key: target.key, base, quantity, unitCode, switched: !exact,
            message: productLabel(scan) + ' lot ' + (target.lot || '') + ': +1 ' + scan.unit.code + ' → ' + quantity + ' ' + unitCode + (exact ? '' : ' (switched to ' + target.baseCode + ' to keep the count exact)') + '.' };
    }

    /** GET /api/barcodes/{code}: {ok, data} or {ok: false, message}. */
    async function lookup(code, fetchImpl) {
        const run = fetchImpl || (root.fetch ? root.fetch.bind(root) : null);
        if (!run) return { ok: false, message: 'Barcode lookup is unavailable in this browser.' };
        let response;
        try {
            response = await run('/api/barcodes/' + encodeURIComponent(code), { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
        } catch (error) {
            return { ok: false, message: 'Network error while looking up ' + code + '; nothing was changed. Try again.' };
        }
        let body = {};
        try { body = await response.json(); } catch (error) { body = {}; }
        if (response.ok) return { ok: true, data: body };
        const messages = {
            401: 'Your session has ended. Sign in again to continue.',
            403: 'Your role cannot scan barcodes.',
            404: 'Unknown barcode ' + code + '. Nothing was changed.',
        };
        return { ok: false, status: response.status, message: messages[response.status] || body.error || ('Barcode lookup failed (' + response.status + ').') };
    }

    function cameraSupported(scope) {
        return Boolean(scope && 'BarcodeDetector' in scope && scope.navigator && scope.navigator.mediaDevices && typeof scope.navigator.mediaDevices.getUserMedia === 'function');
    }

    /**
     * Wires one scan panel: the input accepts scanner (or typed) input and Enter, validates, resolves it and dispatches
     * `barcodescan` (detail: lookup data) or `barcodeerror` on the panel. Returns {handle, announce}.
     */
    function createScanner(panel, options = {}) {
        const input = panel.querySelector('[data-scan-input]');
        const status = panel.querySelector('[data-scan-status]');
        const doLookup = options.lookup || (code => lookup(code, options.fetchImpl));
        const EventType = options.CustomEvent || root.CustomEvent;
        let busy = Promise.resolve();

        function announce(message, tone) {
            if (!status) return;
            status.textContent = message;
            status.dataset.tone = tone || 'info';
        }

        async function resolve(raw) {
            const checked = validateBarcode(raw);
            if (!checked.ok) {
                announce(checked.message, 'error');
                panel.dispatchEvent(new EventType('barcodeerror', { detail: { code: checked.code, message: checked.message } }));
                return null;
            }
            announce('Looking up ' + checked.code + '…', 'info');
            const result = await doLookup(checked.code);
            if (!result.ok) {
                announce(result.message, 'error');
                panel.dispatchEvent(new EventType('barcodeerror', { detail: { code: checked.code, message: result.message } }));
                return null;
            }
            panel.dispatchEvent(new EventType('barcodescan', { detail: result.data }));
            return result.data;
        }

        // Scans are handled one after the other, so fast scanners never apply two scans to stale line values.
        function handle(raw) {
            busy = busy.then(() => resolve(raw), () => resolve(raw));
            return busy;
        }

        if (input) {
            input.addEventListener('keydown', event => {
                if (event.key !== 'Enter') return;
                event.preventDefault(); // never submits a surrounding form
                const value = input.value;
                input.value = '';
                handle(value);
            });
        }
        setupCamera(panel, handle, announce, options.scope || root);
        panel.scanner = { handle, announce };
        return panel.scanner;
    }

    /** Optional camera scanning; hidden unless BarcodeDetector and getUserMedia exist. */
    function setupCamera(panel, handle, announce, scope) {
        const button = panel.querySelector('[data-scan-camera]');
        const video = panel.querySelector('[data-scan-video]');
        if (!button || !video || !cameraSupported(scope)) return;
        button.hidden = false;
        let stream = null;
        let timer = null;
        const stop = () => {
            if (timer) scope.clearTimeout(timer);
            timer = null;
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            video.hidden = true;
            button.textContent = 'Scan with camera';
            button.setAttribute('aria-pressed', 'false');
        };
        button.addEventListener('click', async () => {
            if (stream) { stop(); return; }
            try {
                const detector = new scope.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'code_128', 'code_39'] });
                stream = await scope.navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                video.srcObject = stream;
                video.hidden = false;
                await video.play();
                button.textContent = 'Stop camera';
                button.setAttribute('aria-pressed', 'true');
                announce('Camera on: hold the barcode in front of the camera.', 'info');
                const tick = async () => {
                    if (!stream) return;
                    try {
                        const codes = await detector.detect(video);
                        if (codes.length > 0) {
                            const value = codes[0].rawValue;
                            stop();
                            handle(value);
                            return;
                        }
                    } catch (error) { /* frame not ready; try the next one */ }
                    timer = scope.setTimeout(tick, 250);
                };
                tick();
            } catch (error) {
                stop();
                announce('Camera unavailable (' + (error && error.name ? error.name : 'error') + '). Use a barcode scanner or type the code and press Enter.', 'warning');
            }
        });
    }

    function highlight(element) {
        if (!element) return;
        element.classList.add('is-scanned');
        root.setTimeout?.(() => element.classList.remove('is-scanned'), 1600);
    }

    // ---- Page behaviours --------------------------------------------------------------------------------------------

    function enhanceReceive(panel) {
        const scanner = createScanner(panel);
        const forms = () => [...panel.ownerDocument.querySelectorAll('[data-scan-line]')];
        panel.addEventListener('barcodescan', event => {
            const lines = forms().map(form => ({
                key: form.dataset.scanLine, productId: form.dataset.productId, factor: form.dataset.unitFactor, unitCode: form.dataset.unitCode,
                quantity: form.querySelector('input[name="quantity"]').value, max: form.dataset.remaining, form,
            }));
            const result = applyLineScan(lines, event.detail, 'receive');
            if (result.ok) {
                const line = lines.find(candidate => candidate.key === result.key);
                const field = line.form.querySelector('input[name="quantity"]');
                field.value = String(result.quantity);
                field.dispatchEvent(new root.Event('input', { bubbles: true }));
                highlight(line.form.closest('tr'));
            }
            scanner.announce(result.message, result.tone);
        });
    }

    function enhancePick(panel) {
        const scanner = createScanner(panel);
        const toggle = panel.querySelector('[data-pick-toggle]');
        const body = panel.querySelector('[data-pick-body]');
        const inputs = () => [...panel.ownerDocument.querySelectorAll('[data-pick-line]')];
        const picked = new Map();
        const render = () => {
            for (const input of inputs()) {
                const count = picked.get(input.dataset.pickLine) || 0;
                const max = Number(input.max) || 0;
                input.value = String(Math.min(count, max));
                const badge = panel.ownerDocument.querySelector('[data-picked-for="' + input.dataset.pickLine + '"]');
                if (badge) {
                    badge.hidden = false;
                    badge.textContent = 'Picked ' + count + ' of ' + max + ' ' + input.dataset.unitCode;
                    badge.dataset.tone = count > max ? 'warning' : (count === max ? 'success' : 'info');
                }
            }
        };
        toggle?.addEventListener('click', () => {
            const on = toggle.getAttribute('aria-pressed') !== 'true';
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            toggle.textContent = on ? 'Stop scan to pick' : 'Start scan to pick';
            if (body) body.hidden = !on;
            if (on) {
                picked.clear();
                render();
                scanner.announce('Scan to pick: issue quantities start at 0 and follow the picked counts.', 'info');
                panel.querySelector('[data-scan-input]')?.focus();
            } else {
                scanner.announce('Scan to pick stopped; the picked quantities stay in the issue form.', 'info');
            }
        });
        panel.addEventListener('barcodescan', event => {
            if (toggle && toggle.getAttribute('aria-pressed') !== 'true') {
                scanner.announce('Start scan to pick first.', 'warning');
                return;
            }
            const lines = inputs().map(input => ({ key: input.dataset.pickLine, productId: input.dataset.productId, factor: input.dataset.unitFactor, unitCode: input.dataset.unitCode, quantity: picked.get(input.dataset.pickLine) || 0, max: input.max, input }));
            const result = applyLineScan(lines, event.detail, 'pick');
            if (result.ok) {
                picked.set(result.key, result.quantity);
                render();
                highlight(lines.find(line => line.key === result.key)?.input.closest('tr'));
            }
            scanner.announce(result.message, result.tone);
        });
    }

    function enhanceCount(panel) {
        const scanner = createScanner(panel);
        const doc = panel.ownerDocument;
        let selected = null;
        doc.addEventListener('focusin', event => {
            const row = event.target.closest?.('[data-count-line]');
            if (row) selected = row.dataset.countLine;
        });
        const rows = () => [...doc.querySelectorAll('[data-count-line]')].filter(row => row.querySelector('[data-count-quantity]') && !row.querySelector('[data-count-quantity]').disabled);
        panel.addEventListener('barcodescan', event => {
            const lines = rows().map(row => {
                const field = row.querySelector('[data-count-quantity]');
                const unit = row.querySelector('[data-count-unit]');
                const option = unit?.selectedOptions?.[0];
                const factor = Number(option?.dataset.factor) || 1;
                return { key: row.dataset.countLine, productId: row.dataset.productId, lot: row.dataset.lotCode, base: field.value === '' ? null : Number(field.value) * factor, unitCode: unit ? unit.value : row.dataset.baseUnit, unitFactor: factor, baseCode: row.dataset.baseUnit, row, field, unit };
            });
            const result = applyCountScan(lines, event.detail, selected);
            if (result.ok) {
                const line = lines.find(candidate => candidate.key === result.key);
                if (line.unit) line.unit.value = result.unitCode;
                line.field.value = String(result.quantity);
                line.field.dispatchEvent(new root.Event('input', { bubbles: true }));
                highlight(line.row);
            } else if (result.productId) {
                const select = doc.querySelector('[data-found-product]');
                if (select && [...select.options].some(option => Number(option.value) === result.productId)) {
                    select.value = String(result.productId);
                    select.dispatchEvent(new root.Event('change', { bubbles: true }));
                    const unit = doc.querySelector('[data-found-unit]');
                    if (unit) unit.value = event.detail.unit.code;
                }
            }
            scanner.announce(result.message, result.tone);
        });
    }

    function enhanceAll(document) {
        document.querySelectorAll('[data-barcode-scanner]').forEach(panel => {
            if (panel.dataset.scanReady === '1') return;
            panel.dataset.scanReady = '1';
            if (panel.hasAttribute('data-scan-receive')) enhanceReceive(panel);
            else if (panel.hasAttribute('data-scan-pick')) enhancePick(panel);
            else if (panel.hasAttribute('data-scan-count')) enhanceCount(panel);
            else createScanner(panel);
        });
    }

    const api = { validateBarcode, ean13CheckDigit, lineUnits, applyLineScan, applyCountScan, lookup, createScanner, cameraSupported, enhanceAll };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryBarcodeScan = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => api.enhanceAll(root.document));
})(typeof globalThis === 'object' ? globalThis : this);
