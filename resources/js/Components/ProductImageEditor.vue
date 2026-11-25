<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, disabled: Boolean, maxImages: { type: Number, default: 5 }, title: { type: String, default: '商品画像' }, uploadUrl: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue', 'reset']);
const error = ref('');
const previews = new Set();
let sequence = 0;
// サーバーの ProductImageInput::MAX_FILE_BYTES と同じ値（5MB = 5,000,000バイト）。
const MAX_FILE_BYTES = 5_000_000;
const rowKey = row => row.key || row.id;
const uploadFailedMessage = '画像をアップロードできませんでした。もう一度お試しください。';
function patch(key, values) {
  emit('update:modelValue', props.modelValue.map(row => rowKey(row) === key ? { ...row, ...values } : row));
}
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}
function uploadErrorMessage(status, data) {
  if (data?.errors?.file?.[0]) return data.errors.file[0];
  if (status === 413) return '画像のサイズが大きすぎます。1枚5MB以内にしてください。';
  if (status === 419) return '画面の有効期限が切れました。ページを再読み込みしてください。';
  return uploadFailedMessage;
}
// 画像を1枚ずつ別のリクエストで一時保存し、受け取った upload_id を行に記録する。
async function upload(key, file) {
  const body = new FormData();
  body.append('file', file);
  let values;
  try {
    const response = await fetch(props.uploadUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() } });
    const data = await response.json().catch(() => null);
    values = response.ok && data?.upload_id ? { upload_id: data.upload_id } : { upload_error: uploadErrorMessage(response.status, data) };
  } catch {
    values = { upload_error: uploadFailedMessage };
  }
  patch(key, { uploading: false, ...values });
}
async function uploadSequentially(rows) {
  for (const row of rows) {
    await upload(rowKey(row), row.file);
  }
}
function retry(row) {
  patch(rowKey(row), { uploading: true, upload_error: '' });
  upload(rowKey(row), row.file);
}
const uploadState = () => props.uploadUrl ? { upload_id: null, uploading: true, upload_error: '' } : {};
function valid(file) {
  if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) { error.value = 'JPEG・PNG・WebP・GIFの画像を選択してください。'; return false; }
  if (file.size > MAX_FILE_BYTES) { error.value = '画像は1枚5MB以内にしてください。'; return false; }
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
  error.value = '';
  if (props.modelValue.length + files.length > props.maxImages) { error.value = '画像は' + props.maxImages + '枚以内で登録してください。'; return; }
  if (!files.every(valid)) return;
  const added = files.map(file => ({ key: 'new-' + ++sequence, id: null, file, alt_text: '', preview: preview(file), ...uploadState() }));
  emit('update:modelValue', [...props.modelValue, ...added]);
  if (props.uploadUrl) uploadSequentially(added);
}
function replace(event, index) {
  const file = event.target.files?.[0]; event.target.value = ''; error.value = '';
  if (!file || !valid(file)) return;
  const rows = [...props.modelValue];
  rows[index] = { ...rows[index], key: rowKey(rows[index]) || 'new-' + ++sequence, file, preview: preview(file), ...uploadState() };
  emit('update:modelValue', rows);
  if (props.uploadUrl) uploadSequentially([rows[index]]);
}
function remove(index) { emit('update:modelValue', props.modelValue.filter((_, i) => i !== index)); }
function move(index, offset) {
  const rows = [...props.modelValue];
  [rows[index], rows[index + offset]] = [rows[index + offset], rows[index]];
  emit('update:modelValue', rows);
}
function updateAlt(index, value) {
  emit('update:modelValue', props.modelValue.map((row, i) => i === index ? { ...row, alt_text: value } : row));
}
function reset() { error.value = ''; emit('reset'); }
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
    <p class="mb-4 text-sm leading-6 text-slate-500">最大{{ maxImages }}枚、1枚5MBまで。JPEG・PNG・WebP・GIFに対応します。先頭が代表画像です。変更は商品を保存したときに反映されます。</p>
    <p v-if="error" role="alert" class="mb-4 text-sm text-red-700">{{ error }}</p>
    <div class="grid gap-4 sm:grid-cols-2">
      <div v-for="(row, index) in modelValue" :key="row.key || row.id" class="rounded-xl border border-slate-200 p-4">
        <img :src="row.preview || row.thumbnail_url" :alt="row.alt_text || '商品画像プレビュー'" class="aspect-square w-full rounded-lg bg-slate-50 object-contain" />
        <p class="my-3 text-sm font-semibold">{{ index === 0 ? '代表画像' : '画像 ' + (index + 1) }}<span v-if="row.file" class="ml-2 text-xs text-amber-700">未保存</span></p>
        <p v-if="row.uploading" role="status" class="mb-3 text-xs text-slate-500">アップロード中…</p>
        <div v-else-if="row.upload_error" class="mb-3 flex flex-wrap items-center gap-2">
          <p role="alert" class="text-xs text-red-700">{{ row.upload_error }}</p>
          <button type="button" class="rounded border px-3 py-1 text-xs" :disabled="disabled" @click="retry(row)">再試行</button>
        </div>
        <label class="block text-xs text-slate-600">画像の説明（任意）
          <input :value="row.alt_text" maxlength="200" class="field mt-1" @input="updateAlt(index, $event.target.value)" />
        </label>
        <label class="mt-3 block text-xs text-slate-600">差し替える
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-1 block w-full text-xs" @change="replace($event, index)" />
        </label>
        <div class="mt-4 flex flex-wrap gap-2">
          <button v-if="maxImages > 1" type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === 0 || disabled" :aria-label="'画像' + (index + 1) + 'を前へ移動'" @click="move(index, -1)">前へ</button>
          <button v-if="maxImages > 1" type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === modelValue.length - 1 || disabled" :aria-label="'画像' + (index + 1) + 'を後へ移動'" @click="move(index, 1)">後へ</button>
          <button type="button" class="rounded border border-red-200 px-3 py-2 text-xs text-red-700" :aria-label="'画像' + (index + 1) + 'を削除'" @click="remove(index)">削除</button>
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
      <button type="button" class="nav-link text-sm" @click="reset">画像の変更を取り消す</button>
    </div>
  </fieldset>
</template>
