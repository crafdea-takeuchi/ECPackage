<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, disabled: Boolean });
const emit = defineEmits(['update:modelValue', 'reset']);
const error = ref('');
const previews = new Set();
let sequence = 0;
function valid(file) {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { error.value = 'JPEG・PNG・WebPの画像を選択してください。'; return false; }
  if (file.size > 5 * 1024 * 1024) { error.value = '画像は1枚5MB以内にしてください。'; return false; }
  return true;
}
function preview(file) {
  const url = URL.createObjectURL(file); previews.add(url); return url;
}
function add(event) {
  error.value = '';
  const files = Array.from(event.target.files || []);
  event.target.value = '';
  if (props.modelValue.length + files.length > 5) { error.value = '商品画像は5枚以内で登録してください。'; return; }
  if (!files.every(valid)) return;
  emit('update:modelValue', [...props.modelValue, ...files.map(file => ({ key: 'new-' + ++sequence, id: null, file, alt_text: '', preview: preview(file) }))]);
}
function replace(event, index) {
  const file = event.target.files?.[0]; event.target.value = ''; error.value = '';
  if (!file || !valid(file)) return;
  const rows = [...props.modelValue];
  rows[index] = { ...rows[index], file, preview: preview(file) };
  emit('update:modelValue', rows);
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
    <legend class="px-1 text-lg font-semibold">商品画像</legend>
    <p class="mb-4 text-sm leading-6 text-slate-500">最大5枚、1枚5MBまで。JPEG・PNG・WebPに対応します。先頭が代表画像です。変更は商品を保存したときに反映されます。</p>
    <p v-if="error" role="alert" class="mb-4 text-sm text-red-700">{{ error }}</p>
    <div class="grid gap-4 sm:grid-cols-2">
      <div v-for="(row, index) in modelValue" :key="row.key || row.id" class="rounded-xl border border-slate-200 p-4">
        <img :src="row.preview || row.thumbnail_url" :alt="row.alt_text || '商品画像プレビュー'" class="aspect-square w-full rounded-lg bg-slate-50 object-contain" />
        <p class="my-3 text-sm font-semibold">{{ index === 0 ? '代表画像' : '画像 ' + (index + 1) }}<span v-if="row.file" class="ml-2 text-xs text-amber-700">未保存</span></p>
        <label class="block text-xs text-slate-600">画像の説明（任意）
          <input :value="row.alt_text" maxlength="200" class="field mt-1" @input="updateAlt(index, $event.target.value)" />
        </label>
        <label class="mt-3 block text-xs text-slate-600">差し替える
          <input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-xs" @change="replace($event, index)" />
        </label>
        <div class="mt-4 flex flex-wrap gap-2">
          <button type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === 0 || disabled" :aria-label="'画像' + (index + 1) + 'を前へ移動'" @click="move(index, -1)">前へ</button>
          <button type="button" class="rounded border px-3 py-2 text-xs disabled:opacity-40" :disabled="index === modelValue.length - 1 || disabled" :aria-label="'画像' + (index + 1) + 'を後へ移動'" @click="move(index, 1)">後へ</button>
          <button type="button" class="rounded border border-red-200 px-3 py-2 text-xs text-red-700" :aria-label="'画像' + (index + 1) + 'を削除'" @click="remove(index)">削除</button>
        </div>
      </div>
    </div>
    <p v-if="!modelValue.length" class="rounded-lg bg-slate-50 p-5 text-sm text-slate-500">画像は未登録です。画像なしでも保存できます。</p>
    <div class="mt-5 space-y-4">
      <label v-if="modelValue.length < 5" class="inline-block">
        <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="peer sr-only" :disabled="disabled" @change="add" />
        <span class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-5 py-3 text-sm font-semibold text-indigo-700 transition-colors hover:border-indigo-500 hover:bg-indigo-100 hover:text-indigo-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600 peer-disabled:pointer-events-none peer-disabled:cursor-not-allowed peer-disabled:opacity-50">
          <span aria-hidden="true" class="text-lg leading-none">＋</span>
          画像を追加
        </span>
      </label>
      <button type="button" class="nav-link text-sm" @click="reset">画像の変更を取り消す</button>
    </div>
  </fieldset>
</template>

