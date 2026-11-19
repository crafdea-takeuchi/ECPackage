<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
  groups: { type: Array, default: () => [] },
  storeUrl: String,
  maxAmount: String,
});

const inputValue = value => value == null ? '' : String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
const postalText = codes => (codes ?? []).map(code => code.slice(0, 3) + '-' + code.slice(3)).join('\n');
const parsePostalCodes = text => String(text ?? '').split(/[\s,、]+/u).map(code => code.trim()).filter(Boolean);
const values = group => ({
  name: group?.name ?? '',
  shipping_fee: inputValue(group?.shipping_fee),
  free_shipping_threshold: inputValue(group?.free_shipping_threshold),
  postal_codes_text: postalText(group?.postal_codes),
});
const payload = form => ({
  name: form.name,
  shipping_fee: form.shipping_fee,
  free_shipping_threshold: form.free_shipping_threshold,
  postal_codes: parsePostalCodes(form.postal_codes_text),
});
const postalError = form => Object.entries(form.errors).find(([key]) => key === 'postal_codes' || key.startsWith('postal_codes.'))?.[1];

const createForm = useForm(values());
const entries = props.groups.map(group => ({ group, form: useForm(values(group)) }));
const deletingId = ref(null);

function createGroup() {
  if (createForm.processing) return;
  createForm.transform(() => payload(createForm)).post(props.storeUrl, {
    preserveScroll: true,
    onSuccess: () => createForm.reset(),
  });
}

function updateGroup(entry) {
  if (entry.form.processing) return;
  entry.form.transform(() => payload(entry.form)).put(entry.group.update_url, { preserveScroll: true });
}

function deleteGroup(entry) {
  if (deletingId.value !== null || !window.confirm('「' + entry.group.name + '」を削除しますか？登録した郵便番号も削除されます。')) return;
  deletingId.value = entry.group.id;
  router.delete(entry.group.delete_url, {
    preserveScroll: true,
    onFinish: () => { deletingId.value = null; },
  });
}
</script>

<template>
  <section class="mt-7 max-w-6xl rounded-xl border border-slate-200 bg-white p-5 sm:p-8">
    <h2 class="text-lg font-semibold">郵便番号別送料グループ</h2>
    <p class="mt-2 text-sm leading-6 text-slate-600">グループごとに送料と対象郵便番号を登録します。注文時に郵便番号が一致した場合は、都道府県別の送料よりこちらを優先します。</p>
    <p class="mt-2 text-xs text-slate-500">郵便番号は1行に1件、7桁で入力してください。ハイフンあり・なしのどちらでも登録できます。同じ郵便番号を複数のグループへ登録することはできません。</p>

    <div v-if="entries.length" class="mt-6 space-y-5">
      <form v-for="entry in entries" :key="entry.group.id" class="rounded-xl border border-slate-200 p-5" @submit.prevent="updateGroup(entry)" novalidate>
        <div class="grid gap-5 lg:grid-cols-3">
          <div>
            <label :for="'postal-code-name-' + entry.group.id" class="mb-2 block text-sm font-medium">グループ名 <span class="text-red-600">*</span></label>
            <input :id="'postal-code-name-' + entry.group.id" v-model="entry.form.name" class="field" maxlength="100" required :disabled="entry.form.processing" />
            <p v-if="entry.form.errors.name" class="mt-2 text-sm text-red-700">{{ entry.form.errors.name }}</p>
          </div>
          <div>
            <label :for="'postal-code-fee-' + entry.group.id" class="mb-2 block text-sm font-medium">送料（税込） <span class="text-red-600">*</span></label>
            <div class="flex items-center gap-2"><input :id="'postal-code-fee-' + entry.group.id" v-model="entry.form.shipping_fee" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" class="field" required :disabled="entry.form.processing" /><span class="shrink-0">円</span></div>
            <p v-if="entry.form.errors.shipping_fee" class="mt-2 text-sm text-red-700">{{ entry.form.errors.shipping_fee }}</p>
          </div>
          <div>
            <label :for="'postal-code-threshold-' + entry.group.id" class="mb-2 block text-sm font-medium">送料無料になる購入金額（税込）</label>
            <div class="flex items-center gap-2"><input :id="'postal-code-threshold-' + entry.group.id" v-model="entry.form.free_shipping_threshold" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" class="field" :disabled="entry.form.processing" /><span class="shrink-0">円以上</span></div>
            <p v-if="entry.form.errors.free_shipping_threshold" class="mt-2 text-sm text-red-700">{{ entry.form.errors.free_shipping_threshold }}</p>
          </div>
          <div class="lg:col-span-3">
            <label :for="'postal-code-postal-' + entry.group.id" class="mb-2 block text-sm font-medium">対象郵便番号 <span class="text-red-600">*</span></label>
            <textarea :id="'postal-code-postal-' + entry.group.id" v-model="entry.form.postal_codes_text" class="field min-h-32 font-mono" placeholder="100-0001&#10;100-0002" required :disabled="entry.form.processing"></textarea>
            <p v-if="postalError(entry.form)" class="mt-2 text-sm text-red-700">{{ postalError(entry.form) }}</p>
          </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
          <p class="text-xs text-slate-500">{{ entry.group.postal_codes.length }}件の郵便番号を登録済み</p>
          <div class="flex gap-3">
            <button type="button" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" :disabled="deletingId !== null || entry.form.processing" @click="deleteGroup(entry)">{{ deletingId === entry.group.id ? '削除中…' : 'グループを削除' }}</button>
            <button type="submit" class="btn-primary" :disabled="entry.form.processing">{{ entry.form.processing ? '保存中…' : '変更を保存' }}</button>
          </div>
        </div>
      </form>
    </div>
    <p v-else class="mt-6 rounded-lg bg-slate-50 p-5 text-sm text-slate-600">郵便番号別送料グループはまだ登録されていません。</p>

    <form class="mt-7 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/40 p-5" @submit.prevent="createGroup" novalidate>
      <h3 class="font-semibold">郵便番号別送料グループを追加</h3>
      <div class="mt-5 grid gap-5 lg:grid-cols-3">
        <div><label for="new-postal-code-name" class="mb-2 block text-sm font-medium">グループ名 <span class="text-red-600">*</span></label><input id="new-postal-code-name" v-model="createForm.name" class="field" maxlength="100" placeholder="例：特別配送地域A" required :disabled="createForm.processing" /><p v-if="createForm.errors.name" class="mt-2 text-sm text-red-700">{{ createForm.errors.name }}</p></div>
        <div><label for="new-postal-code-fee" class="mb-2 block text-sm font-medium">送料（税込） <span class="text-red-600">*</span></label><div class="flex items-center gap-2"><input id="new-postal-code-fee" v-model="createForm.shipping_fee" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" class="field" required :disabled="createForm.processing" /><span class="shrink-0">円</span></div><p v-if="createForm.errors.shipping_fee" class="mt-2 text-sm text-red-700">{{ createForm.errors.shipping_fee }}</p></div>
        <div><label for="new-postal-code-threshold" class="mb-2 block text-sm font-medium">送料無料になる購入金額（税込）</label><div class="flex items-center gap-2"><input id="new-postal-code-threshold" v-model="createForm.free_shipping_threshold" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" class="field" :disabled="createForm.processing" /><span class="shrink-0">円以上</span></div><p v-if="createForm.errors.free_shipping_threshold" class="mt-2 text-sm text-red-700">{{ createForm.errors.free_shipping_threshold }}</p></div>
        <div class="lg:col-span-3"><label for="new-postal-code-postal" class="mb-2 block text-sm font-medium">対象郵便番号 <span class="text-red-600">*</span></label><textarea id="new-postal-code-postal" v-model="createForm.postal_codes_text" class="field min-h-32 font-mono" placeholder="100-0001&#10;100-0002" required :disabled="createForm.processing"></textarea><p v-if="postalError(createForm)" class="mt-2 text-sm text-red-700">{{ postalError(createForm) }}</p></div>
      </div>
      <button type="submit" class="btn-primary mt-5" :disabled="createForm.processing">{{ createForm.processing ? '追加中…' : 'グループを追加' }}</button>
    </form>
  </section>
</template>