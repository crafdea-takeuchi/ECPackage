<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ orders: Object, filters: Object, customers: Array, counts: Object });
const search = reactive({ q: props.filters.q ?? '', customer_id: props.filters.customer_id ?? '', status: props.filters.status ?? '', ordered_month: props.filters.ordered_month ?? '' });
const statusLabels = { draft: '下書き', confirmed: '受付済み', preparing: '準備中', shipped: '発送済み', completed: '完了', cancelled: 'キャンセル' };
const statusClass = status => status === 'cancelled' ? 'bg-red-50 text-red-800' : status === 'completed' ? 'bg-slate-100 text-slate-700' : status === 'draft' ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800';
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
function submit() { router.get('/admin/orders', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', customer_id: '', status: '', ordered_month: '' }); router.get('/admin/orders'); }
</script>

<template>
  <AdminLayout>
    <Head title="注文管理" />
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">受注</p><h1 class="mt-3 text-3xl font-bold">注文管理</h1><p class="mt-3 text-sm text-slate-500">お客様の注文を登録し、確定時にロット在庫から自動出庫します。</p></div><Link href="/admin/orders/create" class="btn-primary">＋ 注文を登録</Link></div>
    <div class="my-7 grid grid-cols-3 gap-3"><div v-for="item in [{ label: 'すべて', value: counts.all }, { label: '下書き', value: counts.draft }, { label: '進行中', value: counts.active }, { label: '完了', value: counts.completed }, { label: 'キャンセル', value: counts.cancelled }]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold">{{ item.value }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div></div>
    <form @submit.prevent="submit" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-5">
      <div><label for="order-q" class="mb-2 block text-sm font-medium">注文番号・商品・お客様</label><input id="order-q" v-model="search.q" class="field" maxlength="150" /></div>
      <div><label for="order-customer" class="mb-2 block text-sm font-medium">お客様</label><select id="order-customer" v-model="search.customer_id" class="field"><option value="">すべて</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}｜{{ customer.email }}</option></select></div>
      <div><label for="order-status" class="mb-2 block text-sm font-medium">状態</label><select id="order-status" v-model="search.status" class="field"><option value="">すべて</option><option value="draft">下書き</option><option value="confirmed">受付済み</option><option value="preparing">準備中</option><option value="shipped">発送済み</option><option value="completed">完了</option><option value="cancelled">キャンセル</option></select></div>
      <div><label for="order-month" class="mb-2 block text-sm font-medium">注文月</label><input id="order-month" v-model="search.ordered_month" type="month" class="field" /></div>
      <div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">解除</button></div>
    </form>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">検索結果 <span class="ml-2 text-slate-500">{{ orders.total }} 件</span></div><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">注文番号</th><th class="px-5 py-4">注文日</th><th class="px-5 py-4">お客様</th><th class="px-5 py-4">明細</th><th class="px-5 py-4 text-right">注文金額</th><th class="px-5 py-4">状態</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead><tbody>
      <tr v-for="order in orders.data" :key="order.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/admin/orders/${order.id}/edit`" class="font-semibold text-emerald-900">{{ order.order_number }}</Link></td><td class="px-5 py-4 whitespace-nowrap">{{ order.ordered_on }}</td><td class="px-5 py-4"><p>{{ order.customer.name }}</p><p class="mt-1 text-xs text-slate-500">{{ order.customer.email }}</p></td><td class="px-5 py-4">{{ order.lines_count }} 件</td><td class="px-5 py-4 text-right font-semibold tabular-nums">{{ money(order.total) }}</td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs" :class="statusClass(order.status)">{{ statusLabels[order.status] }}</span></td><td class="px-5 py-4"><Link :href="`/admin/orders/${order.id}/edit`" class="nav-link whitespace-nowrap">{{ order.status === 'draft' ? '編集' : '詳細' }} →</Link></td></tr>
      <tr v-if="!orders.data.length"><td colspan="7" class="px-6 py-14 text-center"><p class="font-semibold">{{ counts.all ? '条件に一致する注文がありません。' : '注文はまだありません。' }}</p><p class="mt-2 text-sm text-slate-500">営業による代理注文をこちらから登録できます。</p></td></tr>
    </tbody></table></div><nav aria-label="注文一覧のページ切り替え" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ orders.current_page }} / {{ orders.last_page }} ページ</span><div class="flex gap-5"><Link v-if="orders.prev_page_url" :href="orders.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="orders.next_page_url" :href="orders.next_page_url" class="nav-link">次へ →</Link></div></nav></section>
  </AdminLayout>
</template>
