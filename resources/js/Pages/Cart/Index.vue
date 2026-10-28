<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ items: Array, total: String });
const page = usePage();
const quantities = reactive(Object.fromEntries(props.items.map(item => [item.product.id, Math.trunc(Number(item.quantity))])));
const busy = reactive({ productId: null });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
function update(item) { busy.productId = item.product.id; router.patch(`/cart/${item.product.id}`, { quantity: quantities[item.product.id] }, { preserveScroll: true, onFinish: () => { busy.productId = null; } }); }
function remove(item) { busy.productId = item.product.id; router.delete(`/cart/${item.product.id}`, { preserveScroll: true, onFinish: () => { busy.productId = null; } }); }
</script>

<template>
  <Head title="カート" />
  <AppLayout>
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">SHOPPING CART</p><h1 class="mt-3 text-3xl font-bold">カート</h1></div><Link href="/shop" class="nav-link">← 商品一覧へ戻る</Link></div>
    <div v-if="page.props.errors.quantity || page.props.errors.product_id" role="alert" class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ page.props.errors.quantity || page.props.errors.product_id }}</div>
    <section v-if="items.length" class="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">商品</th><th class="px-5 py-4 text-right">単価</th><th class="px-5 py-4">数量</th><th class="px-5 py-4 text-right">金額</th><th class="px-5 py-4"><span class="sr-only">操作</span></th></tr></thead><tbody><tr v-for="item in items" :key="item.product.id" class="border-t border-slate-100"><td class="px-5 py-5"><p class="font-semibold text-emerald-950">{{ item.product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.product.sku }} ／ 在庫 {{ money(item.product.available_quantity) }} {{ item.product.unit.name }}<span v-if="item.product.shortage_policy === 'backorder' && Number(item.quantity) > Number(item.product.available_quantity)" class="ml-2 text-amber-700">不足分は取り寄せ</span></p><p v-if="item.product.registration_status !== 'confirmed' || item.product.publication_status !== 'public' || item.product.default_sale_price == null" class="mt-2 text-xs text-red-600">現在購入できない商品です。カートから削除してください。</p></td><td class="px-5 py-5 text-right tabular-nums">{{ money(item.product.default_sale_price) }}</td><td class="px-5 py-5"><div class="flex items-center gap-2"><input v-model="quantities[item.product.id]" type="number" min="1" step="1" class="field w-28" /><span>{{ item.product.unit.name }}</span><button type="button" @click="update(item)" :disabled="busy.productId === item.product.id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">変更</button></div></td><td class="px-5 py-5 text-right font-semibold tabular-nums">{{ money(item.amount) }}</td><td class="px-5 py-5"><button type="button" @click="remove(item)" :disabled="busy.productId === item.product.id" class="text-sm text-red-700">削除</button></td></tr></tbody></table></div><div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 p-6"><span class="text-sm text-slate-500">合計</span><strong class="text-2xl tabular-nums">{{ money(total) }} 円</strong></div></section>
    <div v-if="items.length" class="mt-6 flex justify-end"><Link v-if="page.props.auth.user" href="/checkout" class="btn-primary min-w-48 text-center">購入手続きへ</Link><Link v-else href="/login" class="btn-primary min-w-48 text-center">ログインして購入</Link></div>
    <section v-else class="mt-7 rounded-xl border border-slate-200 bg-white p-12 text-center"><p class="text-lg font-semibold">カートに商品はありません。</p><Link href="/shop" class="btn-primary mt-6 inline-block">商品を見る</Link></section>
  </AppLayout>
</template>
