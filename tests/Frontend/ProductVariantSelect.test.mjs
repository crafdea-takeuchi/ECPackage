import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true }, appType: 'custom' });
const { default: ProductVariantSelect } = await server.ssrLoadModule('/resources/js/Components/ProductVariantSelect.vue');
after(() => server.close());

test('selector labels combine attribute values, tax-inclusive price and stock', async () => {
  const html = await renderToString(createSSRApp(ProductVariantSelect, { id: 'shop-variant-1', modelValue: '', variants: [
    { id: 2, name: '販売商品', attribute_values: [{ value: '24cm' }, { value: '赤' }], price_including_tax: '3300.0000', available_quantity: 5, shortage_policy: 'reject' },
    { id: 3, name: '26cm', price_including_tax: '4400.0000', available_quantity: 0, shortage_policy: 'backorder' },
  ] }));
  assert.ok(html.includes('id="shop-variant-1"'));
  assert.ok(html.includes('バリエーションを選択してください'));
  assert.ok(html.includes('24cm / 赤 — 3,300円（税込）— 在庫あり'));
  assert.ok(html.includes('26cm — 4,400円（税込）— 取り寄せ'));
  assert.ok(html.includes('value="2"'));
  assert.ok(html.includes('value="3"'));
});

test('sold out variants remain identifiable and use the product name without attributes', async () => {
  const html = await renderToString(createSSRApp(ProductVariantSelect, { variants: [
    { id: 4, name: '在庫切れ商品', price_including_tax: '1100.0000', available_quantity: 0, shortage_policy: 'reject' },
  ] }));
  assert.ok(html.includes('在庫切れ商品 — 1,100円（税込）— 在庫なし'));
});
