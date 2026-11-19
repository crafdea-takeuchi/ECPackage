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
const { default: ShippingForm } = await server.ssrLoadModule('/resources/js/Pages/Admin/Settings/Shipping.vue');
const { default: FeeForm } = await server.ssrLoadModule('/resources/js/Pages/Admin/Settings/Fees.vue');
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
    { title: '手数料設定', description: '支払方法に応じた手数料', status: '現在の代引き手数料：330円', href: '/admin/settings/fees', button: '設定を開く' },
    { title: '送料設定', description: '地域ごとの送料', status: '送料が設定されていません', warning: true, href: '/admin/settings/shipping', button: '設定を開く' },
  ] });
  for (const label of ['各種設定', '販売商品', '基本商品・属性', '商品分類', '仕入先', '入庫', 'ロット在庫', '出庫・在庫調整', '注文', '発送', '取り寄せ', '注文設定', 'アカウント']) assert.ok(html.includes(label), label);
  assert.ok(html.includes('href="/admin/settings"'));
  assert.ok(html.includes('href="/admin/order-settings"'));
  assert.ok(html.includes('href="/admin/settings/bank-transfer"'));
  assert.ok(!html.includes('href="/admin/units"'));
  assert.ok(!html.includes('href="/admin/tax-rates"'));
  assert.ok(html.includes('grid-cols-1'));
  assert.ok(html.includes('md:grid-cols-2'));
  assert.ok(html.includes('手数料設定'));
  assert.ok(html.includes('現在の代引き手数料：330円'));
  assert.ok(html.includes('href="/admin/settings/fees"'));
  assert.ok(html.includes('href="/admin/settings/shipping"'));
  assert.ok(html.includes('送料設定'));
  assert.ok(html.includes('xl:grid-cols-3'));
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

test('single bank card still shows unregistered warning without other settings cards', async () => {
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

for (const [value, input, label] of [['330.0000', '330', '330円（税込）'], ['0.0000', '0', '0円（代引き手数料無料）'], [null, '', '未設定']]) {
  test(`fee form displays ${label} without four-decimal formatting`, async () => {
    const html = await renderPage(FeeForm, {
      cashOnDeliveryFee: value, cashOnDeliveryAvailable: false, maxFee: '999999999999999',
      saveUrl: '/admin/settings/fees', settingsUrl: '/admin/settings',
    });
    assert.ok(html.includes('代引き手数料（税込）'));
    assert.match(html, /id="cod-fee"[^>]+type="number"[^>]+min="0"[^>]+step="1"/);
    assert.ok(html.includes(`value="${input}"`));
    assert.ok(html.includes(label));
    assert.ok(html.includes('現在の注文金額には加算されません'));
    assert.ok(html.includes('min-w-0'));
    assert.ok(html.includes('円'));
    assert.ok(!html.includes('value="330.0000"'));
  });
}

test('fee validation errors are displayed next to the amount field', async () => {
  const html = await renderPage(FeeForm, {
    cashOnDeliveryFee: null, cashOnDeliveryAvailable: false, maxFee: '999999999999999',
    saveUrl: '/admin/settings/fees', settingsUrl: '/admin/settings',
    errors: { cash_on_delivery_fee: '代引き手数料は0円以上で入力してください。' },
  });
  assert.ok(html.includes('id="cod-fee-error"'));
  assert.ok(html.includes('代引き手数料は0円以上で入力してください。'));
  assert.ok(html.includes('aria-invalid="true"'));
});

const shippingZones = [
  { code: 'hokkaido', name: '北海道', prefectures: ['北海道'] },
  { code: 'tohoku', name: '東北', prefectures: ['青森県'] },
  { code: 'kanto_shinetsu', name: '関東・信越', prefectures: ['山梨県', '長野県'] },
  { code: 'hokuriku_chubu', name: '北陸・中部', prefectures: ['新潟県'] },
  { code: 'kansai_chugoku', name: '関西・中国', prefectures: ['大阪府'] },
  { code: 'shikoku', name: '四国', prefectures: ['愛媛県'] },
  { code: 'kyushu', name: '九州', prefectures: ['福岡県'] },
  { code: 'okinawa', name: '沖縄', prefectures: ['沖縄県'] },
];
const shippingProps = { zones: shippingZones, shippingZoneFees: null, freeShippingThresholds: null, postalCodeGroups: [], postalCodeGroupStoreUrl: '/admin/settings/shipping/postal-code-groups', maxAmount: '999999999999999', saveUrl: '/admin/settings/shipping', settingsUrl: '/admin/settings' };

test('shipping form initially leaves eight fees and regional thresholds empty', async () => {
  const html = await renderPage(ShippingForm, shippingProps);
  for (const zone of shippingZones) {
    assert.ok(html.includes(zone.name));
    assert.match(html, new RegExp('id="shipping-' + zone.code + '"[^>]*value=""'));
    assert.match(html, new RegExp('id="shipping-' + zone.code + '"[^>]*required'));
    assert.match(html, new RegExp('id="free-shipping-' + zone.code + '"[^>]*value=""'));
    assert.doesNotMatch(html, new RegExp('id="free-shipping-' + zone.code + '"[^>]*required'));
  }
  assert.ok(!html.includes('id="free-shipping-threshold"'));
  for (const text of ['山梨県・長野県', '新潟県', 'xl:table', 'xl:table-row', 'xl:table-cell']) assert.ok(html.includes(text), text);
});

test('shipping form shows editable postal code shipping groups and a new-group form', async () => {
  const html = await renderPage(ShippingForm, { ...shippingProps, postalCodeGroups: [{
    id: 1, name: '特別配送地域A', shipping_fee: '2500.0000', free_shipping_threshold: '30000.0000',
    postal_codes: ['1000101', '1000102'],
    update_url: '/admin/settings/shipping/postal-code-groups/1',
    delete_url: '/admin/settings/shipping/postal-code-groups/1',
  }] });
  for (const text of ['郵便番号別送料グループ', '特別配送地域A', '100-0101', '100-0102', '2件の郵便番号を登録済み', 'グループを削除', '郵便番号別送料グループを追加']) assert.ok(html.includes(text), text);
  assert.match(html, /id="postal-code-fee-1"[^>]*value="2500"/);
  assert.match(html, /id="postal-code-threshold-1"[^>]*value="30000"/);
  assert.ok(html.includes('同じ郵便番号を複数のグループへ登録することはできません。'));
});
test('shipping form displays different regional thresholds and distinguishes blank from zero', async () => {
  const html = await renderPage(ShippingForm, { ...shippingProps,
    shippingZoneFees: { hokkaido: '1500.0000', tohoku: '0.0000' },
    freeShippingThresholds: { hokkaido: null, tohoku: '0.0000', kyushu: '15000.0000', okinawa: '30000.0000' },
  });
  for (const [id, value] of [['shipping-hokkaido', '1500'], ['shipping-tohoku', '0'], ['free-shipping-hokkaido', ''], ['free-shipping-tohoku', '0'], ['free-shipping-kyushu', '15000'], ['free-shipping-okinawa', '30000']]) {
    assert.match(html, new RegExp('id="' + id + '"[^>]*value="' + value + '"'));
  }
  assert.ok(!html.includes('value="1500.0000"'));
});

test('shipping form preserves zero thresholds in every region', async () => {
  const html = await renderPage(ShippingForm, { ...shippingProps, freeShippingThresholds: Object.fromEntries(shippingZones.map(zone => [zone.code, '0.0000'])) });
  for (const zone of shippingZones) assert.match(html, new RegExp('id="free-shipping-' + zone.code + '"[^>]*value="0"'));
});

test('shipping validation errors appear next to the relevant regional amount', async () => {
  const html = await renderPage(ShippingForm, { ...shippingProps, errors: {
    'shipping_zone_fees.hokkaido': '北海道の送料を入力してください。',
    'free_shipping_thresholds.okinawa': '沖縄の送料無料基準額は0円以上で入力してください。',
  } });
  for (const text of ['id="error-hokkaido"', '北海道の送料を入力してください。', 'id="threshold-error-okinawa"', '沖縄の送料無料基準額は0円以上で入力してください。']) assert.ok(html.includes(text), text);
  assert.match(html, /id="shipping-hokkaido"[^>]*aria-invalid="true"/);
  assert.match(html, /id="free-shipping-okinawa"[^>]*aria-invalid="true"/);
});

test('transfer name note is shown only when requested by customer screens', async () => {
  const note = 'お振込みの際は、振込依頼人名の後ろに注文番号を付けてお振込みください。';
  const adminHtml = await renderToString(createSSRApp(Instructions, { paymentMethod: 'bank_transfer', bank }));
  assert.ok(!adminHtml.includes(note));
  assert.ok(!adminHtml.includes('記入例'));
  const customerHtml = await renderToString(createSSRApp(Instructions, { paymentMethod: 'bank_transfer', bank, showTransferNote: true }));
  assert.ok(customerHtml.includes(note));
  assert.ok(customerHtml.includes('記入例：ヤマダタロウ ON2611AB23'));
  const feeNote = '※お振込の際には別途振込手数料がかかります。';
  assert.ok(customerHtml.indexOf('記入例：ヤマダタロウ ON2611AB23') < customerHtml.indexOf(feeNote));
  assert.ok(!adminHtml.includes(feeNote));
  const legacyHtml = await renderToString(createSSRApp(Instructions, { paymentMethod: 'bank_transfer', bank: null, showTransferNote: true }));
  assert.ok(!legacyHtml.includes(note));
});
