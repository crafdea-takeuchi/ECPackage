<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({ items: Array, total: String });
const form = useForm({ notes: '' });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
function submit() { form.post('/checkout', { preserveScroll: true }); }
</script>

<template>
  <Head title="注文内容の確認" />
  <AppLayout>
    <Link href="/cart" class="nav-link text-sm">← カートへ戻る</Link><div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">CHECKOUT</p><h1 class="mt-3 text-3xl font-bold">注文内容の確認</h1><p class="mt-3 text-sm text-slate-500">確定時に最新の価格と在庫を再確認します。</p></div>
    <div v-if="form.hasErrors" role="alert" class="mb-6 rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">注文内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">商品</th><th class="px-5 py-4 text-right">単価</th><th class="px-5 py-4 text-right">数量</th><th class="px-5 py-4 text-right">金額</th></tr></thead><tbody><tr v-for="item in items" :key="item.product.id" class="border-t border-slate-100"><td class="px-5 py-5"><p class="font-semibold">{{ item.product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.product.sku }}</p><p v-if="item.product.shortage_policy === 'backorder' && Number(item.quantity) > Number(item.product.available_quantity)" class="mt-2 text-xs text-amber-700">不足分は取り寄せとして受け付けます。</p></td><td class="px-5 py-5 text-right tabular-nums">{{ money(item.product.default_sale_price) }}</td><td class="px-5 py-5 text-right tabular-nums">{{ money(item.quantity) }} {{ item.product.unit.name }}</td><td class="px-5 py-5 text-right font-semibold tabular-nums">{{ money(item.amount) }}</td></tr></tbody></table></div><div class="flex items-center justify-between border-t border-slate-200 p-6"><span class="text-sm text-slate-500">ご注文合計</span><strong class="text-2xl tabular-nums">{{ money(total) }} 円</strong></div></section>
    <form @submit.prevent="submit" class="mt-6 space-y-6"><section class="rounded-xl border border-slate-200 bg-white p-6"><label for="checkout-notes" class="mb-2 block text-sm font-medium">ご要望・備考</label><textarea id="checkout-notes" v-model="form.notes" class="field min-h-24" maxlength="2000"></textarea></section><div class="flex justify-end"><button type="submit" class="btn-primary min-w-52" :disabled="form.processing">{{ form.processing ? '注文中…' : '注文を確定する' }}</button></div></form>
  </AppLayout>
</template>
