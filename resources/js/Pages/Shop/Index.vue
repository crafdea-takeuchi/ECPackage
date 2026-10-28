<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import ProductImage from '../../Components/ProductImage.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ products: Object, filters: Object, categories: Array });
const page = usePage();
const search = reactive({ q: props.filters.q ?? '', category_id: props.filters.category_id ?? '', availability: props.filters.availability ?? '' });
const quantities = reactive(Object.fromEntries(props.products.data.map(product => [product.id, '1'])));
const pending = reactive({ productId: null });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
function submit() { router.get('/shop', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', category_id: '', availability: '' }); router.get('/shop'); }
function add(product) {
  pending.productId = product.id;
  router.post('/cart', { product_id: product.id, quantity: quantities[product.id] }, { preserveScroll: true, onFinish: () => { pending.productId = null; } });
}
</script>

<template>
  <Head title="商品一覧" />
  <AppLayout>
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">ONLINE SHOP</p><h1 class="mt-3 text-3xl font-bold">商品一覧</h1><p class="mt-3 text-sm text-slate-500">現在販売中の商品をご覧いただけます。</p></div><Link href="/cart" class="rounded-lg border border-emerald-700 bg-white px-5 py-3 text-sm font-semibold text-emerald-800">カートを見る（{{ page.props.cart.count }}）</Link></div>
    <div v-if="page.props.errors.quantity || page.props.errors.product_id" role="alert" class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ page.props.errors.quantity || page.props.errors.product_id }}</div>
    <form @submit.prevent="submit" class="my-7 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4"><div><label for="shop-q" class="mb-2 block text-sm font-medium">商品名・商品コード</label><input id="shop-q" v-model="search.q" class="field" maxlength="150" /></div><div><label for="shop-category" class="mb-2 block text-sm font-medium">商品分類</label><select id="shop-category" v-model="search.category_id" class="field"><option value="">すべて</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></div><div><label for="shop-stock" class="mb-2 block text-sm font-medium">在庫状態</label><select id="shop-stock" v-model="search.availability" class="field"><option value="">すべて</option><option value="available">在庫あり</option><option value="sold_out">在庫なし</option></select></div><div class="flex items-end gap-3"><button type="submit" class="btn-primary">検索</button><button type="button" @click="reset" class="nav-link px-2 py-3">解除</button></div></form>
    <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><article v-for="product in products.data" :key="product.id" class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6"><ProductImage :image="product.images?.[0]" :name="product.name" class="mb-4" /><div class="flex flex-wrap gap-2"><span v-if="product.kind === 'bundle'" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800">セット商品</span><span v-for="category in product.categories" :key="category.id" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{{ category.name }}</span></div><p class="mt-5 text-xs text-slate-400">{{ product.sku }}</p><h2 class="mt-2 text-lg font-semibold text-emerald-950">{{ product.name }}</h2><div v-if="product.kind === 'bundle'" class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><p class="font-semibold text-slate-700">セット内容</p><ul class="mt-1 space-y-1"><li v-for="component in product.bundle_components" :key="component.id">{{ component.component_product.name }} × {{ money(component.quantity) }} {{ component.component_product.unit.name }}</li></ul></div><p class="mt-5 text-2xl font-bold tabular-nums">{{ money(product.default_sale_price) }}<span class="ml-1 text-xs font-normal text-slate-500">円 / {{ product.unit.name }}</span></p><p class="mt-3 text-sm" :class="Number(product.available_quantity) > 0 ? 'text-emerald-700' : 'text-red-600'">{{ Number(product.available_quantity) > 0 ? `在庫 ${money(product.available_quantity)} ${product.unit.name}` : (product.shortage_policy === 'backorder' ? '取り寄せで注文できます' : '在庫なし') }}</p><div class="mt-auto flex items-end gap-3 pt-6"><div class="w-28"><label :for="`shop-quantity-${product.id}`" class="mb-2 block text-xs text-slate-500">数量</label><input :id="`shop-quantity-${product.id}`" v-model="quantities[product.id]" type="number" min="0.0001" step="0.0001" class="field" :disabled="Number(product.available_quantity) <= 0 && product.shortage_policy !== 'backorder'" /></div><button type="button" @click="add(product)" class="btn-primary grow" :disabled="(Number(product.available_quantity) <= 0 && product.shortage_policy !== 'backorder') || pending.productId === product.id">{{ pending.productId === product.id ? '追加中…' : 'カートに追加' }}</button></div></article></section>
    <div v-if="!products.data.length" class="rounded-xl border border-slate-200 bg-white p-12 text-center"><p class="font-semibold">条件に一致する商品がありません。</p></div>
    <nav aria-label="商品一覧のページ切り替え" class="mt-7 flex items-center justify-between text-sm"><span>{{ products.current_page }} / {{ products.last_page }} ページ</span><div class="flex gap-5"><Link v-if="products.prev_page_url" :href="products.prev_page_url" class="nav-link">← 前へ</Link><Link v-if="products.next_page_url" :href="products.next_page_url" class="nav-link">次へ →</Link></div></nav>
  </AppLayout>
</template>
