import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { after, test } from 'node:test';
import { createRenderer, createSSRApp, nextTick } from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue(), {
  name: 'disposal-client-test',
  resolveId: id => id === 'virtual:disposal-client' ? id : null,
  load: id => id === 'virtual:disposal-client' ? compileScript(parse(readFileSync('resources/js/Components/ExpiredInventoryDisposalDialog.vue', 'utf8')).descriptor, { id: 'disposal-test', inlineTemplate: true }).content : null,
}], server: { middlewareMode: true, hmr: false }, appType: 'custom' });
const { default: Dialog } = await server.ssrLoadModule('/resources/js/Components/ExpiredInventoryDisposalDialog.vue');
const { default: ClientDialog } = await server.ssrLoadModule('virtual:disposal-client');
after(() => server.close());

const lot = (id, quantity, unit = { id: 1, name: '個', quantity_scale: 0 }) => ({
  id, current_quantity: quantity, lot_number: 'LOT-' + id, expires_on: '2026-11-13',
  product: { name: 'テスト商品' + id, sku: 'TEST-' + id, unit }, expiration: { type_label: '消費期限', status: 'expired', status_label: '期限切れ', remaining_label: '3日超過' },
});
const props = { today: '2026-11-16', reasons: ['期限切れ廃棄', '期限間近による廃棄', '品質上の判断による廃棄', 'その他'], notes: '在庫管理画面から一括廃棄' };

test('confirmation shows the selected lots, totals and irreversible-operation warning', async () => {
  const html = await renderToString(createSSRApp(Dialog, { ...props, lots: [lot(1, 10), lot(2, 5)] }));
  for (const text of ['2ロット', 'テスト商品1', 'TEST-1', 'LOT-1', '消費期限', '2026-11-13', '現在庫数', '廃棄数量', '15 個', '期限切れ廃棄', '在庫管理画面から一括廃棄', '2026-11-16', '通常の編集では元に戻せません', '実行時に最新在庫を再取得', '廃棄を実行']) {
    assert.ok(html.includes(text), text);
  }
  assert.match(html, /<dialog[^>]+aria-labelledby="disposal-title"/);
  assert.ok(!html.includes('type="number"'));
});

test('mixed units are totaled separately and processing disables execution and cancellation', async () => {
  const html = await renderToString(createSSRApp(Dialog, {
    ...props, lots: [lot(1, 10), lot(2, '1.125', { id: 2, name: 'kg', quantity_scale: 3 })],
    processing: true, errors: { lots: '対象の在庫が変更されています。' },
  }));
  assert.ok(html.includes('10 個'));
  assert.ok(html.includes('1.125 kg'));
  assert.ok(!html.includes('11.125'));
  assert.match(html, /<button[^>]+type="submit"[^>]+disabled/);
  assert.match(html, /<button[^>]+type="button"[^>]+disabled/);
  assert.ok(html.includes('廃棄処理中…'));
  assert.ok(html.includes('対象の在庫が変更されています。'));
});

for (const [status, label, remaining] of [['today', '本日期限', '本日期限'], ['near', '期限間近', '残り4日'], ['valid', '期限内', '残り196日']]) {
  test(`unexpired ${status} lots display warning, remaining days and confirmation checkbox`, async () => {
    const html = await renderToString(createSSRApp(Dialog, {
      ...props, lots: [{ ...lot(1, 10), expires_on: '2027-05-31', expiration: { type_label: '賞味期限', status, status_label: label, remaining_label: remaining } }],
    }));
    assert.ok(html.includes('期限前の在庫が選択されています'));
    assert.ok(html.includes('期限前の在庫が含まれていることを確認しました'));
    assert.ok(html.includes(label));
    assert.ok(html.includes(remaining));
    assert.match(html, /<button[^>]+type="submit"[^>]+disabled/);
  });
}

test('actual form guards submission, sends confirmation and selected reason, and requires other-reason notes', async (t) => {
  globalThis.Document = class {};
  globalThis.document = Object.assign(new Document(), { activeElement: null });
  const node = tag => ({
    tag, tagName: tag.toUpperCase(), props: {}, children: [],
    addEventListener() {}, removeEventListener() {}, getRootNode: () => globalThis.document,
    showModal() {}, close() {},
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
  const emitted = [];
  const near = { ...lot(2, 5), expires_on: '2026-11-20', expiration: { type_label: '消費期限', status: 'near', status_label: '期限間近', remaining_label: '残り4日' } };
  const app = renderer.createApp(ClientDialog, { ...props, lots: [lot(1, 10), near], onSubmit: value => emitted.push(value) });
  app.mount(root);
  t.after(() => { app.unmount(); delete globalThis.document; delete globalThis.Document; });
  const find = (el, predicate) => predicate(el) ? el : el.children?.map(child => find(child, predicate)).find(Boolean);
  const byId = id => find(root, el => el.props?.id === id);
  const submitButton = () => find(root, el => el.tag === 'button' && el.props.type === 'submit');
  const submit = () => find(root, el => el.tag === 'form').props.onSubmit({ preventDefault() {} });

  byId('disposal-reason').props['onUpdate:modelValue']('期限切れ廃棄');
  await nextTick();
  assert.equal(submitButton().props.disabled, true);
  submit();
  assert.equal(emitted.length, 0);
  find(root, el => el.tag === 'input' && el.props.type === 'checkbox').props['onUpdate:modelValue'](true);
  await nextTick();
  assert.equal(submitButton().props.disabled, false);
  submit();
  assert.equal(emitted[0].confirm_unexpired, true);
  assert.equal(emitted[0].reason, '期限切れ廃棄');
  assert.equal(emitted[0].occurred_on, '2026-11-16');

  byId('disposal-reason').props['onUpdate:modelValue']('その他');
  byId('disposal-notes').props['onUpdate:modelValue']('   ');
  await nextTick();
  assert.equal(submitButton().props.disabled, true);
  submit();
  assert.equal(emitted.length, 1);
  byId('disposal-notes').props['onUpdate:modelValue']('容器破損');
  await nextTick();
  assert.equal(submitButton().props.disabled, false);
  submit();
  assert.equal(emitted[1].reason, 'その他');
  assert.equal(emitted[1].notes, '容器破損');
});
