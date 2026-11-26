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

function mount(imagesUrl = '/admin/products/7/images', disabled = false, initialRows = []) {
  globalThis.document = { cookie: 'XSRF-TOKEN=token%3D', activeElement: null };
  globalThis.window = { confirm: () => true };
  globalThis.URL.createObjectURL = file => `blob:${file.name}`;
  globalThis.URL.revokeObjectURL = () => {};
  const rows = ref(initialRows);
  const root = node('root');
  const app = renderer.createApp(defineComponent({
    setup: () => () => h(Editor, { modelValue: rows.value, imagesUrl, disabled, 'onUpdate:modelValue': value => { rows.value = value; } }),
  }));
  app.mount(root);
  const select = files => findAll(root, el => el.tag === 'input' && el.props.multiple)[0].props.onChange({ target: { files, value: 'selected' } });
  const dropZone = () => findAll(root, el => el.props?.['data-testid'] === 'image-drop-zone')[0];
  const dragEvent = files => ({ dataTransfer: { files }, preventDefault() {}, stopPropagation() {} });
  const drop = files => {
    dropZone().props.onDragenter(dragEvent([]));
    dropZone().props.onDrop(dragEvent(files));
  };
  const button = label => findAll(root, el => el.tag === 'button' && text(el) === label);

  return { rows, root, app, select, drop, dropZone, dragEvent, button };
}

function mockFetch(responses) {
  const calls = [];
  globalThis.fetch = (url, options) => new Promise(resolve => {
    calls.push({ url, options, resolve: () => resolve(responses.shift()) });
  });

  return calls;
}

const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const saved = (id, extra = {}) => ({ id, alt_text: null, thumbnail_url: `/product-images/${id}/thumbnail`, ...extra });

test('selected images are saved to the product one request per image, in order', async () => {
  const calls = mockFetch([json(201, { image: saved(11) }), json(201, { image: saved(12) })]);
  const { rows, root, app, select, button } = mount();

  select([image('first.jpg'), image('second.jpg')]);
  await flush();
  assert.equal(calls.length, 1, '2枚目は1枚目の完了を待ってから送る');
  assert.equal(calls[0].url, '/admin/products/7/images');
  assert.equal(calls[0].options.method, 'POST');
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

  assert.deepEqual(rows.value.map(row => [row.id, row.uploading, row.file, row.thumbnail_url]), [
    [11, false, null, '/product-images/11/thumbnail'],
    [12, false, null, '/product-images/12/thumbnail'],
  ]);
  assert.ok(!text(root).includes('アップロード中…'));
  assert.ok(!text(root).includes('未保存'));
  assert.equal(button('画像の変更を取り消す').length, 0, '即時保存では取り消しボタンを出さない');
  app.unmount();
});

test('failed upload shows the server message and can be retried', async () => {
  const calls = mockFetch([json(422, { errors: { file: ['商品画像は5枚以内で登録してください。'] } }), json(201, { image: saved(21) })]);
  const { rows, root, app, select, button } = mount();

  select([image('large.jpg')]);
  await flush();
  calls[0].resolve();
  await flush();
  assert.equal(rows.value[0].id, null);
  assert.ok(text(root).includes('商品画像は5枚以内で登録してください。'));

  button('再試行')[0].props.onClick();
  await flush();
  assert.equal(calls.length, 2);
  assert.equal(calls[1].url, '/admin/products/7/images');
  calls[1].resolve();
  await flush();
  assert.equal(rows.value[0].id, 21);
  assert.equal(rows.value[0].upload_error, '');
  app.unmount();
});

test('replacing an image posts to its replace URL', async () => {
  const calls = mockFetch([json(200, { image: saved(5, { thumbnail_url: '/product-images/5/thumbnail?v=new' }) })]);
  const { rows, root, app } = mount(undefined, false, [saved(5, { thumbnail_url: '/product-images/5/thumbnail?v=old' })]);

  const input = findAll(root, el => el.tag === 'input' && el.props.type === 'file' && !el.props.multiple)[0];
  input.props.onChange({ target: { files: [image('new.jpg')], value: 'selected' } });
  await flush();
  assert.equal(calls[0].url, '/admin/products/7/images/5/replace');
  calls[0].resolve();
  await flush();

  assert.deepEqual([rows.value[0].id, rows.value[0].thumbnail_url, rows.value[0].preview], [5, '/product-images/5/thumbnail?v=new', null]);
  app.unmount();
});

test('deleting asks for confirmation and removes the image on the server', async () => {
  const calls = mockFetch([json(204, null)]);
  const { rows, app, button } = mount(undefined, false, [saved(1), saved(2)]);

  globalThis.window.confirm = () => false;
  button('削除')[0].props.onClick();
  await flush();
  assert.equal(calls.length, 0);

  globalThis.window.confirm = () => true;
  button('削除')[0].props.onClick();
  await flush();
  assert.equal(calls[0].url, '/admin/products/7/images/1');
  assert.equal(calls[0].options.method, 'DELETE');
  calls[0].resolve();
  await flush();

  assert.deepEqual(rows.value.map(row => row.id), [2]);
  app.unmount();
});

test('moving saves the new order and reverts it when saving fails', async () => {
  const calls = mockFetch([json(204, null), json(422, { errors: { ids: ['画像が別の画面で変更されています。ページを再読み込みしてください。'] } })]);
  const { rows, root, app } = mount(undefined, false, [saved(1), saved(2), saved(3)]);
  const moveButton = label => findAll(root, el => el.tag === 'button' && el.props['aria-label'] === label)[0];

  moveButton('画像2を前へ移動').props.onClick();
  await flush();
  assert.equal(calls[0].url, '/admin/products/7/images/order');
  assert.deepEqual(JSON.parse(calls[0].options.body), { ids: [2, 1, 3] });
  calls[0].resolve();
  await flush();
  assert.deepEqual(rows.value.map(row => row.id), [2, 1, 3]);

  moveButton('画像3を前へ移動').props.onClick();
  await flush();
  assert.deepEqual(rows.value.map(row => row.id), [2, 3, 1]);
  calls[1].resolve();
  await flush();
  assert.deepEqual(rows.value.map(row => row.id), [2, 1, 3]);
  assert.ok(text(root).includes('画像が別の画面で変更されています。'));
  app.unmount();
});

test('alt text is saved when the field is changed', async () => {
  const calls = mockFetch([json(200, { image: saved(4, { alt_text: '正面' }) })]);
  const { rows, root, app } = mount(undefined, false, [saved(4)]);
  const altInput = () => findAll(root, el => el.tag === 'input' && String(el.props.maxlength) === '200')[0];

  altInput().props.onInput({ target: { value: '正面' } });
  await flush();
  altInput().props.onChange();
  await flush();
  assert.equal(calls[0].url, '/admin/products/7/images/4');
  assert.equal(calls[0].options.method, 'PATCH');
  assert.deepEqual(JSON.parse(calls[0].options.body), { alt_text: '正面' });
  calls[0].resolve();
  await flush();

  assert.equal(rows.value[0].alt_text, '正面');
  assert.ok(text(root).includes('説明を保存しました'));
  app.unmount();
});

test('GIF images are accepted and other formats are rejected before uploading', async () => {
  const calls = mockFetch([json(201, { image: saved(31) })]);
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
  assert.equal(rows.value[0].id, 31);
  assert.ok(findAll(root, el => el.tag === 'input' && el.props.multiple)[0].props.accept.split(',').includes('image/gif'));
  app.unmount();
});

test('dropping images on the select area uploads them like selecting them', async () => {
  const calls = mockFetch([json(201, { image: saved(41) }), json(201, { image: saved(42) })]);
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

  assert.deepEqual(rows.value.map(row => row.id), [41, 42]);
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

  const disabled = mount(undefined, true);
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

test('without an images URL (product groups) images stay in the form until it is saved', async () => {
  const calls = mockFetch([]);
  const { rows, root, app, select, button } = mount('');

  select([image('group.jpg')]);
  await flush();

  assert.equal(calls.length, 0);
  assert.equal(rows.value[0].file.name, 'group.jpg');
  assert.equal(rows.value[0].uploading, undefined);
  assert.ok(text(root).includes('未保存'));
  assert.equal(button('画像の変更を取り消す').length, 1);
  app.unmount();
});

const uploadErrorArea = root => findAll(root, el => el.props?.['data-testid'] === 'image-upload-errors')[0];
const messages = el => el.children.filter(child => child.tag === 'p').map(text);
const followsDropZone = root => {
  const zone = findAll(root, el => el.props?.['data-testid'] === 'image-drop-zone')[0];
  const area = uploadErrorArea(root);
  return zone.parent === area.parent && zone.parent.children.indexOf(area) === zone.parent.children.indexOf(zone) + 1;
};

test('upload errors are shown right below the select area with the file name', async () => {
  const calls = mockFetch([json(422, { errors: { file: ['画像を保存できませんでした。ファイルを確認して再度お試しください。'] } })]);
  const { root, app, select } = mount();

  select([new File(['bmp'], 'photo.bmp', { type: 'image/bmp' })]);
  await flush();
  assert.ok(followsDropZone(root), 'エラーは「画像を選択」の枠のすぐ下に出す');
  assert.deepEqual(messages(uploadErrorArea(root)), ['JPEG・PNG・WebP・GIFの画像を選択してください。']);

  select([image('broken.jpg')]);
  await flush();
  calls[0].resolve();
  await flush();
  assert.deepEqual(messages(uploadErrorArea(root)), ['broken.jpg：画像を保存できませんでした。ファイルを確認して再度お試しください。']);
  app.unmount();
});

test('errors other than uploads stay at the top of the image section', async () => {
  const calls = mockFetch([json(404, null)]);
  const { root, app, button } = mount(undefined, false, [saved(1)]);

  button('削除')[0].props.onClick();
  await flush();
  calls[0].resolve();
  await flush();

  assert.equal(uploadErrorArea(root), undefined);
  const alerts = findAll(root, el => el.props?.role === 'alert');
  assert.equal(text(alerts[0]), '画像が見つかりません。ページを再読み込みしてください。');
  assert.equal(alerts[0].parent.tag, 'fieldset');
  app.unmount();
});
