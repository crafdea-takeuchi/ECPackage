<script setup>
import { router, Head, Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ suppliers: Object, filters: Object, counts: Object });
const search = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });

function submit() {
  router.get('/admin/suppliers', search, { preserveState: true, replace: true });
}

function reset() {
  search.q = '';
  search.status = '';
  router.get('/admin/suppliers');
}
</script>

<template>
  <AdminLayout>
    <Head title="仕入先管理" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-xs font-bold tracking-widest text-emerald-700">仕入先</p><h1 class="mt-3 text-3xl font-bold">仕入先管理</h1><p class="mt-3 text-sm text-slate-500">仕入先の連絡先と、販売商品ごとの仕入条件を管理します。</p></div>
      <Link href="/admin/suppliers/create" class="btn-primary">＋ 仕入先を追加</Link>
    </div>

    <div class="my-7 grid gap-3 sm:grid-cols-2"><div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">仕入先総数</p><p class="mt-2 text-2xl font-semibold">{{ counts.all }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div><div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">有効な仕入先</p><p class="mt-2 text-2xl font-semibold">{{ counts.active }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p></div></div>

    <form @submit.prevent="submit" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[2fr_1fr_auto]">
      <div><label for="supplier-search" class="mb-2 block text-sm font-medium">仕入先名・コード・担当者</label><input id="supplier-search" v-model="search.q" class="field" maxlength="150" placeholder="キーワードで検索" /></div>
      <div><label for="supplier-status" class="mb-2 block text-sm font-medium">状態</label><select id="supplier-status" v-model="search.status" class="field"><option value="">すべて</option><option value="active">有効</option><option value="inactive">無効</option></select></div>
      <div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">解除</button></div>
    </form>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">検索結果 <span class="ml-2 text-slate-500">{{ suppliers.total }} 件</span></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">仕入先</th><th class="px-5 py-4">連絡先</th><th class="px-5 py-4 whitespace-nowrap">仕入商品</th><th class="px-5 py-4 whitespace-nowrap">通常仕入</th><th class="px-5 py-4">状態</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead>
        <tbody>
          <tr v-for="supplier in suppliers.data" :key="supplier.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/admin/suppliers/${supplier.id}/edit`" class="font-semibold text-emerald-900">{{ supplier.name }}</Link><p class="mt-1 text-xs text-slate-400">{{ supplier.code }}</p></td><td class="px-5 py-4"><p>{{ supplier.contact_name || '—' }}</p><p class="mt-1 text-xs text-slate-500">{{ supplier.email || supplier.phone || '' }}</p></td><td class="px-5 py-4 tabular-nums">{{ supplier.active_purchase_settings_count }} 件</td><td class="px-5 py-4 tabular-nums">{{ supplier.primary_purchase_settings_count }} 件</td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs" :class="supplier.status === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'">{{ supplier.status === 'active' ? '有効' : '無効' }}</span></td><td class="px-5 py-4"><Link :href="`/admin/suppliers/${supplier.id}/edit`" class="nav-link whitespace-nowrap" :aria-label="`${supplier.name}を編集`">編集 →</Link></td></tr>
          <tr v-if="!suppliers.data.length"><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">{{ counts.all ? '条件に一致する仕入先がありません。' : '仕入先はまだ登録されていません。' }}</p><p class="mt-2 text-sm text-slate-500">{{ counts.all ? '検索条件を変更してください。' : '取引を始める仕入先を追加しましょう。' }}</p></td></tr>
        </tbody>
      </table></div>
      <nav aria-label="仕入先一覧のページ切り替え" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm"><span>{{ suppliers.current_page }} / {{ suppliers.last_page }} ページ</span><div class="flex gap-5"><Link v-if="suppliers.prev_page_url" :href="suppliers.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="suppliers.next_page_url" :href="suppliers.next_page_url" class="nav-link">次へ →</Link></div></nav>
    </section>
  </AdminLayout>
</template>
