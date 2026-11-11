<script setup>
defineProps({ order: { type: Object, required: true }, taxes: { type: Array, default: () => [] } });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
</script>

<template>
  <dl class="space-y-3 border-t border-slate-200 p-6 text-sm" aria-label="注文金額の内訳">
    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">商品小計（税抜）</dt><dd class="tabular-nums">{{ money(order.subtotal) }} 円</dd></div>
    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">送料</dt><dd class="tabular-nums">0 円</dd></div>
    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">税金</dt><dd class="tabular-nums">{{ money(order.tax_amount) }} 円</dd></div>
    <div v-for="tax in taxes" :key="`${tax.name}-${tax.rate}`" class="flex items-center justify-between gap-4 pl-4 text-xs text-slate-500"><dt>{{ tax.name }}（{{ Number(tax.rate) }}%）</dt><dd class="tabular-nums">{{ money(tax.tax_amount) }} 円</dd></div>
    <div class="flex items-center justify-between gap-4 border-t border-slate-100 pt-3"><dt class="font-semibold">合計（税込）</dt><dd class="text-xl font-semibold tabular-nums">{{ money(order.total) }} 円</dd></div>
  </dl>
</template>
