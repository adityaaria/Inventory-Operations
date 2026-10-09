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
  for (const row of rows()) if (input(row, 'baseline').value !== '') row.dataset.retainBaseline = 'true';
  async function loadBaseline(row, current) {
    const query = new URLSearchParams({ product_id: input(row, 'product_id').value, warehouse_id: form.elements.warehouse_id.value });
    const response = await fetch('/inventory-operations/balance?' + query, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error('Current stock could not be loaded.');
    const balance = await response.json();
    if (current !== revision) return false;
    if (!row.dataset.retainBaseline) { input(row, 'baseline').value = balance.quantity; row.dataset.retainBaseline = 'true'; }
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
    summary.textContent = 'Original ' + source.reference_type + ' #' + source.reference_id + ' · product #' + source.product_id + ' · warehouse #' + source.warehouse_id + ' · original quantity ' + source.quantity;
    return { product: String(source.product_id), warehouse: String(source.warehouse_id) };
  }
  // Resolves to the row's product (and return warehouse) or false; stale results from an older refresh are ignored.
  async function checkRow(row, value, isReturn, current) {
    const summary = row.querySelector('[data-stock-source-summary]');
    row.querySelector('[data-stock-quantity-label]').textContent = value === 'Adjustment' ? 'Counted Quantity' : 'Quantity';
    input(row, 'quantity').min = value === 'Adjustment' ? '0' : '1';
    summary.textContent = '';
    try {
      if (value === 'Adjustment') return await loadBaseline(row, current);
      if (isReturn) return await loadReturnSource(row, value, summary, current);
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
      for (const control of field.querySelectorAll('input, select')) { control.disabled = !visible; control.required = visible; }
    }
    add.disabled = items.length >= 100;
    submit.disabled = true;
    const valid = await Promise.all(items.map(row => checkRow(row, value, isReturn, current)));
    if (current !== revision) return;
    const products = new Set(), warehouses = new Set();
    let allValid = valid.every(Boolean);
    valid.forEach((result, index) => {
      if (!result) return;
      if (products.has(result.product)) { items[index].querySelector('[data-stock-source-summary]').textContent = 'Choose a different product; duplicate products are not allowed.'; allValid = false; }
      products.add(result.product);
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
      delete event.target.closest('[data-stock-item]').dataset.retainBaseline;
      refresh();
    }
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
 const enhanceAll = () => document.querySelectorAll('[data-stock-proposal]').forEach(enhance);
 document.addEventListener('DOMContentLoaded', () => {
   enhanceAll();
   new MutationObserver(enhanceAll).observe(document.body, { childList: true, subtree: true });
 });
})();
