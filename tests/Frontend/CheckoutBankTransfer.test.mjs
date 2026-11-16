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
