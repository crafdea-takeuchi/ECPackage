<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ProductImage from '../../../Components/ProductImage.vue';
defineProps({ productGroup: Object, products: Object, indexUrl: String, editUrl: String, createProductUrl: String });
const number = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value));
const registration = value => ({ provisional: '仮登録', confirmed: '本登録', merged: '統合済み', inactive: '無効' }[value] ?? value);
</script>
<template>
  <AdminLayout>
    <Head :title="productGroup.name" />
    <Link :href="indexUrl" class="nav-link text-sm">← 基本商品一覧へ</Link>
    <section class="mt-5 rounded-xl border border-slate-200 bg-white p-6 sm:p-8">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div><ProductImage :image="productGroup.images?.[0]" :name="productGroup.name" class="mb-4 w-32" /><p class="text-sm text-slate-500">基本商品情報</p><h1 class="mt-2 text-3xl font-bold">{{ productGroup.name }}</h1></div>
        <Link :href="editUrl" class="btn-primary">基本商品を編集</Link>
      </div>
      <p class="mt-5 whitespace-pre-wrap text-sm text-slate-700">{{ productGroup.description || '説明はありません。' }}</p>
      <p class="mt-4"><span class="rounded-full px-3 py-1 text-xs" :class="productGroup.status === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ productGroup.status === 'active' ? '有効' : '無効' }}</span></p>
      <h2 class="mt-6 text-sm font-semibold">使用する属性</h2>
      <ul class="mt-2 flex flex-wrap gap-2"><li v-for="definition in productGroup.definitions" :key="definition.id" class="rounded bg-slate-100 px-3 py-2 text-sm">{{ definition.name }}{{ definition.is_required ? '（本登録時必須）' : '' }}{{ definition.status !== 'active' ? '（無効）' : '' }}</li></ul>
      <p v-if="!productGroup.definitions.length" class="mt-2 text-sm text-slate-500">属性はありません。</p>
    </section>
    <section class="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-5">
        <h2 class="text-lg font-semibold">販売商品・バリエーション <span class="text-sm font-normal">{{ products.total }}件</span></h2>
        <Link v-if="createProductUrl" :href="createProductUrl" class="btn-primary">販売商品を追加</Link>
        <p v-else class="text-sm text-amber-800">基本商品が無効のため追加できません。</p>
      </div>
      <div v-if="!products.total" class="p-8 text-center"><p>この基本商品には販売商品が登録されていません。</p><Link v-if="createProductUrl" :href="createProductUrl" class="btn-primary mt-5 inline-block">最初の販売商品を追加</Link></div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">販売商品</th><th class="p-4">属性の組み合わせ</th><th class="p-4">標準販売単価（税抜）・税率・単位</th><th class="p-4">在庫・不足時</th><th class="p-4">登録・公開状態</th><th class="p-4">操作</th></tr></thead>
          <tbody>
            <tr v-for="product in products.data" :key="product.id" class="border-t border-slate-100">
              <td class="min-w-48 p-4"><ProductImage :image="product.images?.[0]" :name="product.name" class="mb-3 w-16" /><p class="font-semibold">{{ product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ product.sku }}</p></td>
              <td class="min-w-48 p-4"><p v-for="value in product.attribute_values" :key="value.id">{{ value.definition.name }}：{{ value.value }}<span v-if="value.definition.status !== 'active'" class="text-xs text-slate-500">（無効）</span></p><span v-if="!product.attribute_values.length">—</span></td>
              <td class="min-w-48 p-4"><p>{{ product.default_sale_price == null ? '未設定' : number(product.default_sale_price) + '円' }}</p><p class="mt-2 text-xs text-slate-600">{{ product.tax_rate ? product.tax_rate.name + '（' + number(product.tax_rate.rate) + '%）' : '税率未設定' }}／{{ product.unit?.name }}</p></td>
              <td class="min-w-40 p-4"><p>{{ number(product.available_quantity) }} {{ product.unit?.name }}</p><span class="mt-2 inline-block rounded px-2 py-1 text-xs" :class="Number(product.available_quantity) > 0 ? 'bg-emerald-50 text-emerald-800' : product.shortage_policy === 'backorder' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'">{{ Number(product.available_quantity) > 0 ? '在庫あり' : product.shortage_policy === 'backorder' ? '取り寄せ可能' : '在庫切れ' }}</span><p class="mt-2 text-xs">{{ product.shortage_policy === 'backorder' ? '不足分は取り寄せ' : '在庫超過を拒否' }}</p><p v-if="product.kind === 'bundle'" class="mt-1 text-xs text-slate-500">構成商品の在庫から算出</p></td>
              <td class="min-w-32 p-4"><p>{{ registration(product.registration_status) }}</p><span class="mt-2 inline-block rounded px-2 py-1 text-xs" :class="product.publication_status === 'public' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ product.publication_status === 'public' ? '公開' : '非公開' }}<span class="mt-1 block text-xs">{{ product.sale_status }}</span></span></td>
              <td class="p-4"><Link v-if="product.edit_url" :href="product.edit_url" class="nav-link whitespace-nowrap">編集</Link><span v-else class="text-slate-500">編集不可</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <nav v-if="products.total" class="flex items-center justify-between border-t p-5 text-sm" aria-label="所属する販売商品のページ切り替え"><span>{{ products.current_page }} / {{ products.last_page }} ページ</span><div class="flex gap-5"><Link v-if="products.prev_page_url" :href="products.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="products.next_page_url" :href="products.next_page_url" class="nav-link">次へ →</Link></div></nav>
    </section>
  </AdminLayout>
</template>
