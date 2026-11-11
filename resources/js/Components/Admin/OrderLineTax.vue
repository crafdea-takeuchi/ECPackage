<script setup>
defineProps({ line: { type: Object, required: true } });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
</script>

<template>
  <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
    <span>{{ line.tax_rate_name ?? '非課税' }}（{{ Number(line.tax_rate_name == null ? 0 : line.tax_rate) }}%）</span>
    <span v-if="line.display_tax_amount != null" class="tabular-nums">税額 {{ money(line.display_tax_amount) }} 円</span>
    <span v-else>明細税額の記録なし</span>
  </div>
</template>
