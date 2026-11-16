import assert from 'node:assert/strict';
import { test } from 'node:test';
import { effectScope, nextTick, ref } from 'vue';
import { useInventoryLotFilters } from '../../resources/js/Pages/Admin/InventoryLots/filters.js';

function setup(t, initial) {
  const filters = ref(initial);
  const calls = [];
  const scope = effectScope();
  const state = scope.run(() => useInventoryLotFilters(
    () => filters.value, () => 14, (...args) => calls.push(args),
  ));
  t.after(() => scope.stop());
  return { filters, calls, ...state };
}

test('expired toggle changes only expiration status and resets pagination', async (t) => {
  const other = { q: 'LOT-001', product_id: '2', supplier_id: '3', received_month: '2026-11', availability: 'available', expiration_type: 'use_by', expiration_from: '2026-11-01', expiration_to: '2026-11-30', expiration_days: 14, expiring_only: 1, sort: 'received_desc' };
  const state = setup(t, { ...other, page: 3 });
  state.toggleExpired();
  const [url, on, options] = state.calls[0];
  assert.equal(url, '/admin/inventory-lots');
  assert.deepEqual(on, { ...other, expiration_status: 'expired' });
  assert.equal(on.page, undefined);
  assert.equal(options.replace, undefined);
  state.filters.value = on;
  await nextTick();
  assert.equal(state.expiredOnly.value, true);
  state.toggleExpired();
  const off = state.calls[1][1];
  assert.deepEqual(off, other);
  state.filters.value = off;
  await nextTick();
  assert.equal(state.expiredOnly.value, false);
  assert.equal(state.search.expiration_status, '');
});

test('server props from back and forward navigation restore form values and toggle state', async (t) => {
  const original = { q: '元の検索', supplier_id: 3, expiration_status: '', expiration_days: 14 };
  const active = { ...original, expiration_status: 'expired' };
  const state = setup(t, original);
  state.filters.value = active;
  await nextTick();
  assert.equal(state.expiredOnly.value, true);
  assert.equal(state.search.expiration_status, 'expired');
  state.search.q = '未送信の検索';
  state.filters.value = { ...original };
  await nextTick();
  assert.equal(state.expiredOnly.value, false);
  assert.equal(state.search.q, '元の検索');
  state.filters.value = { ...active };
  await nextTick();
  assert.equal(state.search.expiration_status, 'expired');
  assert.equal(state.expiredOnly.value, true);
  assert.equal(state.search.supplier_id, 3);
});

test('full reset remains available and restores configured day count', (t) => {
  const state = setup(t, { q: '商品', expiration_status: 'expired', expiration_days: 90, expiring_only: 1 });
  state.reset();
  assert.deepEqual(state.calls[0], ['/admin/inventory-lots', {}, { preserveState: true }]);
  assert.equal(state.search.q, '');
  assert.equal(state.search.expiration_status, '');
  assert.equal(state.search.expiration_days, 14);
  assert.equal(state.search.expiring_only, false);
});
