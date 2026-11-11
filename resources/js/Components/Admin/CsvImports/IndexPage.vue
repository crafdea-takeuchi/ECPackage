<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

defineProps({ imports: Object, context: Object });

const statusClass = {
  uploaded: 'bg-blue-50 text-blue-800',
  validating: 'bg-blue-50 text-blue-800',
  ready: 'bg-emerald-50 text-emerald-800',
  invalid: 'bg-amber-50 text-amber-800',
  importing: 'bg-blue-50 text-blue-800',
  completed: 'bg-emerald-50 text-emerald-800',
  failed: 'bg-red-50 text-red-800',
};
</script>

<template>
  <AdminLayout>
    <Head :title="context.title" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <Link :href="context.parent_url" class="nav-link">← {{ context.parent_label }}へ</Link>
        <p class="mt-6 text-xs font-bold tracking-widest text-emerald-700">{{ context.parent_label }}</p>
        <h1 class="mt-3 text-3xl font-bold">{{ context.title }}</h1>
        <p class="mt-3 text-sm text-slate-500">{{ context.description }}</p>
      </div>
      <Link :href="context.base_url + '/create'" class="btn-primary">＋ CSVをアップロード</Link>
    </div>

    <section class="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="border-b border-slate-200 px-5 py-4 text-sm font-semibold">取込履歴 <span class="ml-2 text-slate-500">{{ imports.total }} 件</span></div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">受付日時</th><th class="px-5 py-4">ファイル</th><th class="px-5 py-4">状態</th><th class="px-5 py-4">行数</th><th class="px-5 py-4">登録者</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead>
          <tbody>
            <tr v-for="item in imports.data" :key="item.id" class="border-t border-slate-100 hover:bg-slate-50">
              <td class="whitespace-nowrap px-5 py-4">{{ item.created_at }}</td>
              <td class="max-w-xs truncate px-5 py-4" :title="item.original_filename">{{ item.original_filename }}</td>
              <td class="px-5 py-4"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass[item.status]">{{ item.status_label }}</span></td>
              <td class="whitespace-nowrap px-5 py-4">{{ item.total_rows }} 行<span v-if="item.error_rows" class="ml-2 text-amber-700">（エラー {{ item.error_rows }}）</span></td>
              <td class="whitespace-nowrap px-5 py-4">{{ item.creator?.name }}</td>
              <td class="px-5 py-4"><Link :href="context.base_url + '/' + item.id" class="nav-link whitespace-nowrap">詳細 →</Link></td>
            </tr>
            <tr v-if="!imports.data.length"><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">CSV取込履歴はまだありません。</p><p class="mt-2 text-sm text-slate-500">CSVをアップロードすると、ここに検証結果が表示されます。</p></td></tr>
          </tbody>
        </table>
      </div>
      <nav v-if="imports.last_page > 1" :aria-label="context.title + '履歴のページ切り替え'" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm">
        <span>{{ imports.current_page }} / {{ imports.last_page }} ページ</span>
        <div class="flex gap-5"><Link v-if="imports.prev_page_url" :href="imports.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="imports.next_page_url" :href="imports.next_page_url" class="nav-link">次へ →</Link></div>
      </nav>
    </section>
  </AdminLayout>
</template>