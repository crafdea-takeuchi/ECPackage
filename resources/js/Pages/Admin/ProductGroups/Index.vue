<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ProductImage from '../../../Components/ProductImage.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ groups: Array });
const money = value => value == null ? '未設定' : new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(value);
const query = ref('');
const visible = computed(() => props.groups.filter(group => group.name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
</script>
<template>
  <AdminLayout>
    <Head title="基本商品管理" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div><p class="text-xs font-bold tracking-widest text-emerald-700">基本商品</p><h1 class="mt-3 text-3xl font-bold">基本商品管理</h1><p class="mt-3 text-sm text-slate-500">味・品種・産地・容量など、同じ商品の違いを整理します。</p></div>
      <Link href="/admin/product-groups/create" class="btn-primary">＋ 基本商品を追加</Link>
    </div>
    <div class="my-7 rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm leading-7 text-emerald-900">基本商品は「果汁グミ」「ジャガイモ」など、販売商品をまとめる共通単位です。在庫・価格・商品コードは、基本商品に属する販売商品ごとに管理します。</div>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-5"><h2 class="font-semibold">基本商品一覧 <span class="ml-2 text-sm font-normal text-slate-500">{{ groups.length }} 件</span></h2><div class="w-full sm:w-80"><label for="group-search" class="sr-only">基本商品を検索</label><input id="group-search" v-model="query" class="field" placeholder="基本商品名で検索" /></div></div>
      <div class="overflow-x-auto"><table class="min-w-[72rem] w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-500"><tr><th class="w-56 max-w-56 px-5 py-4">基本商品名</th><th class="px-5 py-4">状態</th><th class="px-5 py-4">属性</th><th class="px-5 py-4">販売商品</th><th class="px-5 py-4">公開／非公開</th><th class="px-5 py-4">価格帯（税抜）</th><th class="px-5 py-4">在庫状態</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead>
        <tbody>
          <tr v-for="group in visible" :key="group.id" class="border-t border-slate-100 hover:bg-slate-50"><td class="w-56 max-w-56 px-5 py-4"><ProductImage :image="group.images?.[0]" :name="group.name" class="mb-3 w-16" /><Link :href="group.show_url" class="block break-words font-semibold text-emerald-900">{{ group.name }}</Link><p v-if="group.description" class="mt-1 max-w-full truncate text-xs text-slate-500">{{ group.description }}</p></td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs" :class="group.status === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'">{{ group.status === 'active' ? '有効' : '無効' }}</span></td><td class="px-5 py-4 tabular-nums">{{ group.definitions.filter(definition => definition.status === 'active').map(definition => definition.name).join('・') || '—' }}</td><td class="px-5 py-4 tabular-nums">{{ group.products_count }} 件</td><td class="px-5 py-4">{{ group.public_products_count }}件／{{ group.private_products_count }}件</td><td class="px-5 py-4 whitespace-nowrap">{{ money(group.products_min_default_sale_price) }}<template v-if="group.products_max_default_sale_price != null && Number(group.products_min_default_sale_price) !== Number(group.products_max_default_sale_price)">～{{ money(group.products_max_default_sale_price) }}</template><span v-if="group.products_min_default_sale_price != null">円</span></td><td class="px-5 py-4 whitespace-nowrap">{{ group.stocked_products_count ? '在庫あり ' + group.stocked_products_count + '件' : '在庫なし' }}</td><td class="px-5 py-4"><Link :href="group.show_url" class="nav-link whitespace-nowrap" :aria-label="`${group.name}の詳細`">詳細 →</Link><Link :href="group.edit_url" class="nav-link ml-4 whitespace-nowrap">編集</Link></td></tr>
          <tr v-if="!visible.length"><td colspan="8" class="px-6 py-14 text-center"><p class="font-semibold">{{ groups.length ? '一致する基本商品がありません。' : '基本商品はまだありません。' }}</p><p class="mt-2 text-sm text-slate-500">「果汁グミ」や「ジャガイモ」などから追加できます。</p></td></tr>
        </tbody>
      </table></div>
    </section>
  </AdminLayout>
</template>
