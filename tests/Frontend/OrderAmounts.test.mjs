import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true }, appType: 'custom' });
after(() => server.close());
const { default: OrderAmounts } = await server.ssrLoadModule('/resources/js/Components/Admin/OrderAmounts.vue');
const { default: OrderLineTax } = await server.ssrLoadModule('/resources/js/Components/Admin/OrderLineTax.vue');
const render = order => renderToString(createSSRApp(OrderAmounts, { order }));
const rows = html => [...html.matchAll(/<dt[^>]*>(.*?)<\/dt><dd[^>]*>(.*?)<\/dd>/g)].map(([, label, value]) => [label, value]);

test('saved amounts appear in order with zero shipping and separately added tax', async () => {
  const html = await render({ subtotal: '1000.0000', tax_amount: '100.0000', total: '1100.0000' });
  assert.deepEqual(rows(html), [['商品小計（税抜）', '1,000 円'], ['送料', '0 円'], ['税金', '100 円'], ['合計（税込）', '1,100 円']]);
});

test('mixed-rate order displays saved aggregate tax without recalculating from rates', async () => {
  const html = await render({ subtotal: '2000.0000', tax_amount: '180.0000', total: '2180.0000', lines: [{ tax_rate: '15.00' }, { tax_rate: '15.00' }] });
  assert.deepEqual(rows(html), [['商品小計（税抜）', '2,000 円'], ['送料', '0 円'], ['税金', '180 円'], ['合計（税込）', '2,180 円']]);
});

test('zero tax on an existing order remains visible', async () => {
  const html = await render({ subtotal: '501.0000', tax_amount: '0.0000', total: '501.0000' });
  assert.deepEqual(rows(html), [['商品小計（税抜）', '501 円'], ['送料', '0 円'], ['税金', '0 円'], ['合計（税込）', '501 円']]);
});

test('stored decimal amounts and totals are preserved instead of summing current line prices', async () => {
  const html = await render({ subtotal: '1234.5678', tax_amount: '123.0000', total: '1357.5678', lines: [{ quantity: 9, unit_sale_price: 9999 }] });
  assert.deepEqual(rows(html), [['商品小計（税抜）', '1,234.5678 円'], ['送料', '0 円'], ['税金', '123 円'], ['合計（税込）', '1,357.5678 円']]);
});


test('tax-rate breakdown is shown next to the saved aggregate', async () => {
  const html = await renderToString(createSSRApp(OrderAmounts, {
    order: { subtotal: '3000.0000', tax_amount: '260.0000', total: '3260.0000' },
    taxes: [{ name: '標準税率', rate: '10.00', tax_amount: '100.0000' }, { name: '軽減税率', rate: '8.00', tax_amount: '160.0000' }],
  }));
  assert.deepEqual(rows(html), [['商品小計（税抜）', '3,000 円'], ['送料', '0 円'], ['税金', '260 円'], ['標準税率（10%）', '100 円'], ['軽減税率（8%）', '160 円'], ['合計（税込）', '3,260 円']]);
});

for (const [name, rate, amount, expected] of [
  ['標準税率', '10.00', '100.0000', '100'],
  ['軽減税率', '8.00', '160.0000', '160'],
  [null, '0.00', '0.0000', '0'],
]) {
  test(`line displays tax classification and allocated amount: ${name ?? '非課税'}`, async () => {
    const html = await renderToString(createSSRApp(OrderLineTax, { line: { tax_rate_name: name, tax_rate: rate, display_tax_amount: amount } }));
    assert.ok(html.includes(`${name ?? '非課税'}（${Number(rate)}%）`));
    assert.ok(html.includes(`税額 ${expected} 円`));
  });
}

test('unavailable line tax is explicit instead of showing an estimated value or zero', async () => {
  const html = await renderToString(createSSRApp(OrderLineTax, { line: { tax_rate_name: '標準税率', tax_rate: '10.00', display_tax_amount: null } }));
  assert.ok(html.includes('明細税額の記録なし'));
  assert.ok(!html.includes('税額 0 円'));
});

test('cash on delivery order shows the saved fee before the total', async () => {
  const html = await render({ payment_method: 'cash_on_delivery', subtotal: '1000.0000', tax_amount: '100.0000', payment_fee: '330.0000', total: '1430.0000' });
  assert.deepEqual(rows(html), [['商品小計（税抜）', '1,000 円'], ['送料', '0 円'], ['税金', '100 円'], ['代引き手数料（税込）', '330 円'], ['合計（税込）', '1,430 円']]);
});
