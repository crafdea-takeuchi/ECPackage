<script setup>
defineProps({ variants: { type: Array, default: () => [] }, modelValue: [String, Number], id: { type: String, default: 'product-variant' }, disabled: Boolean });
const emit = defineEmits(['update:modelValue', 'change']);
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
function label(variant) {
  const attributes = variant.attribute_values?.map(attribute => attribute.value).filter(Boolean).join(' / ');
  const availability = Number(variant.available_quantity) > 0 ? '在庫あり' : (variant.shortage_policy === 'backorder' ? '取り寄せ' : '在庫なし');
  return `${attributes || variant.name} — ${money(variant.price_including_tax)}円（税込）— ${variant.purchase_unavailable_reason ? variant.sale_status : availability}`;
}
function select(event) {
  emit('update:modelValue', event.target.value);
  emit('change', event.target.value);
}
</script>

<template>
  <select :id="id" :value="modelValue" :disabled="disabled" class="field" @change="select">
    <option value="" disabled>バリエーションを選択してください</option>
    <option v-for="variant in variants" :key="variant.id" :value="String(variant.id)">{{ label(variant) }}</option>
  </select>
</template>
