import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true }, appType: 'custom' });
const load = async name => (await server.ssrLoadModule('/resources/js/Pages/Admin/' + name + '.vue')).default;
const [Show, Index, GroupForm, ProductForm, Products] = await Promise.all(['ProductGroups/Show', 'ProductGroups/Index', 'ProductGroups/Form', 'Products/Form', 'Products/Index'].map(load));
after(() => server.close());
async function render(component, props) {
  let html;
  await createInertiaApp({
    page: { component: 'Test', url: '/admin/product-groups/1', version: null, props: {
      auth: { user: { id: 1, name: '管理者', role: 'admin', email_verified_at: '2026-11-18' } },
      cart: { count: 0 }, flash: { status: null }, errors: {}, ...props,
    } },
    render: () => '', resolve: () => component,
    setup: async ({ App, props, plugin }) => {
      html = await renderToString(createSSRApp({ render: () => h(App, props) }).use(plugin));
    },
  });
  return html;
}
const definition = { id: 1, name: 'サイズ', status: 'active', is_required: true, input_type: 'select', options: ['20cm', '24cm'] };
const group = { id: 1, name: '鉄フライパン', description: '共通説明', status: 'active', definitions: [definition], products_count: 3, lock_version: 0 };
const product = { id: 2, name: '鉄フライパン20cm', sku: 'PAN-20', product_group_id: 1, images: [], attribute_values: [{ id: 1, definition, product_attribute_definition_id: 1, value: '20cm' }], default_sale_price: '3000.0000', tax_rate: { name: '標準税率', rate: '10.0000' }, unit: { name: '個' }, available_quantity: '2.0000', shortage_policy: 'reject', registration_status: 'confirmed', publication_status: 'public', edit_url: '/admin/products/2/edit?from_product_group_id=1' };
const urls = { indexUrl: '/admin/product-groups', editUrl: '/admin/product-groups/1/edit', createProductUrl: '/admin/products/create?product_group_id=1&from_product_group_id=1' };
const page = data => ({ data, total: data.length, current_page: 1, last_page: 1, next_page_url: null, prev_page_url: null });

test('basic product detail shows member attributes, stock, price, statuses, images and navigation', async () => {
  const html = await render(Show, { productGroup: group, products: page([product, { ...product, id: 3, images: [{ thumbnail_url: '/image.webp', alt_text: '画像' }], available_quantity: 0, shortage_policy: 'backorder', publication_status: 'private' }]), ...urls });
  for (const text of ['基本商品情報', '共通説明', '販売商品・バリエーション', 'サイズ：20cm', '3,000円', '標準税率（10%）', '在庫あり', '取り寄せ可能', '本登録', '公開', '非公開', '画像なし', 'src="/image.webp"', '基本商品を編集', '販売商品を追加', 'href="/admin/products/2/edit?from_product_group_id=1"', 'overflow-x-auto']) assert.ok(html.includes(text), text);
});

test('empty and inactive parents have clear empty states and no add link for inactive parent', async () => {
  const empty = await render(Show, { productGroup: group, products: page([]), ...urls });
  assert.ok(empty.includes('この基本商品には販売商品が登録されていません。'));
  assert.ok(empty.includes('最初の販売商品を追加'));
  const inactive = await render(Show, { productGroup: { ...group, status: 'inactive' }, products: page([product]), ...urls, createProductUrl: null });
  assert.ok(inactive.includes('基本商品が無効のため追加できません。'));
  assert.ok(!inactive.includes('販売商品を追加</a>'));
  assert.ok(inactive.includes(product.edit_url));
});

test('basic product summary shows counts and a price range without decimal padding', async () => {
  const html = await render(Index, { groups: [{ ...group, definitions_count: 1, public_products_count: 2, private_products_count: 1, stocked_products_count: 1, products_min_default_sale_price: '3000.0000', products_max_default_sale_price: '4500.0000', show_url: '/admin/product-groups/1', edit_url: '/admin/product-groups/1/edit' }] });
  for (const text of ['3 件', '2件／1件', '3,000', '～4,500', '在庫あり 1件', 'href="/admin/product-groups/1"', 'サイズ']) assert.ok(html.includes(text), text);
});

test('inactive parent edit warns that child publication is unchanged', async () => {
  const html = await render(GroupForm, { productGroup: { ...group, status: 'inactive' }, showUrl: '/admin/product-groups/1' });
  assert.ok(html.includes('この基本商品には3件の販売商品があります。'));
  assert.ok(html.includes('販売商品の公開状態は変更されません。'));
  assert.ok(html.includes('販売商品・バリエーションを確認'));
});

test('existing product form preselects and locks parent, displays its attributes and return link', async () => {
  const html = await render(ProductForm, { product: null, selectedGroupId: 1, returnGroup: { id: 1, name: group.name, url: '/admin/product-groups/1' }, saveUrl: '/admin/products', groups: [group], units: [{ id: 1, code: 'piece', name: '個' }], taxRates: [], componentProducts: [], categories: [] });
  for (const text of ['基本商品：鉄フライパン', '鉄フライパンの属性', '本登録時必須', '20cm', '24cm', '鉄フライパンの販売商品一覧へ戻る']) assert.ok(html.includes(text), text);
  assert.ok(!html.includes('<select id="product-group"'));
});

test('cross-product management retains parent links and adds ungrouped and stock filters', async () => {
  const html = await render(Products, { products: page([{ ...product, group: { id: 1, name: group.name, show_url: '/admin/product-groups/1' }, categories: [] }]), filters: { ungrouped: 1 }, groups: [group], categories: [], attributeDefinitions: [], counts: { all: 1, provisional: 0, confirmed: 1 }, indexUrl: '/admin/products' });
  for (const text of ['基本商品未設定', 'href="/admin/product-groups/1"', '在庫状態', '在庫あり', 'PAN-20']) assert.ok(html.includes(text), text);
  assert.match(html, /<option value="ungrouped" selected/);
});

test('parent image editor shows saved preview, single-image limit and clickable selection control', async () => {
  const html = await render(GroupForm, { productGroup: { ...group, images: [{ id: 7, thumbnail_url: '/group.webp', alt_text: '親商品画像' }] }, showUrl: '/admin/product-groups/1' });
  for (const text of ['基本商品の代表画像', '最大1枚', 'src="/group.webp"', '差し替える', '削除']) assert.ok(html.includes(text), text);
  const empty = await render(GroupForm, { productGroup: null });
  for (const text of ['画像を選択', 'cursor-pointer', 'hover:bg-indigo-100', 'hover:border-indigo-500']) assert.ok(empty.includes(text), text);
  assert.ok(!empty.includes(' multiple'));
});

test('product editing displays all local schedule inputs and current sale status', async () => {
  const html = await render(ProductForm, { product: { ...product, description: '', categories: [] }, scheduleValues: { publication_starts_at: '2026-12-01T09:00:00', publication_ends_at: '', sale_starts_at: '2026-12-01T10:00:00', sale_ends_at: '' }, saveUrl: '/admin/products/2', groups: [group], units: [{ id: 1, code: 'piece', name: '個' }], taxRates: [], componentProducts: [], categories: [] });
  for (const name of ['publication_starts_at', 'publication_ends_at', 'sale_starts_at', 'sale_ends_at']) assert.match(html, new RegExp('id="' + name + '"[^>]*type="datetime-local"'));
  assert.ok(html.includes('value="2026-12-01T09:00:00"'));
  assert.ok(html.includes('Asia/Tokyo'));
});

test('sale-before and sale-ended detail pages explain why purchase is disabled', async () => {
  const { default: ShopShow } = await server.ssrLoadModule('/resources/js/Pages/Shop/Show.vue');
  for (const reason of ['販売開始前です。12月1日 10:00から販売開始', '販売は終了しました。']) {
    const html = await render(ShopShow, { product: { ...product, unit: { name: '個', quantity_scale: 0 }, categories: [], purchase_unavailable_reason: reason }, variants: [] });
    assert.ok(html.includes(reason));
    assert.match(html, /<button[^>]*disabled[^>]*>\s*カートに追加/);
  }
});

test('cart explains unavailable product and hides checkout navigation', async () => {
  const { default: Cart } = await server.ssrLoadModule('/resources/js/Pages/Cart/Index.vue');
  const html = await render(Cart, { items: [{ product, quantity: '1', amount: '3000', purchase_error: '販売は終了しました。' }], cartErrors: ['鉄フライパン20cm：販売は終了しました。'], subtotal: '3000', taxes: [], taxAmount: '0', total: '3000' });
  assert.ok(html.includes('鉄フライパン20cm：販売は終了しました。'));
  assert.ok(!html.includes('href="/checkout"'));
});
