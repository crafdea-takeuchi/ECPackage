import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true, hmr: false }, appType: 'custom' });
const { default: Instructions } = await server.ssrLoadModule('/resources/js/Components/BankTransferInstructions.vue');
const { default: SettingsCards } = await server.ssrLoadModule('/resources/js/Components/Admin/SettingsCards.vue');
const { default: Settings } = await server.ssrLoadModule('/resources/js/Pages/Admin/Settings/Index.vue');
const { default: BankForm } = await server.ssrLoadModule('/resources/js/Pages/Admin/Settings/BankTransfer.vue');
after(() => server.close());

const bank = { bank_name: 'テスト銀行', bank_code: '0001', branch_name: 'テスト支店', branch_code: '001', account_type: '普通', account_number: '001234567890', account_holder: 'テスト株式会社', account_holder_kana: 'テスト（カ', instructions: '注文番号を添えてください。' };
const fields = { bank_name: '銀行名', bank_code: '銀行コード', branch_name: '支店名', branch_code: '支店コード', account_type: '口座種別', account_number: '口座番号', account_holder: '口座名義', account_holder_kana: '口座名義カナ', instructions: 'お客様向け案内文' };

test('bank transfer panel shows saved details and escapes customer instructions', async () => {
  const html = await renderToString(createSSRApp(Instructions, { paymentMethod: 'bank_transfer', bank: { ...bank, instructions: '<script>alert(1)</script>' } }));
  for (const value of ['お振込先', 'テスト銀行', 'テスト支店', '普通', '001234567890', 'テスト株式会社', 'テスト（カ']) assert.ok(html.includes(value), value);
  assert.ok(!html.includes('銀行コード'));
  assert.ok(!html.includes('支店コード'));
  assert.ok(html.includes('&lt;script&gt;'));
  assert.ok(!html.includes('<script>'));
});

test('legacy snapshots show an explicit notice and other payment methods show no account', async () => {
  const legacy = await renderToString(createSSRApp(Instructions, { paymentMethod: 'bank_transfer', bank: null }));
  assert.ok(legacy.includes('この注文には注文時点の振込先情報が保存されていません。'));
  assert.ok(!legacy.includes('001234567890'));
  const other = await renderToString(createSSRApp(Instructions, { paymentMethod: 'cash', bank }));
  assert.ok(!other.includes('お振込先'));
  assert.ok(!other.includes('001234567890'));
});

async function renderPage(component, props) {
  let html;
  await createInertiaApp({
    page: { component: 'Test', url: '/admin/settings', version: null, props: {
      auth: { user: { id: 1, name: '管理者', role: 'admin', email_verified_at: '2026-11-16' } },
      flash: { status: null }, cart: { count: 0 }, errors: {}, adminSettingsUrl: '/admin/settings', ...props,
    } },
    render: () => '', resolve: () => component,
    setup: async ({ App, props: appProps, plugin }) => {
      const app = createSSRApp({ render: () => h(App, appProps) }).use(plugin);
      html = await renderToString(app);
      return app;
    },
  });
  return html;
}

test('settings entry preserves admin menus and uses responsive cards without dead links', async () => {
  const html = await renderPage(Settings, { cards: [
    { title: '振込先設定', description: '振込先を登録します。', status: '振込先設定済み', account_number: '****890', href: '/admin/settings/bank-transfer', button: '設定を開く' },
  ] });
  for (const label of ['各種設定', '販売商品', '基本商品・属性', '商品分類', '仕入先', '入庫', 'ロット在庫', '出庫・在庫調整', '注文', '発送', '取り寄せ', '注文設定', 'アカウント']) assert.ok(html.includes(label), label);
  assert.ok(html.includes('href="/admin/settings"'));
  assert.ok(html.includes('href="/admin/order-settings"'));
  assert.ok(html.includes('href="/admin/settings/bank-transfer"'));
  assert.ok(!html.includes('href="/admin/units"'));
  assert.ok(!html.includes('href="/admin/tax-rates"'));
  assert.ok(html.includes('grid-cols-1'));
  assert.ok(html.includes('max-w-2xl'));
  for (const hidden of ['単位管理', '税率管理', '在庫・期限設定', '準備中']) assert.ok(!html.includes(hidden), hidden);
  assert.ok(html.includes('****890'));
  assert.ok(!html.includes('001234567890'));
});

test('bank form uses text inputs to preserve leading zeroes and places validation errors near fields', async () => {
  const html = await renderPage(BankForm, {
    bank, registered: true, fields, accountTypes: ['普通', '当座', '貯蓄'],
    saveUrl: '/admin/settings/bank-transfer', settingsUrl: '/admin/settings', updatedAt: null,
    errors: { account_number: '口座番号は半角数字で入力してください。' },
  });
  assert.ok(html.includes('value="001234567890"'));
  assert.match(html, /id="bank-account_number"[^>]+type="text"/);
  assert.match(html, /id="bank-account_number"[^>]+maxlength="20"/);
  assert.ok(html.includes('口座番号は半角数字で入力してください。'));
  assert.ok(html.includes('error-account_number'));
  assert.ok(html.includes('普通'));
  assert.ok(html.includes('当座'));
  assert.ok(html.includes('貯蓄'));
});

test('bank-only hub card shows unregistered warning without other settings cards', async () => {
  const html = await renderToString(createSSRApp(SettingsCards, { cards: [{
    title: '振込先設定', description: '銀行振込でお客様へ案内する振込先を登録します。',
    status: '振込先が設定されていません。銀行振込の注文受付前に登録してください。',
    warning: true, account_number: null, href: '/admin/settings/bank-transfer', button: '設定を開く',
  }] }));
  assert.ok(html.includes('role="alert"'));
  assert.ok(html.includes('振込先が設定されていません。'));
  assert.ok(html.includes('銀行振込の注文受付前に登録してください。'));
  assert.ok(html.includes('href="/admin/settings/bank-transfer"'));
  assert.ok(html.includes('max-w-2xl'));
  for (const hidden of ['注文設定', '単位管理', '税率管理', '在庫・期限設定', '準備中']) assert.ok(!html.includes(hidden), hidden);
});
