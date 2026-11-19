import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { after, test } from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, nextTick } from 'vue';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const compile = path => compileScript(parse(readFileSync(path, 'utf8')).descriptor, { id: path, inlineTemplate: true }).content;
const server = await createServer({
  configFile: false,
  plugins: [vue(), {
    name: 'checkout-client-test',
    resolveId: id => ['virtual:checkout-client', 'virtual:bank-client'].includes(id) ? id : null,
    load(id) {
      if (id === 'virtual:bank-client') return compile('resources/js/Components/BankTransferInstructions.vue');
      if (id !== 'virtual:checkout-client') return null;
      return compile('resources/js/Pages/Checkout/Create.vue')
        .replace("import { Head, Link, useForm } from '@inertiajs/vue3';", "import { useForm } from '@inertiajs/vue3'; import { h as testH } from 'vue'; const Head = { render: () => null }; const Link = { props: ['href'], setup: (p, { slots }) => () => testH('a', { href: p.href }, slots.default?.()) };")
        .replace("import BankTransferInstructions from '../../Components/BankTransferInstructions.vue';", "import BankTransferInstructions from 'virtual:bank-client';")
        .replace("import ProductImage from '../../Components/ProductImage.vue';", "const ProductImage = { render: () => null };")
        .replace("import AppLayout from '../../Layouts/AppLayout.vue';", "const AppLayout = { setup: (p, { slots }) => () => testH('main', null, slots.default?.()) };");
    },
  }],
  server: { middlewareMode: true, hmr: false }, appType: 'custom',
});
const { default: Checkout } = await server.ssrLoadModule('virtual:checkout-client');
after(() => server.close());

const bank = { bank_name: '確認銀行', branch_name: '本店', account_type: '普通', account_number: '001234567890', account_holder_kana: 'カ）テスト', instructions: '注文番号を添えてください。' };

function mount(t, overrides = {}) {
  globalThis.Document = class {};
  globalThis.document = Object.assign(new Document(), { activeElement: null });
  const node = tag => ({
    tag, tagName: tag.toUpperCase(), props: {}, children: [],
    addEventListener() {}, removeEventListener() {}, getRootNode: () => globalThis.document,
    get options() { return this.children.filter(child => child.tag === 'option'); },
  });
  const renderer = createRenderer({
    createElement: node, createText: text => ({ text }), createComment: text => ({ text }),
    setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; },
    patchProp: (el, key, previous, value) => { el.props[key] = value; el[key] = value; },
    insert: (el, parent, anchor) => {
      if (el.parent) el.parent.children = el.parent.children.filter(child => child !== el);
      const index = anchor ? parent.children.indexOf(anchor) : -1;
      if (index >= 0) parent.children.splice(index, 0, el);
      else parent.children.push(el);
      el.parent = parent;
    },
    remove: el => { el.parent.children = el.parent.children.filter(child => child !== el); },
    parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
  });
  const root = node('root');
  const app = renderer.createApp(Checkout, {
    items: [], subtotal: '0', taxes: [], taxAmount: '0', total: '0', prefectures: [],
    paymentMethods: { bank_transfer: '銀行振込', cash: 'その他' }, customerName: '購入者', bankTransfer: bank, ...overrides,
  });
  app.mount(root);
  t.after(() => { app.unmount(); delete globalThis.document; delete globalThis.Document; });
  const find = (el, predicate) => predicate(el) ? el : el.children?.map(child => find(child, predicate)).find(Boolean);
  return {
    root, find: predicate => find(root, predicate),
    text: () => { const collect = el => [el.text ?? '', ...(el.children ?? []).map(collect)].join(' '); return collect(root); },
    async select(method) {
      find(root, el => el.props?.id === 'payment-method').props['onUpdate:modelValue'](method);
      await nextTick();
    },
    async set(id, value) {
      find(root, el => el.props?.id === id).props['onUpdate:modelValue'](value);
      await nextTick();
    },
    value: id => find(root, el => el.props?.id === id).value,
  };
}

test('initial bank selection immediately displays the registered bank directly after payment selector', async (t) => {
  const page = mount(t);
  for (const value of ['お振込先', '確認銀行', '本店', '普通', '001234567890', '口座名義：カ）テスト', '注文番号を添えてください。']) assert.ok(page.text().includes(value), value);
  assert.ok(page.text().indexOf('お支払い方法') < page.text().indexOf('お振込先'));
  assert.ok(page.text().indexOf('お振込先') < page.text().indexOf('ご要望・備考'));
  await page.select('cash');
  assert.ok(!page.text().includes('お振込先'));
  assert.ok(!page.text().includes('001234567890'));
  await page.select('bank_transfer');
  assert.ok(page.text().includes('001234567890'));
});

test('bank panel appears immediately when changing a different initial payment method', async (t) => {
  const page = mount(t, { paymentMethods: { cash: 'その他', bank_transfer: '銀行振込' } });
  assert.ok(!page.text().includes('お振込先'));
  await page.select('bank_transfer');
  assert.ok(page.text().includes('お振込先'));
  assert.ok(page.text().includes('口座名義：カ）テスト'));
});

test('unregistered bank uses a checkout notice instead of a missing-order-snapshot notice', async (t) => {
  const page = mount(t, { bankTransfer: null });
  assert.ok(page.text().includes('振込先が設定されていません。'));
  assert.ok(!page.text().includes('注文時点の振込先情報'));
  await page.select('cash');
  assert.ok(!page.text().includes('振込先が設定されていません。'));
});

test('cash on delivery shows the fee and adds it to the displayed total only while selected', async (t) => {
  const page = mount(t, {
    total: '1100.0000',
    paymentMethods: { bank_transfer: '銀行振込', cash_on_delivery: '代引き' },
    paymentFees: { cash_on_delivery: '330.0000' },
  });
  assert.ok(!page.text().includes('代引き手数料'));
  assert.ok(page.text().includes('1,100 円'));
  await page.select('cash_on_delivery');
  assert.ok(page.text().includes('代引き手数料（税込）'));
  assert.ok(page.text().includes('330 円'));
  assert.ok(page.text().includes('1,430 円'));
  assert.ok(page.text().includes('配達員へご注文合計（税込）1,430 円'));
  assert.ok(!page.text().includes('お振込先'));
  await page.select('bank_transfer');
  assert.ok(!page.text().includes('代引き手数料'));
  assert.ok(!page.text().includes('1,430 円'));
});

test('bank panel ends with the transfer name note', async (t) => {
  const page = mount(t);
  const text = page.text();
  const note = 'お振込みの際は、振込依頼人名の後ろに注文番号を付けてお振込みください。';
  assert.ok(text.includes(note));
  assert.ok(text.includes('記入例：ヤマダタロウ ON2611AB23'));
  assert.ok(text.indexOf('注文番号を添えてください。') < text.indexOf(note));
  await page.select('cash');
  assert.ok(!page.text().includes(note));
});

const settle = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick(); };
function mockFetch(t, handler) {
  const original = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, options) => { calls.push(url); return handler(url, options); };
  t.after(() => { globalThis.fetch = original; });
  return calls;
}
const zipcloud = results => ({ json: async () => ({ status: 200, message: null, results }) });

test('shipping fee of the selected prefecture is shown and added to the total with the cod fee', async (t) => {
  const page = mount(t, {
    total: '1100.0000', prefectures: ['北海道', '東京都'],
    paymentMethods: { cash_on_delivery: '代引き' }, paymentFees: { cash_on_delivery: '330.0000' },
    shippingFees: { 北海道: '1200.0000', 東京都: '0.0000' },
  });
  assert.ok(page.text().includes('お届け先の都道府県を選択すると表示します'));
  assert.ok(page.text().includes('1,430 円'));
  await page.set('shipping-prefecture', '北海道');
  assert.ok(page.text().includes('送料（税込）'));
  assert.ok(page.text().includes('1,200 円'));
  assert.ok(page.text().includes('2,630 円'));
  await page.set('shipping-prefecture', '東京都');
  assert.ok(page.text().includes('1,430 円'));
  assert.ok(!page.text().includes('2,630 円'));
});

test('a complete postal code fills the prefecture and an empty address, which updates the shipping fee', async (t) => {
  const calls = mockFetch(t, () => zipcloud([{ address1: '北海道', address2: '札幌市中央区', address3: '北一条西' }]));
  const page = mount(t, { total: '1000.0000', prefectures: ['北海道', '東京都'], shippingFees: { 北海道: '1200.0000', 東京都: '800.0000' } });
  await page.set('shipping-postal-code', '060-000');
  assert.equal(calls.length, 0);
  await page.set('shipping-postal-code', '０６０-０００１');
  await settle();
  assert.deepEqual(calls, ['https://zipcloud.ibsnet.co.jp/api/search?zipcode=0600001']);
  assert.equal(page.value('shipping-address-line1'), '札幌市中央区北一条西');
  assert.ok(page.text().includes('1,200 円'), '北海道の送料が表示される');
  assert.ok(page.text().includes('2,200 円'));
});

test('postal lookup keeps an address the customer already typed', async (t) => {
  mockFetch(t, () => zipcloud([{ address1: '東京都', address2: '千代田区', address3: '千代田' }]));
  const page = mount(t, { prefectures: ['北海道', '東京都'], shippingFees: { 北海道: '1200.0000', 東京都: '800.0000' } });
  await page.set('shipping-address-line1', '入力済みの住所1-2');
  await page.set('shipping-postal-code', '1000001');
  await settle();
  assert.ok(page.text().includes('800 円'), '東京都の送料が表示される');
  assert.equal(page.value('shipping-address-line1'), '入力済みの住所1-2');
});

test('unknown postal codes and lookup failures ask for manual input without changing the form', async (t) => {
  let respond = () => zipcloud(null);
  mockFetch(t, () => respond());
  const page = mount(t, { prefectures: ['北海道', '東京都'], shippingFees: { 北海道: '1200.0000', 東京都: '800.0000' } });
  await page.set('shipping-postal-code', '9999999');
  await settle();
  assert.ok(page.text().includes('該当する住所が見つかりませんでした。'));
  assert.ok(page.text().includes('お届け先の都道府県を選択すると表示します'));
  respond = () => { throw new TypeError('network'); };
  await page.set('shipping-postal-code', '1000002');
  await settle();
  assert.ok(page.text().includes('住所を自動入力できませんでした。'));
  assert.ok(page.text().includes('お届け先の都道府県を選択すると表示します'));
  assert.equal(page.value('shipping-address-line1'), '');
});

test('checkout shows per-rate tax rows without an aggregate tax row', async (t) => {
  const page = mount(t, { subtotal: '1000.0000', taxes: [{ name: '標準税率', rate: '10.00', taxable_amount: '1000.0000', tax_amount: '100.0000' }], taxAmount: '100.0000', total: '1100.0000' });
  assert.ok(page.text().includes('標準税率（10%）対象 1,000 円'));
  assert.ok(page.text().includes('消費税 100 円'));
  assert.ok(!page.text().includes('消費税合計'));
});
