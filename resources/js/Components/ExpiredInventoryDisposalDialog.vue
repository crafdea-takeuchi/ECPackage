<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ lots: { type: Array, required: true }, today: String, reasons: { type: Array, default: () => [] }, notes: String, processing: Boolean, errors: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close', 'submit']);
const dialog = ref(null);
const occurredOn = ref(props.today);
const notes = ref(props.notes);
const reason = ref('');
const confirmUnexpired = ref(false);
const hasUnexpired = computed(() => props.lots.some(lot => lot.expiration.status !== 'expired'));
const reasonMismatch = computed(() => (
  (reason.value === '期限切れ廃棄' && hasUnexpired.value)
  || (reason.value === '期限間近による廃棄' && props.lots.some(lot => lot.expiration.status !== 'near'))
));
const canSubmit = computed(() => !props.processing && props.lots.length > 0 && props.reasons.includes(reason.value)
  && (!hasUnexpired.value || confirmUnexpired.value)
  && (reason.value !== 'その他' || Boolean(notes.value?.trim())));
const statusClass = status => ({
  expired: 'bg-red-100 text-red-800',
  today: 'bg-orange-100 text-orange-900',
  near: 'bg-amber-100 text-amber-900',
  valid: 'bg-blue-50 text-blue-800',
  none: 'bg-slate-100 text-slate-600',
}[status]);
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
const totals = computed(() => {
  const result = new Map();
  for (const lot of props.lots) {
    const unit = lot.product.unit;
    const entry = result.get(unit.id) ?? { ...unit, quantity: 0 };
    entry.quantity += Number(lot.current_quantity);
    result.set(unit.id, entry);
  }
  return [...result.values()];
});

function close() { if (!props.processing) emit('close'); }
function submit() {
  if (canSubmit.value) emit('submit', { occurred_on: occurredOn.value, reason: reason.value, notes: notes.value, confirm_unexpired: confirmUnexpired.value });
}
onMounted(() => dialog.value.showModal());
onBeforeUnmount(() => dialog.value?.close());
</script>

<template>
  <dialog ref="dialog" aria-labelledby="disposal-title" class="m-auto max-h-[90vh] w-[min(72rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/50" @cancel.prevent="close">
    <form @submit.prevent="submit" class="space-y-5">
      <h2 id="disposal-title" class="text-xl font-bold">選択した在庫を廃棄（{{ lots.length }}ロット）</h2>
      <p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">選択した在庫を廃棄し、在庫数を減少します。この操作は通常の編集では元に戻せません。内容を確認してください。</p>
      <div v-if="hasUnexpired" class="space-y-3 rounded-lg border-2 border-red-600 bg-red-50 p-4 text-sm text-red-900">
        <p role="alert" class="font-bold">期限前の在庫が選択されています。廃棄すると現在庫が全数減少します。対象と期限日を確認してください。</p>
        <label class="flex items-start gap-2 font-semibold"><input v-model="confirmUnexpired" type="checkbox" class="mt-1" :disabled="processing" />期限前の在庫が含まれていることを確認しました</label>
      </div>
      <div v-if="Object.keys(errors).length" role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800"><ul class="list-inside list-disc"><li v-for="(error, key) in errors" :key="key">{{ error }}</li></ul></div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">商品名・商品コード</th><th class="p-3">ロット番号</th><th class="p-3">期限種別・期限日</th><th class="p-3">残り日数・期限状態</th><th class="p-3 text-right">現在庫数</th><th class="p-3 text-right">廃棄数量</th></tr></thead>
          <tbody><tr v-for="lot in lots" :key="lot.id" class="border-t border-slate-100"><td class="p-3"><p>{{ lot.product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ lot.product.sku }}</p></td><td class="p-3">{{ lot.lot_number }}</td><td class="p-3 whitespace-nowrap"><p>{{ lot.expiration.type_label }}</p><p>{{ lot.expires_on }}</p></td><td class="p-3 whitespace-nowrap"><p class="mb-2 text-xs text-slate-600">{{ lot.expiration.remaining_label }}</p><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="statusClass(lot.expiration.status)">{{ lot.expiration.status_label }}</span></td><td class="p-3 text-right whitespace-nowrap">{{ number(lot.current_quantity, lot.product.unit.quantity_scale) }} {{ lot.product.unit.name }}</td><td class="p-3 text-right whitespace-nowrap">{{ number(lot.current_quantity, lot.product.unit.quantity_scale) }} {{ lot.product.unit.name }}</td></tr></tbody>
        </table>
      </div>
      <div class="text-sm font-semibold">合計廃棄数量：<span v-for="(total, index) in totals" :key="total.id">{{ index ? '・' : '' }}{{ number(total.quantity, total.quantity_scale) }} {{ total.name }}</span></div>
      <p class="text-xs text-slate-600">数量は確認時点の表示です。実行時に最新在庫を再取得し、全数を廃棄します。対象が廃棄条件を満たさなくなった場合は、すべて中止します。</p>
      <div class="grid gap-4 sm:grid-cols-2">
        <div><label for="disposal-date" class="mb-2 block text-sm font-medium">処理日 <span class="text-xs text-red-600">必須</span></label><input id="disposal-date" v-model="occurredOn" type="date" required class="field" :disabled="processing" /></div>
        <div><label for="disposal-reason" class="mb-2 block text-sm font-medium">廃棄理由 <span class="text-xs text-red-600">必須</span></label><select id="disposal-reason" v-model="reason" required class="field" :disabled="processing"><option value="">選択してください</option><option v-for="item in reasons" :key="item" :value="item">{{ item }}</option></select><p v-if="reasonMismatch" class="mt-2 text-sm font-semibold text-amber-900">選択した廃棄理由と異なる期限状態の在庫が含まれています。業務判断に合った理由か確認してください。</p></div>
        <div class="sm:col-span-2"><label for="disposal-notes" class="mb-2 block text-sm font-medium">備考 <span v-if="reason === 'その他'" class="text-xs text-red-600">必須</span></label><textarea id="disposal-notes" v-model="notes" :required="reason === 'その他'" maxlength="2000" class="field min-h-20" :disabled="processing"></textarea></div>
      </div>
      <div class="flex justify-end gap-3"><button type="button" autofocus class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-semibold disabled:opacity-50" :disabled="processing" @click="close">キャンセル</button><button type="submit" class="rounded-lg bg-red-700 px-5 py-3 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50" :disabled="!canSubmit">{{ processing ? '廃棄処理中…' : '廃棄を実行' }}</button></div>
    </form>
  </dialog>
</template>
