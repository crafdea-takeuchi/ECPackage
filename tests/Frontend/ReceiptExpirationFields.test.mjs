import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { after, test } from 'node:test';
import { createRenderer, createSSRApp, reactive, nextTick } from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue(), {
  name: 'receipt-client-test',
  resolveId: id => id === 'virtual:receipt-client' ? id : null,
  load: id => id === 'virtual:receipt-client'
    ? compileScript(parse(readFileSync('resources/js/Components/ReceiptExpirationFields.vue', 'utf8')).descriptor, { id: 'receipt-test', inlineTemplate: true }).content
    : null,
}], server: { middlewareMode: true }, appType: 'custom' });
const { default: Fields } = await server.ssrLoadModule('/resources/js/Components/ReceiptExpirationFields.vue');
const { default: ClientFields } = await server.ssrLoadModule('virtual:receipt-client');
after(() => server.close());

for (const type of ['best_before', 'use_by']) {
  test(`editing displays saved ${type} and required expiration date`, async () => {
    const html = await renderToString(createSSRApp(Fields, { index: 0, line: { expiration_type: type, expires_on: '2027-05-31', manufactured_on: '2027-05-01' } }));
    assert.ok(html.includes(`value="${type}"`));
    assert.ok(html.includes('value="2027-05-31"'));
    assert.ok(html.includes('min="2027-05-01"'));
    assert.ok(html.includes('required'));
    assert.ok(html.includes('賞味期限'));
    assert.ok(html.includes('消費期限'));
  });
}

test('no expiration disables the date and legacy dates remain visible', async () => {
  const none = await renderToString(createSSRApp(Fields, { index: 0, line: { expiration_type: null, expires_on: '' } }));
  assert.match(none, /type="date"[^>]*disabled/);
  const legacy = await renderToString(createSSRApp(Fields, { index: 0, line: { expiration_type: null, expires_on: '2027-05-31' } }));
  assert.ok(legacy.includes('種別未設定（既存データ）'));
  assert.ok(legacy.includes('value="2027-05-31"'));
});

test('changing to no expiration clears only the selected line date', async () => {
  globalThis.Document = class {};
  globalThis.document = Object.assign(new Document(), { activeElement: null });
  const node = tag => ({ tag, tagName: tag.toUpperCase(), props: {}, children: [], addEventListener() {}, removeEventListener() {}, getRootNode: () => globalThis.document });
  const renderer = createRenderer({
    createElement: node,
    createText: text => ({ text }),
    createComment: text => ({ text }),
    setText: (el, text) => { el.text = text; },
    setElementText: (el, text) => { el.text = text; },
    patchProp: (el, key, previous, value) => { el.props[key] = value; },
    insert: (el, parent, anchor) => {
      if (el.parent) el.parent.children = el.parent.children.filter(child => child !== el);
      const index = anchor ? parent.children.indexOf(anchor) : -1;
      if (index >= 0) parent.children.splice(index, 0, el);
      else parent.children.push(el);
      el.parent = parent;
    },
    remove: el => { el.parent.children = el.parent.children.filter(child => child !== el); },
    parentNode: el => el.parent,
    nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
  });
  const line = reactive({ expiration_type: 'best_before', expires_on: '2027-05-31' });
  const otherLine = reactive({ expiration_type: 'use_by', expires_on: '2027-06-01' });
  const root = node('root');
  const app = renderer.createApp(ClientFields, { line, index: 0 });
  app.mount(root);
  const find = (el, tag) => el.tag === tag ? el : el.children?.map(child => find(child, tag)).find(Boolean);
  find(root, 'select').props.onChange({ target: { value: '' } });
  assert.equal(line.expiration_type, null);
  assert.equal(line.expires_on, '');
  assert.equal(otherLine.expires_on, '2027-06-01');
  await nextTick();
  assert.equal(find(root, 'input').props.disabled, true);
  find(root, 'select').props.onChange({ target: { value: 'use_by' } });
  await nextTick();
  assert.equal(find(root, 'input').props.disabled, false);
  assert.equal(find(root, 'input').props.required, true);
  app.unmount();
  delete globalThis.document;
  delete globalThis.Document;
});
