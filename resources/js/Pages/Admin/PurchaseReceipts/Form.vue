<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ receipt: Object, suppliers: Array });
const mapLine = line => ({ id: line.id ?? null, product_id: line.product_id ?? '', product_supplier_id: line.product_supplier_id ?? '', lot_number: line.lot_number ?? '', manufactured_on: line.manufactured_on ?? '', expires_on: line.expires_on ?? '', quantity: line.quantity ?? '', unit_purchase_price: line.unit_purchase_price ?? '' });
const form = useForm({ supplier_id: props.receipt?.supplier_id ?? '', received_on: props.receipt?.received_on ?? new Date().toLocaleDateString('en-CA'), notes: props.receipt?.notes ?? '', lock_version: props.receipt?.lock_version ?? 0, action: 'draft', lines: props.receipt?.lines?.map(mapLine) ?? [] });
const posted = computed(() => props.receipt?.status === 'posted');
const selectedSupplier = computed(() => props.suppliers.find(supplier => String(supplier.id) === String(form.supplier_id)));
const availableSettings = computed(() => selectedSupplier.value?.purchase_settings ?? []);
const total = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_purchase_price) || 0), 0));
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
const settingFor = line => availableSettings.value.find(setting => String(setting.id) === String(line.product_supplier_id));
const unitFor = line => settingFor(line)?.product?.unit?.name ?? props.receipt?.lines?.find(item => item.id === line.id)?.product?.unit?.name ?? '';
const makeLine = () => ({ id: null, product_id: '', product_supplier_id: '', lot_number: '', manufactured_on: '', expires_on: '', quantity: '', unit_purchase_price: '' });

function changeSupplier() { form.lines = []; }
function addLine() { form.lines.push(makeLine()); }
function removeLine(index) { form.lines.splice(index, 1); }
function changeProduct(line) {
  const setting = settingFor(line);
  line.product_id = setting?.product_id ?? '';
  line.unit_purchase_price = setting?.default_purchase_price ?? '';
}
function reloadFromProps() {
  if (!props.receipt) return;
  Object.assign(form, { supplier_id: props.receipt.supplier_id, received_on: props.receipt.received_on, notes: props.receipt.notes ?? '', lock_version: props.receipt.lock_version, action: 'draft', lines: props.receipt.lines.map(mapLine) });
  form.defaults();
}
function save(action) {
  form.action = action;
  const options = { preserveScroll: true, onSuccess: reloadFromProps };
  if (props.receipt) form.put(`/admin/purchase-receipts/${props.receipt.id}`, options);
  else form.post('/admin/purchase-receipts', options);
}
</script>

<template>
  <AdminLayout>
    <Head :title="receipt ? '入庫を確認' : '入庫を登録'" />
    <Link href="/admin/purchase-receipts" class="nav-link text-sm">← 入庫一覧へ</Link>
    <div class="mt-5 mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">入庫の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ receipt ? (posted ? '入庫の詳細' : '入庫を編集') : '入庫を登録' }}</h1><p class="mt-3 text-sm text-slate-500">{{ posted ? '確定済みの入庫と作成されたロット在庫を確認できます。' : '下書きでは在庫は増えません。「入庫を確定」でロット在庫へ反映します。' }}</p></div><div v-if="receipt"><p class="text-xs text-slate-500">入庫番号</p><p class="mt-1 font-semibold">{{ receipt.receipt_number }}</p></div></div>
    <div v-if="posted" class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm text-emerald-900">この入庫は確定済みです。訂正機能は在庫調整として後の工程で追加します。<Link href="/admin/inventory-lots" class="ml-2 underline">ロット在庫を見る</Link></div>
    <form @submit.prevent="save('draft')" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-2">
        <div><label for="receipt-supplier" class="mb-2 block text-sm font-medium">仕入先 <span class="text-xs text-red-600">必須</span></label><select id="receipt-supplier" v-model="form.supplier_id" class="field" :disabled="posted" @change="changeSupplier"><option value="">選択してください</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.code }}｜{{ supplier.name }}{{ supplier.status === 'inactive' ? '（無効）' : '' }}</option></select></div>
        <div><label for="received-on" class="mb-2 block text-sm font-medium">入庫日 <span class="text-xs text-red-600">必須</span></label><input id="received-on" v-model="form.received_on" type="date" class="field" :disabled="posted" /></div>
        <div class="lg:col-span-2"><label for="receipt-notes" class="mb-2 block text-sm font-medium">備考</label><textarea id="receipt-notes" v-model="form.notes" class="field min-h-20" maxlength="2000" :disabled="posted"></textarea></div>
      </section>
      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-6"><div><h2 class="font-semibold">入庫商品</h2><p class="mt-2 text-xs text-slate-500">仕入先に設定された販売商品から選択します。最大100件。</p></div><button v-if="!posted" type="button" @click="addLine" :disabled="!form.supplier_id || !availableSettings.length || form.lines.length >= 100" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-40">＋ 商品を追加</button></div>
        <div class="space-y-4 p-6">
          <article v-for="(line, index) in form.lines" :key="line.id ?? `new-${index}`" class="rounded-xl border border-slate-200 p-5"><div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">明細 {{ index + 1 }}</h3><button v-if="!posted" type="button" @click="removeLine(index)" class="text-sm text-red-700">取り除く</button></div>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div class="lg:col-span-2"><label :for="`line-product-${index}`" class="mb-2 block text-sm font-medium">販売商品 <span class="text-xs text-red-600">必須</span></label><select :id="`line-product-${index}`" v-model="line.product_supplier_id" class="field" :disabled="posted || Boolean(line.id)" @change="changeProduct(line)"><option value="">選択してください</option><option v-for="setting in availableSettings" :key="setting.id" :value="setting.id">{{ setting.product.sku }}｜{{ setting.product.name }}{{ setting.status === 'inactive' ? '（仕入設定無効）' : '' }}</option></select></div>
              <div><label :for="`lot-number-${index}`" class="mb-2 block text-sm font-medium">ロット番号 <span class="text-xs text-red-600">必須</span></label><input :id="`lot-number-${index}`" v-model="line.lot_number" class="field" maxlength="100" :disabled="posted" /></div>
              <div><label :for="`manufactured-on-${index}`" class="mb-2 block text-sm font-medium">製造日</label><input :id="`manufactured-on-${index}`" v-model="line.manufactured_on" type="date" class="field" :disabled="posted" /></div>
              <div><label :for="`expires-on-${index}`" class="mb-2 block text-sm font-medium">期限日</label><input :id="`expires-on-${index}`" v-model="line.expires_on" type="date" class="field" :disabled="posted" /></div>
              <div><label :for="`quantity-${index}`" class="mb-2 block text-sm font-medium">入庫数量 <span class="text-xs text-red-600">必須</span></label><div class="flex items-center gap-2"><input :id="`quantity-${index}`" v-model="line.quantity" type="number" min="0.0001" step="0.0001" class="field" :disabled="posted" /><span class="min-w-8 text-sm text-slate-500">{{ unitFor(line) }}</span></div></div>
              <div><label :for="`unit-price-${index}`" class="mb-2 block text-sm font-medium">仕入単価 <span class="text-xs text-red-600">必須</span></label><input :id="`unit-price-${index}`" v-model="line.unit_purchase_price" type="number" min="0" step="0.0001" class="field" :disabled="posted" /></div>
              <div><p class="mb-2 text-sm font-medium">入庫金額</p><p class="rounded-lg bg-slate-50 px-4 py-3 text-right font-semibold tabular-nums">{{ money((Number(line.quantity) || 0) * (Number(line.unit_purchase_price) || 0)) }}</p></div>
            </div>
          </article>
          <div v-if="!form.lines.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">{{ form.supplier_id ? (availableSettings.length ? '「商品を追加」から入庫商品を追加してください。' : 'この仕入先には有効な仕入商品がありません。') : '先に仕入先を選択してください。' }}</div>
        </div>
        <div class="flex items-center justify-between border-t border-slate-200 p-6"><span class="text-sm text-slate-500">合計入庫金額</span><strong class="text-xl tabular-nums">{{ money(total) }}</strong></div>
      </section>
      <div v-if="!posted" class="flex flex-wrap justify-end gap-3"><button type="submit" class="rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50" :disabled="form.processing">{{ form.processing ? '保存中…' : '下書き保存' }}</button><button type="button" @click="save('post')" class="btn-primary min-w-48" :disabled="form.processing">{{ form.processing ? '処理中…' : '入庫を確定' }}</button></div>
    </form>
  </AdminLayout>
</template>
