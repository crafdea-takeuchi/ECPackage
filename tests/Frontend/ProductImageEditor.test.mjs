import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { after, test } from 'node:test';
import { createRenderer, defineComponent, h, nextTick, ref } from 'vue';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

const server = await createServer({ configFile: false, plugins: [vue(), {
  name: 'product-image-editor-client-test',
  resolveId: id => id === 'virtual:product-image-editor-client' ? id : null,
  load: id => id === 'virtual:product-image-editor-client'
    ? compileScript(parse(readFileSync('resources/js/Components/ProductImageEditor.vue', 'utf8')).descriptor, { id: 'product-image-editor-test', inlineTemplate: true }).content
    : null,
}], server: { middlewareMode: true }, appType: 'custom' });
const { default: Editor } = await server.ssrLoadModule('virtual:product-image-editor-client');
after(() => server.close());

const node = tag => ({ tag, props: {}, children: [], addEventListener() {}, removeEventListener() {}, getRootNode: () => globalThis.document });
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
const findAll = (el, predicate) => [...(predicate(el) ? [el] : []), ...(el.children ?? []).flatMap(child => findAll(child, predicate))];
const text = el => el.text ?? (el.children ?? []).map(text).join('');
const flush = async () => { for (let i = 0; i < 5; i++) { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick(); } };
const image = name => new File(['image'], name, { type: 'image/jpeg' });

function mount(uploadUrl = '/admin/product-image-uploads', disabled = false) {
  globalThis.document = { cookie: 'XSRF-TOKEN=token%3D', activeElement: null };
  globalThis.URL.createObjectURL = file => `blob:${file.name}`;
  globalThis.URL.revokeObjectURL = () => {};
  const rows = ref([]);
  const root = node('root');
  const app = renderer.createApp(defineComponent({
    setup: () => () => h(Editor, { modelValue: rows.value, uploadUrl, disabled, 'onUpdate:modelValue': value => { rows.value = value; } }),
  }));
  app.mount(root);
  const select = files => findAll(root, el => el.tag === 'input' && el.props.multiple)[0].props.onChange({ target: { files, value: 'selected' } });
  const dropZone = () => findAll(root, el => el.props?.['data-testid'] === 'image-drop-zone')[0];
  const dragEvent = files => ({ dataTransfer: { files }, preventDefault() {}, stopPropagation() {} });
  const drop = files => {
    dropZone().props.onDragenter(dragEvent([]));
    dropZone().props.onDrop(dragEvent(files));
  };

  return { rows, root, app, select, drop, dropZone, dragEvent };
}

function mockFetch(responses) {
  const calls = [];
  globalThis.fetch = (url, options) => new Promise(resolve => {
    calls.push({ url, options, resolve: () => resolve(responses.shift()) });
  });

  return calls;
}

const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

test('selected images are uploaded one request per image, in order', async () => {
  const calls = mockFetch([json(201, { upload_id: 11 }), json(201, { upload_id: 12 })]);
  const { rows, root, app, select } = mount();

  select([image('first.jpg'), image('second.jpg')]);
  await flush();
  assert.equal(calls.length, 1, '2枚目は1枚目の完了を待ってから送る');
  assert.equal(calls[0].url, '/admin/product-image-uploads');
  assert.deepEqual([...calls[0].options.body.keys()], ['file']);
  assert.equal(calls[0].options.body.get('file').name, 'first.jpg');
  assert.equal(calls[0].options.headers['X-XSRF-TOKEN'], 'token=');
  assert.ok(text(root).includes('アップロード中…'));

  calls[0].resolve();
  await flush();
  assert.equal(calls.length, 2);
  assert.equal(calls[1].options.body.get('file').name, 'second.jpg');
  calls[1].resolve();
  await flush();

  assert.deepEqual(rows.value.map(row => [row.upload_id, row.uploading]), [[11, false], [12, false]]);
  assert.ok(!text(root).includes('アップロード中…'));
  app.unmount();
});

test('failed upload shows the server message and can be retried', async () => {
  const calls = mockFetch([json(422, { errors: { file: ['画像は1枚5MB以内にしてください。'] } }), json(201, { upload_id: 21 })]);
  const { rows, root, app, select } = mount();

  select([image('large.jpg')]);
  await flush();
  calls[0].resolve();
  await flush();
  assert.equal(rows.value[0].upload_id, null);
  assert.ok(text(root).includes('画像は1枚5MB以内にしてください。'));

  findAll(root, el => el.tag === 'button' && text(el) === '再試行')[0].props.onClick();
  await flush();
  assert.equal(calls.length, 2);
  calls[1].resolve();
  await flush();
  assert.equal(rows.value[0].upload_id, 21);
  assert.equal(rows.value[0].upload_error, '');
  app.unmount();
});

test('GIF images are accepted and other formats are rejected before uploading', async () => {
  const calls = mockFetch([json(201, { upload_id: 31 })]);
  const { rows, root, app, select } = mount();

  select([new File(['bmp'], 'photo.bmp', { type: 'image/bmp' })]);
  await flush();
  assert.equal(calls.length, 0);
  assert.ok(text(root).includes('JPEG・PNG・WebP・GIFの画像を選択してください。'));

  select([new File(['gif'], 'animation.gif', { type: 'image/gif' })]);
  await flush();
  assert.equal(calls.length, 1);
  calls[0].resolve();
  await flush();
  assert.equal(rows.value[0].upload_id, 31);
  assert.ok(findAll(root, el => el.tag === 'input' && el.props.multiple)[0].props.accept.split(',').includes('image/gif'));
  app.unmount();
});

test('dropping images on the select area uploads them like selecting them', async () => {
  const calls = mockFetch([json(201, { upload_id: 41 }), json(201, { upload_id: 42 })]);
  const { rows, root, app, drop, dropZone, dragEvent } = mount();

  dropZone().props.onDragenter(dragEvent([]));
  await flush();
  assert.ok(text(root).includes('ここで離すと追加します'));

  drop([image('dropped-1.jpg'), image('dropped-2.jpg')]);
  await flush();
  assert.ok(text(root).includes('またはここに画像をドラッグ＆ドロップ'), 'ドロップ後は表示が元に戻る');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.body.get('file').name, 'dropped-1.jpg');
  calls[0].resolve();
  await flush();
  calls[1].resolve();
  await flush();

  assert.deepEqual(rows.value.map(row => [row.file.name, row.upload_id]), [['dropped-1.jpg', 41], ['dropped-2.jpg', 42]]);
  app.unmount();
});

test('dropped files are validated and ignored while the editor is disabled', async () => {
  const calls = mockFetch([]);
  const invalid = mount();
  invalid.drop([new File(['pdf'], 'manual.pdf', { type: 'application/pdf' })]);
  await flush();
  assert.equal(invalid.rows.value.length, 0);
  assert.ok(text(invalid.root).includes('JPEG・PNG・WebP・GIFの画像を選択してください。'));
  invalid.drop([image('1.jpg'), image('2.jpg'), image('3.jpg'), image('4.jpg'), image('5.jpg'), image('6.jpg')]);
  await flush();
  assert.equal(invalid.rows.value.length, 0);
  assert.ok(text(invalid.root).includes('画像は5枚以内で登録してください。'));
  invalid.app.unmount();

  const disabled = mount('/admin/product-image-uploads', true);
  disabled.drop([image('disabled.jpg')]);
  await flush();
  assert.equal(disabled.rows.value.length, 0);
  assert.equal(calls.length, 0);
  disabled.app.unmount();
});

test('images over 5,000,000 bytes are rejected before uploading', async () => {
  const calls = mockFetch([]);
  const { rows, root, app, select } = mount();

  select([new File([new Uint8Array(5_000_001)], 'over.jpg', { type: 'image/jpeg' })]);
  await flush();

  assert.equal(calls.length, 0);
  assert.equal(rows.value.length, 0);
  assert.ok(text(root).includes('画像は1枚5MB以内にしてください。'));
  app.unmount();
});

test('without an upload URL images stay in the form and nothing is sent', async () => {
  const calls = mockFetch([]);
  const { rows, app, select } = mount('');

  select([image('group.jpg')]);
  await flush();

  assert.equal(calls.length, 0);
  assert.equal(rows.value[0].file.name, 'group.jpg');
  assert.equal(rows.value[0].uploading, undefined);
  app.unmount();
});
