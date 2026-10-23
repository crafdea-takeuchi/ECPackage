<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ shipments: Object, filters: Object, counts: Object });
const search = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', scheduled_month: props.filters.scheduled_month ?? '' });
const statusLabels = { draft: '発送準備中', shipped: '発送済み', cancelled: '取消' };
function submit() { router.get('/admin/shipments', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', status: '', scheduled_month: '' }); router.get('/admin/shipments'); }
</script>

<template>
  <AdminLayout>
    <Head title="発送管理" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-xs font-bold tracking-widest text-emerald-700">発送</p><h1 class="mt-3 text-3xl font-bold">発送管理</h1><p class="mt-3 text-sm text-slate-500">発送予定、配送会社、追跡番号と発送状況を管理します。</p></div>
      <Link href="/admin/shipments/create" class="btn-primary">＋ 発送を登録</Link>
    </div>
    <div class="my-7 grid gap-3 sm:grid-cols-3"><div v-for="item in [{ label: 'すべて', value: counts.all }, { label: '発送準備中', value: counts.draft }, { label: '発送済み', value: counts.shipped }]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold">{{ item.value }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div></div>
    <form @submit.prevent="submit" class="mb-6 grid items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_12rem_auto]">
      <div><label for="shipment-q" class="mb-2 block text-sm font-medium">発送番号・注文番号・追跡番号・お客様</label><input id="shipment-q" v-model="search.q" class="field" maxlength="150" /></div>
      <div><label for="shipment-status" class="mb-2 block text-sm font-medium">状態</label><select id="shipment-status" v-model="search.status" class="field"><option value="">すべて</option><option value="draft">発送準備中</option><option value="shipped">発送済み</option><option value="cancelled">取消</option></select></div>
      <div><label for="scheduled-month" class="mb-2 block text-sm font-medium">発送予定月</label><input id="scheduled-month" v-model="search.scheduled_month" type="month" class="field" /></div>
      <div class="flex gap-3"><button class="btn-primary">検索</button><button type="button" class="nav-link px-2 py-3" @click="reset">解除</button></div>
    </form>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">発送</th><th class="px-5 py-4">注文・お客様</th><th class="px-5 py-4">配送情報</th><th class="px-5 py-4">予定日／発送日</th><th class="px-5 py-4">状態</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead><tbody>
      <tr v-for="shipment in shipments.data" :key="shipment.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/admin/shipments/${shipment.id}/edit`" class="font-semibold text-emerald-900">{{ shipment.shipment_number }}</Link><p class="mt-1 text-xs text-slate-500">{{ shipment.lines_count }}商品</p></td><td class="px-5 py-4"><Link :href="`/admin/orders/${shipment.order.id}/edit`" class="font-medium text-emerald-900">{{ shipment.order.order_number }}</Link><p class="mt-1 text-xs text-slate-500">{{ shipment.order.customer.name }}</p></td><td class="px-5 py-4"><p>{{ shipment.carrier || '未設定' }}</p><p class="mt-1 text-xs text-slate-500">{{ shipment.tracking_number || '追跡番号なし' }}</p></td><td class="px-5 py-4"><p>{{ shipment.scheduled_on || '—' }}</p><p class="mt-1 text-xs text-slate-500">発送：{{ shipment.shipped_on || '未発送' }}</p></td><td class="px-5 py-4"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs" :class="shipment.status === 'shipped' ? 'bg-emerald-50 text-emerald-800' : shipment.status === 'cancelled' ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-800'">{{ statusLabels[shipment.status] }}</span></td><td class="px-5 py-4"><Link :href="`/admin/shipments/${shipment.id}/edit`" class="nav-link whitespace-nowrap">詳細 →</Link></td></tr>
      <tr v-if="!shipments.data.length"><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">発送はまだ登録されていません。</p><p class="mt-2 text-sm text-slate-500">確定済みの注文から発送を登録できます。</p></td></tr>
    </tbody></table></div><nav class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ shipments.current_page }} / {{ shipments.last_page }} ページ</span><div class="flex gap-5"><Link v-if="shipments.prev_page_url" :href="shipments.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="shipments.next_page_url" :href="shipments.next_page_url" class="nav-link">次へ →</Link></div></nav></section>
  </AdminLayout>
</template>
