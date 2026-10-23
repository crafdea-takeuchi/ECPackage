<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ lots: Array });
const form = useForm({ operation_type: 'outbound', occurred_on: new Date().toLocaleDateString('en-CA'), notes: '', lines: [] });
const makeLine = () => ({ inventory_lot_id: '', quantity: '', reason: '' });
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
const lotFor = line => props.lots.find(lot => String(lot.id) === String(line.inventory_lot_id));
const usedByOtherLine = (lotId, index) => form.lines.some((line, lineIndex) => lineIndex !== index && String(line.inventory_lot_id) === String(lotId));
const projected = line => {
  const lot = lotFor(line);
  if (!lot || line.quantity === '') return null;
  return Number(lot.current_quantity) + (form.operation_type === 'outbound' ? -Number(line.quantity) : Number(line.quantity));
};
const totalsByUnit = computed(() => {
  const totals = new Map();
  form.lines.forEach(line => {
    const lot = lotFor(line);
    if (!lot || line.quantity === '') return;
    const unit = lot.product.unit;
    const delta = (form.operation_type === 'outbound' ? -1 : 1) * (Number(line.quantity) || 0);
    const current = totals.get(unit.id) ?? { id: unit.id, name: unit.name, scale: unit.quantity_scale, quantity: 0 };
    current.quantity += delta;
    totals.set(unit.id, current);
  });
  return [...totals.values()];
});
function changeType() { form.lines.forEach(line => { line.quantity = ''; }); form.clearErrors(); }
function submit() { form.post('/admin/stock-operations', { preserveScroll: true }); }
</script>

<template>
  <AdminLayout>
    <Head title="出庫・在庫調整を登録" />
    <Link href="/admin/stock-operations" class="nav-link text-sm">← 履歴一覧へ</Link>
    <div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">在庫を更新</p><h1 class="mt-3 text-3xl font-bold">出庫・在庫調整を登録</h1><p class="mt-3 text-sm text-slate-500">登録すると即時に現在庫へ反映されます。登録後の内容は変更できません。</p></div>
    <form @submit.prevent="submit" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-2">
        <fieldset><legend class="mb-3 text-sm font-medium">処理区分 <span class="text-xs text-red-600">必須</span></legend><div class="flex gap-3"><label v-for="choice in [{ value: 'outbound', label: '出庫' }, { value: 'adjustment', label: '在庫調整' }]" :key="choice.value" class="flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-3" :class="form.operation_type === choice.value ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200'"><input v-model="form.operation_type" type="radio" :value="choice.value" @change="changeType" />{{ choice.label }}</label></div></fieldset>
        <div><label for="occurred-on" class="mb-2 block text-sm font-medium">処理日 <span class="text-xs text-red-600">必須</span></label><input id="occurred-on" v-model="form.occurred_on" type="date" class="field" /></div>
        <div class="lg:col-span-2 rounded-lg p-4 text-sm" :class="form.operation_type === 'outbound' ? 'bg-blue-50 text-blue-900' : 'bg-amber-50 text-amber-900'"><template v-if="form.operation_type === 'outbound'">出庫する数量を正数で入力します。現在庫から自動的に差し引きます。</template><template v-else>棚卸増加は正数、破損・紛失などの減少はマイナスで入力します。</template></div>
        <div class="lg:col-span-2"><label for="operation-notes" class="mb-2 block text-sm font-medium">全体の備考</label><textarea id="operation-notes" v-model="form.notes" class="field min-h-20" maxlength="2000"></textarea></div>
      </section>
      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-6"><div><h2 class="font-semibold">対象ロット</h2><p class="mt-2 text-xs text-slate-500">同じロットは1回の処理に1行だけ指定できます。</p></div><button type="button" @click="form.lines.push(makeLine())" :disabled="form.lines.length >= 100" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-40">＋ ロットを追加</button></div>
        <div class="space-y-4 p-6">
          <article v-for="(line, index) in form.lines" :key="index" class="rounded-xl border border-slate-200 p-5"><div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">明細 {{ index + 1 }}</h3><button type="button" @click="form.lines.splice(index, 1)" class="text-sm text-red-700">取り除く</button></div>
            <div class="grid gap-4 lg:grid-cols-12">
              <div class="lg:col-span-5"><label :for="`operation-lot-${index}`" class="mb-2 block text-sm font-medium">販売商品・ロット <span class="text-xs text-red-600">必須</span></label><select :id="`operation-lot-${index}`" v-model="line.inventory_lot_id" class="field"><option value="">選択してください</option><option v-for="lot in lots" :key="lot.id" :value="lot.id" :disabled="usedByOtherLine(lot.id, index) || (form.operation_type === 'outbound' && Number(lot.current_quantity) <= 0)">{{ lot.product.sku }}｜{{ lot.product.name }}｜{{ lot.lot_number }}｜在庫 {{ number(lot.current_quantity, lot.product.unit.quantity_scale) }} {{ lot.product.unit.name }}</option></select></div>
              <div class="lg:col-span-2"><label :for="`operation-quantity-${index}`" class="mb-2 block text-sm font-medium">{{ form.operation_type === 'outbound' ? '出庫数量' : '増減数量' }} <span class="text-xs text-red-600">必須</span></label><input :id="`operation-quantity-${index}`" v-model="line.quantity" type="number" :min="form.operation_type === 'outbound' ? '0.0001' : undefined" step="0.0001" class="field" /></div>
              <div class="lg:col-span-3"><label :for="`operation-reason-${index}`" class="mb-2 block text-sm font-medium">理由・用途</label><input :id="`operation-reason-${index}`" v-model="line.reason" class="field" maxlength="500" placeholder="受注出荷、棚卸差異、破損など" /></div>
              <div class="lg:col-span-2"><p class="mb-2 text-sm font-medium">処理後の見込</p><p class="rounded-lg bg-slate-50 px-4 py-3 text-right font-semibold tabular-nums" :class="projected(line) < 0 ? 'text-red-700' : ''">{{ projected(line) == null ? '—' : number(projected(line), lotFor(line)?.product?.unit?.quantity_scale) }} {{ lotFor(line)?.product?.unit?.name ?? '' }}</p></div>
            </div>
          </article>
          <div v-if="!form.lines.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">「ロットを追加」から対象在庫を選択してください。</div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-6"><span class="text-sm text-slate-500">入力した増減数量（単位別）</span><div class="flex flex-wrap gap-3"><strong v-for="total in totalsByUnit" :key="total.id" class="rounded-lg bg-slate-50 px-4 py-2 tabular-nums" :class="total.quantity < 0 ? 'text-red-700' : 'text-emerald-700'">{{ total.quantity > 0 ? '+' : '' }}{{ number(total.quantity, total.scale) }} {{ total.name }}</strong><span v-if="!totalsByUnit.length" class="text-sm text-slate-400">—</span></div></div>
      </section>
      <div class="flex justify-end"><button type="submit" class="btn-primary min-w-48" :disabled="form.processing">{{ form.processing ? '登録中…' : '在庫へ反映する' }}</button></div>
    </form>
  </AdminLayout>
</template>
