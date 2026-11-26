<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

// imagesUrl を渡すと、追加・差し替え・説明の変更・並べ替え・削除を操作した時点でサーバーへ保存する（販売商品）。
// 渡さない場合は、選んだファイルを v-model に持ち、親フォームの保存時にまとめて送る（基本商品）。
const props = defineProps({ modelValue: { type: Array, default: () => [] }, disabled: Boolean, maxImages: { type: Number, default: 5 }, title: { type: String, default: '商品画像' }, imagesUrl: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue', 'reset']);
// 削除・並べ替えなど、アップロード以外の操作のエラー（画像欄の上に表示）。
const error = ref('');
// 画像を選んだときのチェックで見つかったエラー（「画像を選択」の枠の下に表示）。
const selectError = ref('');
const reordering = ref(false);
const previews = new Set();
let sequence = 0;
// サーバーの ProductImageInput::MAX_FILE_BYTES と同じ値（5MB = 5,000,000バイト）。
const MAX_FILE_BYTES = 5_000_000;
const rowKey = row => row.key || row.id;
const failedMessage = '画像を保存できませんでした。もう一度お試しください。';
const savedState = { file: null, preview: null, uploading: false, upload_error: '' };

function patch(key, values) {
  emit('update:modelValue', props.modelValue.map(row => rowKey(row) === key ? { ...row, ...values } : row));
}
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}
function errorMessage(status, data) {
  const first = Object.values(data?.errors ?? {})[0];
  if (Array.isArray(first) && first[0]) return first[0];
  if (status === 413) return '画像のサイズが大きすぎます。1枚5MB以内にしてください。';
  if (status === 419) return '画面の有効期限が切れました。ページを再読み込みしてください。';
  if (status === 404) return '画像が見つかりません。ページを再読み込みしてください。';
  return failedMessage;
}
async function send(url, method, body) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() };
  let payload = body;
  if (body && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch(url, { method, body: payload, credentials: 'same-origin', headers });
  } catch {
    throw new Error(failedMessage);
  }
  const data = response.status === 204 ? null : await response.json().catch(() => null);
  if (!response.ok) throw new Error(errorMessage(response.status, data));
  return data;
}
function fileBody(file) {
  const body = new FormData();
  body.append('file', file);
  return body;
}
const imageUrl = row => `${props.imagesUrl}/${row.id}`;

// 追加した画像は1枚ずつ順番に送り、保存された画像で行を置き換える。
// emit 直後は props がまだ更新されていないため、送る行は引数で受け取る。
async function upload(row) {
  const key = rowKey(row);
  try {
    const data = await send(row.id ? `${imageUrl(row)}/replace` : props.imagesUrl, 'POST', fileBody(row.file));
    patch(key, { ...data.image, ...savedState });
  } catch (exception) {
    patch(key, { uploading: false, upload_error: exception.message });
  }
}
async function uploadSequentially(rows) {
  for (const row of rows) {
    await upload(row);
  }
}
function retry(row) {
  patch(rowKey(row), { uploading: true, upload_error: '' });
  upload(row);
}
function valid(file) {
  if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) { selectError.value = 'JPEG・PNG・WebP・GIFの画像を選択してください。'; return false; }
  if (file.size > MAX_FILE_BYTES) { selectError.value = '画像は1枚5MB以内にしてください。'; return false; }
  return true;
}
function preview(file) {
  const url = URL.createObjectURL(file); previews.add(url); return url;
}
function add(event) {
  const files = Array.from(event.target.files || []);
  event.target.value = '';
  addFiles(files);
}
const dragging = ref(false);
let dragDepth = 0;
function dragEnter() {
  if (props.disabled) return;
  dragDepth++;
  dragging.value = true;
}
function dragLeave() {
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) dragging.value = false;
}
function drop(event) {
  dragDepth = 0;
  dragging.value = false;
  if (props.disabled) return;
  const files = Array.from(event.dataTransfer?.files || []);
  if (files.length) addFiles(files);
}
function addFiles(files) {
  selectError.value = '';
  if (props.modelValue.length + files.length > props.maxImages) { selectError.value = '画像は' + props.maxImages + '枚以内で登録してください。'; return; }
  if (!files.every(valid)) return;
  const uploading = props.imagesUrl ? { uploading: true, upload_error: '' } : {};
  const added = files.map(file => ({ key: 'new-' + ++sequence, id: null, file, alt_text: '', preview: preview(file), ...uploading }));
  emit('update:modelValue', [...props.modelValue, ...added]);
  if (props.imagesUrl) uploadSequentially(added);
}
function replace(event, index) {
  const file = event.target.files?.[0]; event.target.value = ''; selectError.value = '';
  if (!file || !valid(file)) return;
  const rows = [...props.modelValue];
  rows[index] = { ...rows[index], key: rowKey(rows[index]) || 'new-' + ++sequence, file, preview: preview(file), ...(props.imagesUrl ? { uploading: true, upload_error: '' } : {}) };
  emit('update:modelValue', rows);
  if (props.imagesUrl) uploadSequentially([rows[index]]);
}
async function remove(index) {
  const row = props.modelValue[index];
  error.value = '';
  if (!props.imagesUrl || !row.id) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
    return;
  }
  if (!window.confirm('この画像を削除しますか？削除した画像は元に戻せません。')) return;
  patch(rowKey(row), { deleting: true });
  try {
    await send(imageUrl(row), 'DELETE');
    emit('update:modelValue', props.modelValue.filter(item => rowKey(item) !== rowKey(row)));
  } catch (exception) {
    patch(rowKey(row), { deleting: false });
    error.value = exception.message;
  }
}
async function move(index, offset) {
  const previous = props.modelValue;
  const rows = [...previous];
  [rows[index], rows[index + offset]] = [rows[index + offset], rows[index]];
  error.value = '';
  emit('update:modelValue', rows);
  if (!props.imagesUrl) return;
  reordering.value = true;
  try {
    await send(`${props.imagesUrl}/order`, 'PUT', { ids: rows.filter(row => row.id).map(row => row.id) });
  } catch (exception) {
    emit('update:modelValue', previous);
    error.value = exception.message;
  } finally {
    reordering.value = false;
  }
}
function updateAlt(index, value) {
  emit('update:modelValue', props.modelValue.map((row, i) => i === index ? { ...row, alt_text: value, alt_saved: false, alt_error: '' } : row));
}
async function saveAlt(row) {
  if (!props.imagesUrl || !row.id) return;
  try {
    const data = await send(imageUrl(row), 'PATCH', { alt_text: row.alt_text || null });
    patch(rowKey(row), { alt_text: data.image.alt_text ?? '', alt_saved: true, alt_error: '' });
  } catch (exception) {
    patch(rowKey(row), { alt_saved: false, alt_error: exception.message });
  }
}
// アップロードに失敗した画像のエラーは、どの画像か分かるようファイル名を添えて枠の下に並べる。
const uploadErrors = computed(() => props.modelValue
  .filter(row => row.upload_error)
  .map(row => ({ key: rowKey(row), message: row.file?.name ? `${row.file.name}：${row.upload_error}` : row.upload_error })));
const moveLocked = () => props.disabled || reordering.value || props.modelValue.some(row => row.uploading || row.deleting);
function reset() { error.value = ''; selectError.value = ''; emit('reset'); }
watch(() => props.modelValue.map(row => row.preview), active => {
  for (const url of previews) {
    if (!active.includes(url)) { URL.revokeObjectURL(url); previews.delete(url); }
  }
});
onBeforeUnmount(() => previews.forEach(url => URL.revokeObjectURL(url)));
</script>

<template>
  <fieldset :disabled="disabled" class="mt-8 min-w-0 border-t border-slate-200 pt-6">
    <legend class="px-1 text-lg font-semibold">{{ title }}</legend>
    <p class="mb-4 text-sm leading-6 text-slate-500">最大{{ maxImages }}枚、1枚5MBまで。JPEG・PNG・WebP・GIFに対応します。先頭が代表画像です。{{ imagesUrl ? '画像の追加・差し替え・削除・並べ替え・説明の変更は、操作した時点で保存されます。' : '変更は商品を保存したときに反映されます。' }}</p>
    <p v-if="error" role="alert" class="mb-4 text-sm text-red-700">{{ error }}</p>
    <div class="grid gap-4 sm:grid-cols-2">
      <div v-for="(row, index) in modelValue" :key="row.key || row.id" class="rounded-xl border border-slate-200 p-4">
        <img :src="row.preview || row.thumbnail_url" :alt="row.alt_text || '商品画像プレビュー'" class="aspect-square w-full rounded-lg bg-slate-50 object-contain" />
        <p class="my-3 text-sm font-semibold">{{ index === 0 ? '代表画像' : '画像 ' + (index + 1) }}<span v-if="row.file && !imagesUrl" class="ml-2 text-xs text-amber-700">未保存</span></p>
        <p v-if="row.uploading" role="status" class="mb-3 text-xs text-slate-500">アップロード中…</p>
        <div v-else-if="row.upload_error" class="mb-3 flex flex-wrap items-center gap-2">
          <p class="text-xs text-red-700">アップロードできませんでした</p>
          <button type="button" class="rounded border px-3 py-1 text-xs" :disabled="disabled" @click="retry(row)">再試行</button>
        </div>
        <label class="block text-xs text-slate-600">画像の説明（任意）
          <input :value="row.alt_text" maxlength="200" class="field mt-1" :disabled="Boolean(imagesUrl && !row.id)" @input="updateAlt(index, $event.target.value)" @change="saveAlt(row)" />
        </label>
        <p v-if="row.alt_error" role="alert" class="mt-1 text-xs text-red-700">{{ row.alt_error }}</p>
        <p v-else-if="row.alt_saved" role="status" class="mt-1 text-xs text-emerald-700">説明を保存しました</p>
        <label class="mt-3 block text-xs text-slate-600">差し替える
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-1 block w-full text-xs" :disabled="Boolean(row.uploading || row.deleting || (imagesUrl && !row.id))" @change="replace($event, index)" />
        </label>
        <div class="mt-4 flex flex-wrap gap-2">
          <button v-if="maxImages > 1" type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === 0 || moveLocked()" :aria-label="'画像' + (index + 1) + 'を前へ移動'" @click="move(index, -1)">前へ</button>
          <button v-if="maxImages > 1" type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === modelValue.length - 1 || moveLocked()" :aria-label="'画像' + (index + 1) + 'を後へ移動'" @click="move(index, 1)">後へ</button>
          <button type="button" class="rounded border border-red-200 px-3 py-2 text-xs text-red-700 disabled:opacity-40" :disabled="Boolean(row.uploading || row.deleting)" :aria-label="'画像' + (index + 1) + 'を削除'" @click="remove(index)">{{ row.deleting ? '削除中…' : '削除' }}</button>
        </div>
      </div>
    </div>
    <p v-if="!modelValue.length" class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">画像は未登録です。画像なしでも保存できます。</p>
    <div class="mt-5 space-y-4">
      <div
        v-if="modelValue.length < maxImages"
        data-testid="image-drop-zone"
        class="flex flex-wrap items-center gap-4 rounded-xl border-2 border-dashed p-4 transition-colors"
        :class="dragging ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300'"
        @dragenter.prevent="dragEnter"
        @dragover.prevent
        @dragleave="dragLeave"
        @drop.prevent="drop"
      >
        <label class="inline-block">
          <input type="file" :multiple="maxImages > 1" accept="image/jpeg,image/png,image/webp,image/gif" class="peer sr-only" :disabled="disabled" @change="add" />
          <span class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-5 py-3 text-sm font-semibold text-indigo-700 transition-colors hover:border-indigo-500 hover:bg-indigo-100 hover:text-indigo-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600 peer-disabled:pointer-events-none peer-disabled:cursor-not-allowed peer-disabled:opacity-50">
            <span aria-hidden="true" class="text-lg leading-none">＋</span>
            画像を選択
          </span>
        </label>
        <span class="text-sm text-slate-500">{{ dragging ? 'ここで離すと追加します' : 'またはここに画像をドラッグ＆ドロップ' }}</span>
      </div>
      <div v-if="selectError || uploadErrors.length" role="alert" data-testid="image-upload-errors" class="space-y-1 text-sm text-red-700">
        <p v-if="selectError">{{ selectError }}</p>
        <p v-for="item in uploadErrors" :key="item.key">{{ item.message }}</p>
      </div>
      <button v-if="!imagesUrl" type="button" class="nav-link text-sm" @click="reset">画像の変更を取り消す</button>
    </div>
  </fieldset>
</template>
