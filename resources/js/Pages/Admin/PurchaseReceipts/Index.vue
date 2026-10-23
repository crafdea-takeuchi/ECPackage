<script setup>
import { router, Head, Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ receipts: Object, filters: Object, suppliers: Array, counts: Object });
const search = reactive({ q: props.filters.q ?? '', supplier_id: props.filters.supplier_id ?? '', status: props.filters.status ?? '', received_month: props.filters.received_month ?? '' });
const money = value => value == null ? '—' : new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value));
function submit() { router.get('/admin/purchase-receipts', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', supplier_id: '', status: '', received_month: '' }); router.get('/admin/purchase-receipts'); }
</script>

<template>
  <AdminLayout>
    <Head title="入庫管理" />
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">入庫</p><h1 class="mt-3 text-3xl font-bold">入庫管理</h1><p class="mt-3 text-sm text-slate-500">仕入先から届いた商品を、ロットと仕入単価を含めて記録します。</p></div><Link href="/admin/purchase-receipts/create" class="btn-primary">＋ 入庫を登録</Link></div>
    <div class="my-7 grid grid-cols-3 gap-3"><div v-for="item in [{ label: 'すべて', value: counts.all }, { label: '下書き', value: counts.draft }, { label: '確定済み', value: counts.posted }]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold">{{ item.value }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div></div>
    <form @submit.prevent="submit" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-5">
      <div><label for="receipt-q" class="mb-2 block text-sm font-medium">入庫番号・ロット番号</label><input id="receipt-q" v-model="search.q" class="field" maxlength="150" placeholder="キーワードで検索" /></div>
      <div><label for="receipt-supplier" class="mb-2 block text-sm font-medium">仕入先</label><select id="receipt-supplier" v-model="search.supplier_id" class="field"><option value="">すべて</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></div>
      <div><label for="receipt-state" class="mb-2 block text-sm font-medium">状態</label><select id="receipt-state" v-model="search.status" class="field"><option value="">すべて</option><option value="draft">下書き</option><option value="posted">確定済み</option></select></div>
      <div><label for="receipt-month" class="mb-2 block text-sm font-medium">入庫月</label><input id="receipt-month" v-model="search.received_month" type="month" class="field" /></div>
      <div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">解除</button></div>
    </form>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">検索結果 <span class="ml-2 text-slate-500">{{ receipts.total }} 件</span></div><div class="overflow-x-auto"><table class="w-full text-left text-sm">
      <thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">入庫番号</th><th class="px-5 py-4">入庫日</th><th class="px-5 py-4">仕入先</th><th class="px-5 py-4">明細</th><th class="px-5 py-4 text-right">入庫金額</th><th class="px-5 py-4">状態</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead>
      <tbody><tr v-for="receipt in receipts.data" :key="receipt.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/admin/purchase-receipts/${receipt.id}/edit`" class="font-semibold text-emerald-900">{{ receipt.receipt_number }}</Link></td><td class="px-5 py-4 whitespace-nowrap">{{ receipt.received_on }}</td><td class="px-5 py-4">{{ receipt.supplier.name }}</td><td class="px-5 py-4 tabular-nums">{{ receipt.lines_count }} 件</td><td class="px-5 py-4 text-right tabular-nums">{{ money(receipt.lines_sum_purchase_amount) }}</td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs" :class="receipt.status === 'posted' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">{{ receipt.status === 'posted' ? '確定済み' : '下書き' }}</span></td><td class="px-5 py-4"><Link :href="`/admin/purchase-receipts/${receipt.id}/edit`" class="nav-link whitespace-nowrap">{{ receipt.status === 'posted' ? '詳細' : '編集' }} →</Link></td></tr><tr v-if="!receipts.data.length"><td colspan="7" class="px-6 py-14 text-center"><p class="font-semibold">{{ counts.all ? '条件に一致する入庫がありません。' : '入庫はまだ登録されていません。' }}</p><p class="mt-2 text-sm text-slate-500">{{ counts.all ? '検索条件を変更してください。' : '商品が届いたら最初の入庫を登録しましょう。' }}</p></td></tr></tbody>
    </table></div><nav aria-label="入庫一覧のページ切り替え" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ receipts.current_page }} / {{ receipts.last_page }} ページ</span><div class="flex gap-5"><Link v-if="receipts.prev_page_url" :href="receipts.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="receipts.next_page_url" :href="receipts.next_page_url" class="nav-link">次へ →</Link></div></nav></section>
  </AdminLayout>
</template>
