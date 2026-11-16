<script setup>
import { router, Head, Link, usePage, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ExpiredInventoryDisposalDialog from '../../../Components/ExpiredInventoryDisposalDialog.vue';
import { useInventoryLotFilters } from './filters.js';

const props = defineProps({ lots: Object, filters: Object, products: Array, suppliers: Array, totalsByUnit: Array, counts: Object, expirationSettings: Object, disposal: Object });
const page = usePage();
const selectedIds = ref([]);
const dialogOpen = ref(false);
const confirmationLots = ref([]);
const disposalForm = useForm({ lots: [], occurred_on: '', reason: '', notes: '', confirm_unexpired: false });
const selectedLots = computed(() => props.lots.data.filter(lot => lot.can_dispose && selectedIds.value.includes(lot.id)));
watch(() => [props.lots.current_page, JSON.stringify(props.filters)], () => { selectedIds.value = []; dialogOpen.value = false; });
function openDisposal() {
  if (!selectedLots.value.length) return;
  confirmationLots.value = selectedLots.value;
  disposalForm.clearErrors();
  dialogOpen.value = true;
}
function dispose(values) {
  if (disposalForm.processing) return;
  disposalForm.lots = confirmationLots.value.map(lot => ({ inventory_lot_id: lot.id, product_id: lot.product_id }));
  Object.assign(disposalForm, values);
  disposalForm.post('/admin/inventory-lots/dispose' + window.location.search, {
    preserveScroll: true,
    onSuccess: () => { dialogOpen.value = false; selectedIds.value = []; },
  });
}
const { search, expiredOnly, submit, toggleExpired, reset } = useInventoryLotFilters(
  () => props.filters,
  () => props.expirationSettings.warning_days,
  (...args) => router.get(...args),
);
const statusClass = status => ({
  expired: 'bg-red-100 text-red-800',
  today: 'bg-orange-100 text-orange-900',
  near: 'bg-amber-100 text-amber-900',
  valid: 'bg-emerald-50 text-emerald-800',
  none: 'bg-slate-100 text-slate-600',
}[status]);
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
</script>

<template>
  <AdminLayout>
    <Head title="ロット在庫" />
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">在庫</p><h1 class="mt-3 text-3xl font-bold">ロット在庫</h1><p class="mt-3 text-sm text-slate-500">ロットごとの残数と、販売商品全体の在庫量を確認します。</p></div><Link href="/admin/purchase-receipts/create" class="btn-primary">＋ 入庫を登録</Link></div>
    <div class="my-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">ロット総数</p><p class="mt-2 text-2xl font-semibold">{{ counts.all }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div><div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">在庫あり</p><p class="mt-2 text-2xl font-semibold">{{ counts.available }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div><div v-for="total in totalsByUnit" :key="total.id" class="rounded-xl border border-emerald-100 bg-emerald-50 p-5"><p class="text-xs text-emerald-800">検索結果の総在庫（{{ total.name }}）</p><p class="mt-2 text-2xl font-semibold text-emerald-950">{{ number(total.total_quantity, total.quantity_scale) }}<span class="ml-2 text-xs font-normal">{{ total.name }}</span></p></div></div>
    <div v-if="Object.keys(page.props.errors).length" role="alert" class="mb-4 rounded-xl bg-red-50 p-5 text-sm text-red-700"><ul class="list-inside list-disc"><li v-for="(error, key) in page.props.errors" :key="key">{{ error }}</li></ul></div>
    <p class="mb-3 text-xs text-slate-500">期限判定の基準日：{{ expirationSettings.today }}（日本時間）。期限間近は指定日数以内です。</p>
    <form @submit.prevent="submit" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-6"><div><label for="lot-q" class="mb-2 block text-sm font-medium">商品・ロット番号</label><input id="lot-q" v-model="search.q" class="field" maxlength="150" /></div><div><label for="lot-product" class="mb-2 block text-sm font-medium">販売商品</label><select id="lot-product" v-model="search.product_id" class="field"><option value="">すべて</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.sku }}｜{{ product.name }}</option></select></div><div><label for="lot-supplier" class="mb-2 block text-sm font-medium">仕入先</label><select id="lot-supplier" v-model="search.supplier_id" class="field"><option value="">すべて</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></div><div><label for="lot-month" class="mb-2 block text-sm font-medium">入庫月</label><input id="lot-month" v-model="search.received_month" type="month" class="field" /></div><div><label for="lot-availability" class="mb-2 block text-sm font-medium">在庫状態</label><select id="lot-availability" v-model="search.availability" class="field"><option value="">すべて</option><option value="available">在庫あり</option><option value="depleted">在庫なし</option></select></div><div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">検索条件をすべて解除</button></div>
      <div><label for="lot-expiration-type" class="mb-2 block text-sm font-medium">期限種別</label><select id="lot-expiration-type" v-model="search.expiration_type" class="field"><option value="">すべて</option><option value="best_before">賞味期限</option><option value="use_by">消費期限</option><option value="none">期限なし</option></select></div>
      <div><label for="lot-expiration-status" class="mb-2 block text-sm font-medium">期限状態</label><select id="lot-expiration-status" v-model="search.expiration_status" class="field"><option value="">すべて</option><option value="expired">期限切れ</option><option value="today">本日期限</option><option value="near">期限間近</option><option value="valid">期限内</option><option value="none">期限なし</option></select></div>
      <div><label for="lot-expiration-from" class="mb-2 block text-sm font-medium">期限日From</label><input id="lot-expiration-from" v-model="search.expiration_from" type="date" class="field" /></div>
      <div><label for="lot-expiration-to" class="mb-2 block text-sm font-medium">期限日To</label><input id="lot-expiration-to" v-model="search.expiration_to" type="date" class="field" :min="search.expiration_from || undefined" /></div>
      <div><label for="lot-expiration-days" class="mb-2 block text-sm font-medium">期限までの日数</label><div class="flex items-center gap-2"><input id="lot-expiration-days" v-model="search.expiration_days" type="number" min="1" :max="expirationSettings.max_days" step="1" class="field" /><span class="shrink-0 text-sm">日以内</span></div><label class="mt-2 flex items-center gap-2 text-xs"><input v-model="search.expiring_only" type="checkbox" />本日から指定日数以内のみ</label></div>
      <div><label for="lot-sort" class="mb-2 block text-sm font-medium">並び順</label><select id="lot-sort" v-model="search.sort" class="field"><option value="received_desc">入庫日が新しい順</option><option value="expiration_asc">期限が近い順（在庫あり優先）</option></select></div>
    </form>
    <div v-if="disposal.allowed" class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <button type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold" @click="toggleExpired" :aria-pressed="expiredOnly">{{ expiredOnly ? '期限切れ表示を解除' : '期限切れのみ表示' }}</button>
      <div class="flex items-center gap-3"><span class="text-sm text-slate-600">このページで {{ selectedLots.length }} ロット選択</span><button type="button" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-40" :disabled="!selectedLots.length || disposalForm.processing" @click="openDisposal">選択した在庫を廃棄</button></div>
    </div>
    <ExpiredInventoryDisposalDialog v-if="dialogOpen" :lots="confirmationLots" :today="expirationSettings.today" :reasons="disposal.reasons" :notes="disposal.notes" :processing="disposalForm.processing" :errors="disposalForm.errors" @close="dialogOpen = false" @submit="dispose" />
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">検索結果 <span class="ml-2 text-slate-500">{{ lots.total }} 件</span></div><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th v-if="disposal.allowed" class="px-5 py-4">選択</th><th class="px-5 py-4">販売商品・ロット</th><th class="px-5 py-4">仕入先</th><th class="px-5 py-4">入庫日</th><th class="px-5 py-4">期限種別</th><th class="px-5 py-4">期限日・残り日数</th><th class="px-5 py-4">期限状態</th><th class="px-5 py-4 text-right">入庫数</th><th class="px-5 py-4 text-right">現在庫</th><th class="px-5 py-4 text-right">仕入単価</th><th class="px-5 py-4">入庫番号</th></tr></thead><tbody>
      <tr v-for="lot in lots.data" :key="lot.id" class="border-t border-slate-100 hover:bg-slate-50"><td v-if="disposal.allowed" class="px-5 py-4"><input v-model="selectedIds" type="checkbox" :value="lot.id" :disabled="!lot.can_dispose || disposalForm.processing" :aria-label="`ロット ${lot.lot_number} を廃棄対象に選択`" /></td><td class="px-5 py-4"><p class="font-semibold text-emerald-900">{{ lot.product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ lot.product.sku }} ／ {{ lot.lot_number }}</p></td><td class="px-5 py-4">{{ lot.supplier.name }}</td><td class="px-5 py-4 whitespace-nowrap">{{ lot.received_on }}</td><td class="px-5 py-4 whitespace-nowrap">{{ lot.expiration.type_label }}</td><td class="px-5 py-4 whitespace-nowrap"><p>{{ lot.expires_on || '—' }}</p><p class="mt-1 text-xs text-slate-500">{{ lot.expiration.remaining_label }}</p></td><td class="px-5 py-4 whitespace-nowrap"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="statusClass(lot.expiration.status)">{{ lot.expiration.status_label }}</span></td><td class="px-5 py-4 text-right tabular-nums">{{ number(lot.initial_quantity, lot.product.unit.quantity_scale) }} {{ lot.product.unit.name }}</td><td class="px-5 py-4 text-right font-semibold tabular-nums" :class="Number(lot.current_quantity) > 0 ? 'text-emerald-800' : 'text-slate-400'">{{ number(lot.current_quantity, lot.product.unit.quantity_scale) }} {{ lot.product.unit.name }}</td><td class="px-5 py-4 text-right tabular-nums">{{ number(lot.unit_purchase_price) }}</td><td class="px-5 py-4"><Link v-if="lot.receipt_line?.receipt" :href="`/admin/purchase-receipts/${lot.receipt_line.receipt.id}/edit`" class="nav-link">{{ lot.receipt_line.receipt.receipt_number }}</Link><span v-else>—</span></td></tr>
      <tr v-if="!lots.data.length"><td :colspan="disposal.allowed ? 11 : 10" class="px-6 py-14 text-center"><p class="font-semibold">{{ counts.all ? '条件に一致するロット在庫がありません。' : 'ロット在庫はまだありません。' }}</p><p class="mt-2 text-sm text-slate-500">確定した入庫がロット在庫として表示されます。</p></td></tr>
    </tbody></table></div><nav aria-label="ロット在庫一覧のページ切り替え" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ lots.current_page }} / {{ lots.last_page }} ページ</span><div class="flex gap-5"><Link v-if="lots.prev_page_url" :href="lots.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="lots.next_page_url" :href="lots.next_page_url" class="nav-link">次へ →</Link></div></nav></section>
  </AdminLayout>
</template>
