(() => {
 function enhance(form) {
  if (form.dataset.stockEnhanced) return;
  form.dataset.stockEnhanced = 'true';
  const kind = form.elements.kind;
  const submit = form.querySelector('[type="submit"]');
  const add = form.querySelector('[data-stock-add]');
  const rows = () => [...form.querySelectorAll('[data-stock-items] [data-stock-item]')];
  const input = (row, name) => row.querySelector('[data-stock-input="' + name + '"]');
  let revision = 0, next = rows().length;
  // Units (D-16, ADR-022): shared helpers from order-items.js (loaded on every workspace page).
  const units = () => (typeof window !== 'undefined' && window.InventoryOrderItems) || null;
  function fillRowUnits(row, option) {
    const select = input(row, 'unit_code');
    const helpers = units();
    if (!select || !option || !helpers) return;
    helpers.fillUnits(select, option, false, select.value);
    updateBaseHint(row, option);
  }
  function updateBaseHint(row, option) {
    const hint = row.querySelector('[data-stock-base-hint]');
    const helpers = units();
    const select = input(row, 'unit_code');
    if (!hint || !helpers || !select) return;
    const source = option || row.__unitSource || input(row, 'product_id').selectedOptions[0];
    const factor = Number(select.selectedOptions[0]?.dataset.factor || 1);
    const quantity = String(input(row, 'quantity').value).trim();
    hint.textContent = factor > 1 && /^\d+$/.test(quantity) ? '= ' + helpers.formatQuantity(Number(quantity) * factor, helpers.parseUnits(source?.dataset.units), source?.dataset.baseUnit || '') : '';
  }
  for (const row of rows()) if (input(row, 'baseline').value !== '') row.dataset.retainBaseline = 'true';
  // Lots (D-17, ADR-023): an adjustment counts one lot (baseline = its quantity), a new lot (baseline 0) or the whole
  // product (baseline = pair quantity). Expired lots are labelled; the server re-checks every baseline.
  function fillLots(row, balance) {
    const select = input(row, 'lot_id');
    if (!select) return;
    const wanted = select.value || select.dataset.selected || '';
    row.__balance = balance;
    select.replaceChildren(new Option('Whole product (all lots)', ''));
    for (const lot of Array.isArray(balance.lots) ? balance.lots : []) {
      const label = lot.lot_code + (lot.expiry_date ? ' · exp. ' + lot.expiry_date : ' · no expiry') + (lot.expired ? ' (expired)' : '') + ' · ' + lot.quantity;
      const option = new Option(label, String(lot.id));
      option.dataset.quantity = String(lot.quantity);
      select.add(option);
    }
    select.add(new Option('New lot found at the count', 'new'));
    if ([...select.options].some(option => option.value === wanted)) select.value = wanted;
    toggleNewLot(row);
  }
  function toggleNewLot(row) {
    const select = input(row, 'lot_id');
    const box = row.querySelector('[data-stock-new-lot]');
    if (!select || !box) return;
    box.hidden = select.value !== 'new' || kind.value !== 'Adjustment';
    input(row, 'lot_code').required = !box.hidden;
  }
  function applyLotBaseline(row) {
    const select = input(row, 'lot_id');
    const balance = row.__balance || {};
    const option = select ? select.selectedOptions[0] : null;
    input(row, 'baseline').value = !select || select.value === '' ? balance.quantity : (select.value === 'new' ? 0 : (option?.dataset.quantity ?? ''));
  }
  async function loadBaseline(row, current) {
    const query = new URLSearchParams({ product_id: input(row, 'product_id').value, warehouse_id: form.elements.warehouse_id.value });
    const response = await fetch('/inventory-operations/balance?' + query, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error('Current stock could not be loaded.');
    const balance = await response.json();
    if (current !== revision) return false;
    fillLots(row, balance);
    if (!row.dataset.retainBaseline) { applyLotBaseline(row); row.dataset.retainBaseline = 'true'; }
    return { product: input(row, 'product_id').value };
  }
  async function loadReturnSource(row, value, summary, current) {
    const id = input(row, 'source_ledger_id').value;
    if (!/^[1-9]\d*$/.test(id)) throw new Error('Choose the original receipt/issue ledger ID.');
    const response = await fetch('/inventory-operations/source?id=' + encodeURIComponent(id), { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error('Original movement could not be loaded.');
    const source = await response.json();
    if (current !== revision) return false;
    if (source.movement_type !== (value === 'SupplierReturn' ? 'Receipt' : 'Issue')) throw new Error('Original movement does not match the return type.');
    summary.textContent = 'Original ' + source.reference_type + ' #' + source.reference_id + ' · product #' + source.product_id + ' · warehouse #' + source.warehouse_id + ' · original quantity ' + source.quantity + (source.base_unit ? ' ' + source.base_unit : '');
    if (Array.isArray(source.units)) {
      row.__unitSource = { dataset: { units: JSON.stringify(source.units), baseUnit: source.base_unit || '' } };
      fillRowUnits(row, row.__unitSource);
    }
    return { product: String(source.product_id), warehouse: String(source.warehouse_id) };
  }
  // Resolves to the row's product (and return warehouse) or false; stale results from an older refresh are ignored.
  async function checkRow(row, value, isReturn, current) {
    const summary = row.querySelector('[data-stock-source-summary]');
    row.querySelector('[data-stock-quantity-label]').textContent = value === 'Adjustment' ? 'Counted Quantity' : 'Quantity';
    input(row, 'quantity').min = value === 'Adjustment' ? '0' : '1';
    summary.textContent = '';
    try {
      if (value === 'Adjustment') { fillRowUnits(row, input(row, 'product_id').selectedOptions[0]); return await loadBaseline(row, current); }
      if (isReturn) return await loadReturnSource(row, value, summary, current);
      fillRowUnits(row, input(row, 'product_id').selectedOptions[0]);
      return { product: input(row, 'product_id').value };
    } catch (error) {
      if (current === revision) summary.textContent = error.message || 'Please reload and try again.';
      return false;
    }
  }
  async function refresh() {
    const current = ++revision, value = kind.value;
    const isReturn = value === 'SupplierReturn' || value === 'CustomerReturn';
    const items = rows();
    items.forEach((row, index) => row.querySelector('legend').textContent = 'Product item ' + (index + 1));
    for (const field of form.querySelectorAll('[data-stock-field]')) {
      const visible = ({ regular: !isReturn, transfer: value === 'Transfer', adjustment: value === 'Adjustment', return: isReturn, customer: value === 'CustomerReturn' })[field.dataset.stockField];
      field.hidden = !visible;
      for (const control of field.querySelectorAll('input, select')) { control.disabled = !visible; control.required = visible && !control.hasAttribute('data-stock-optional'); }
    }
    items.forEach(toggleNewLot);
    add.disabled = items.length >= 100;
    submit.disabled = true;
    const valid = await Promise.all(items.map(row => checkRow(row, value, isReturn, current)));
    if (current !== revision) return;
    const products = new Set(), warehouses = new Set();
    let allValid = valid.every(Boolean);
    valid.forEach((result, index) => {
      if (!result) return;
      // One line per product, or per product and lot for adjustments (D-17, ADR-023).
      const lotChoice = value === 'Adjustment' && input(items[index], 'lot_id') ? input(items[index], 'lot_id').value : '';
      const key = result.product + ':' + (lotChoice === '' ? 'all' : lotChoice);
      const clash = products.has(key) || products.has(result.product + ':all') || (lotChoice === '' && [...products].some(entry => entry.startsWith(result.product + ':')));
      if (clash) { items[index].querySelector('[data-stock-source-summary]').textContent = 'Choose a different product or lot; duplicate lines are not allowed.'; allValid = false; }
      products.add(key);
      if (result.warehouse) warehouses.add(result.warehouse);
    });
    if (warehouses.size > 1) { items[0].querySelector('[data-stock-source-summary]').textContent = 'All return items must use the same original warehouse.'; allValid = false; }
    submit.disabled = !allValid;
  }
  form.addEventListener('change', event => {
    const name = event.target.dataset.stockInput;
    if (event.target === kind || event.target === form.elements.warehouse_id) {
      for (const row of rows()) delete row.dataset.retainBaseline;
      refresh();
    } else if (['product_id', 'source_ledger_id'].includes(name)) {
      const row = event.target.closest('[data-stock-item]');
      delete row.dataset.retainBaseline;
      if (name === 'product_id' && input(row, 'unit_code')) input(row, 'unit_code').value = ''; // new product: base unit
      refresh();
    } else if (name === 'lot_id') {
      const row = event.target.closest('[data-stock-item]');
      toggleNewLot(row);
      applyLotBaseline(row);
    } else if (['unit_code', 'quantity'].includes(name)) {
      updateBaseHint(event.target.closest('[data-stock-item]'));
    }
  });
  form.addEventListener('input', event => {
    if (event.target.dataset.stockInput === 'quantity') updateBaseHint(event.target.closest('[data-stock-item]'));
  });
  add.addEventListener('click', () => {
    if (rows().length >= 100) return;
    const row = form.querySelector('[data-stock-template]').content.firstElementChild.cloneNode(true);
    for (const control of row.querySelectorAll('[data-stock-input]')) control.name = 'items[' + next + '][' + control.dataset.stockInput + ']';
    next++;
    row.querySelector('[data-stock-remove]').disabled = false;
    form.querySelector('[data-stock-items]').appendChild(row);
    refresh();
    input(row, 'product_id').focus();
  });
  form.addEventListener('click', event => {
    const remove = event.target.closest('[data-stock-remove]');
    if (!remove || remove.disabled) return;
    remove.closest('[data-stock-item]').remove();
    add.focus();
    refresh();
  });
  refresh();
 }
 // Lot splits on the PO receipt form (D-17, ADR-023): "Add another lot" clones the last lot row with empty values.
 document.addEventListener('click', event => {
   const add = event.target.closest && event.target.closest('[data-lot-split-add]');
   if (!add) return;
   const fieldset = add.closest('[data-lot-splits]');
   const rows = fieldset ? fieldset.querySelectorAll('[data-lot-split]') : [];
   if (!rows.length || rows.length >= 50) return;
   const clone = rows[rows.length - 1].cloneNode(true);
   for (const control of clone.querySelectorAll('input')) control.value = '';
   for (const control of clone.querySelectorAll('input[name="lot_quantity[]"]')) control.required = true;
   for (const control of fieldset.querySelectorAll('input[name="lot_quantity[]"]')) control.required = true;
   add.before(clone);
   clone.querySelector('input').focus();
 });
 const enhanceAll = () => document.querySelectorAll('[data-stock-proposal]').forEach(enhance);
 document.addEventListener('DOMContentLoaded', () => {
   enhanceAll();
   new MutationObserver(enhanceAll).observe(document.body, { childList: true, subtree: true });
 });
})();
