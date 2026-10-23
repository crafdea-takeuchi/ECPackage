<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ categories: Array });
const query = ref('');
const visible = computed(() => props.categories.filter(category => category.path.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
const activeCount = computed(() => props.categories.filter(category => category.effective_active).length);
</script>
<template>
  <AdminLayout>
    <Head title="商品分類管理" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-xs font-bold tracking-widest text-emerald-700">商品分類</p><h1 class="mt-3 text-3xl font-bold">商品分類管理</h1><p class="mt-3 text-sm text-slate-500">商品の分類を、あとから育てていけます。</p></div>
      <Link href="/admin/categories/create" class="btn-primary">＋ 商品分類を追加</Link>
    </div>
    <div class="my-7 grid gap-4 sm:grid-cols-3">
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">商品分類総数</p><p class="mt-2 text-2xl font-semibold">{{ categories.length }}<span class="ml-2 text-xs text-slate-400">件</span></p></div>
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">新しく商品に設定できる分類</p><p class="mt-2 text-2xl font-semibold">{{ activeCount }}<span class="ml-2 text-xs text-slate-400">件</span></p></div>
      <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm leading-6 text-emerald-900">名前や親分類を変更しても、販売商品の関連はそのまま保持されます。</div>
    </div>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-5"><h2 class="font-semibold">商品分類一覧</h2><div class="w-full sm:w-80"><label for="category-search" class="sr-only">商品分類を検索</label><input id="category-search" v-model="query" class="field" placeholder="名前・階層で検索" /></div></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">商品分類</th><th class="px-5 py-4 whitespace-nowrap">状態</th><th class="px-5 py-4 whitespace-nowrap">表示順</th><th class="px-5 py-4 whitespace-nowrap">直接所属する販売商品</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead>
        <tbody>
          <tr v-for="category in visible" :key="category.id" class="border-t border-slate-100 hover:bg-slate-50">
            <td class="px-5 py-4"><div :style="{ paddingLeft: `${Math.min(category.depth, 5) * 16}px` }"><Link :href="`/admin/categories/${category.id}/edit`" class="font-semibold text-emerald-900"><span v-if="category.depth" aria-hidden="true" class="mr-2 text-slate-300">└</span>{{ category.name }}</Link><p class="mt-1 max-w-lg break-words text-xs text-slate-500">{{ category.path }}</p></div></td>
            <td class="px-5 py-4"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs" :class="category.effective_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'">{{ category.effective_active ? '有効' : category.status === 'inactive' ? '無効' : '親が無効' }}</span></td>
            <td class="px-5 py-4 tabular-nums">{{ category.sort_order }}</td>
            <td class="px-5 py-4 tabular-nums">{{ category.products_count }} 件</td>
            <td class="px-5 py-4"><Link :href="`/admin/categories/${category.id}/edit`" class="nav-link whitespace-nowrap" :aria-label="`${category.path}を編集`">編集 →</Link></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="5" class="px-6 py-14 text-center"><p class="font-semibold">{{ categories.length ? '一致する商品分類がありません。' : '商品分類はまだありません。' }}</p><p class="mt-2 text-sm text-slate-500">{{ categories.length ? '検索する言葉を変えてみてください。' : '「野菜」など、大きな分類から追加できます。' }}</p></td></tr>
        </tbody>
      </table></div>
      <p class="border-t border-slate-200 p-5 text-xs leading-6 text-slate-500">同じ親の中で表示順の小さいものから並びます。同じ表示順では登録順になります。無効化しても、販売商品や下位分類は削除されません。</p>
    </section>
  </AdminLayout>
</template>
