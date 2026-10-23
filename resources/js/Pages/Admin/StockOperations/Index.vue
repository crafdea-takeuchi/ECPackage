<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ operations: Object, filters: Object, counts: Object });
const search = reactive({ q: props.filters.q ?? '', operation_type: props.filters.operation_type ?? '', occurred_month: props.filters.occurred_month ?? '' });
function submit() { router.get('/admin/stock-operations', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', operation_type: '', occurred_month: '' }); router.get('/admin/stock-operations'); }
</script>

<template>
  <AdminLayout>
    <Head title="出庫・在庫調整" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-xs font-bold tracking-widest text-emerald-700">在庫履歴</p><h1 class="mt-3 text-3xl font-bold">出庫・在庫調整</h1><p class="mt-3 text-sm text-slate-500">ロットからの出庫と、棚卸差異・破損などの在庫調整を履歴として残します。</p></div>
      <Link href="/admin/stock-operations/create" class="btn-primary">＋ 出庫・在庫調整を登録</Link>
    </div>
    <div class="my-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div v-for="item in [{ label: 'すべて', value: counts.all }, { label: '出庫', value: counts.outbound }, { label: '在庫調整', value: counts.adjustment }, { label: 'キャンセル戻し', value: counts.cancellation_return }, { label: '取り寄せ引当', value: counts.backorder_allocation }]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold">{{ item.value }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div></div>
    <form @submit.prevent="submit" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
      <div><label for="operation-q" class="mb-2 block text-sm font-medium">処理番号・商品・ロット</label><input id="operation-q" v-model="search.q" class="field" maxlength="150" /></div>
      <div><label for="operation-type" class="mb-2 block text-sm font-medium">処理区分</label><select id="operation-type" v-model="search.operation_type" class="field"><option value="">すべて</option><option value="outbound">出庫</option><option value="adjustment">在庫調整</option><option value="cancellation_return">キャンセル戻し</option><option value="backorder_allocation">取り寄せ引当</option></select></div>
      <div><label for="operation-month" class="mb-2 block text-sm font-medium">処理月</label><input id="operation-month" v-model="search.occurred_month" type="month" class="field" /></div>
      <div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">解除</button></div>
    </form>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">検索結果 <span class="ml-2 text-slate-500">{{ operations.total }} 件</span></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">処理番号</th><th class="px-5 py-4">処理日</th><th class="px-5 py-4">区分</th><th class="px-5 py-4">明細</th><th class="px-5 py-4">登録者</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead><tbody>
        <tr v-for="operation in operations.data" :key="operation.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="px-5 py-4 font-semibold text-emerald-900">{{ operation.operation_number }}</td><td class="px-5 py-4 whitespace-nowrap">{{ operation.occurred_on }}</td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs" :class="operation.operation_type === 'outbound' ? 'bg-blue-50 text-blue-800' : 'bg-amber-50 text-amber-800'">{{ operation.operation_type === 'outbound' ? '出庫' : (operation.operation_type === 'cancellation_return' ? 'キャンセル戻し' : (operation.operation_type === 'backorder_allocation' ? '取り寄せ引当' : '在庫調整')) }}</span></td><td class="px-5 py-4">{{ operation.lines_count }} 件</td><td class="px-5 py-4">{{ operation.creator.name }}</td><td class="px-5 py-4"><Link :href="`/admin/stock-operations/${operation.id}`" class="nav-link whitespace-nowrap">詳細 →</Link></td></tr>
        <tr v-if="!operations.data.length"><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">{{ counts.all ? '条件に一致する在庫履歴がありません。' : '出庫・在庫調整はまだありません。' }}</p></td></tr>
      </tbody></table></div>
      <nav aria-label="出庫・在庫調整一覧のページ切り替え" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ operations.current_page }} / {{ operations.last_page }} ページ</span><div class="flex gap-5"><Link v-if="operations.prev_page_url" :href="operations.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="operations.next_page_url" :href="operations.next_page_url" class="nav-link">次へ →</Link></div></nav>
    </section>
  </AdminLayout>
</template>
