<script setup>
const props = defineProps({ line: { type: Object, required: true }, index: { type: Number, required: true }, disabled: Boolean });

function changeType(event) {
  props.line.expiration_type = event.target.value || null;
  if (!props.line.expiration_type) props.line.expires_on = '';
}
</script>

<template>
  <div>
    <label :for="`expiration-type-${index}`" class="mb-2 block text-sm font-medium">期限種別</label>
    <select :id="`expiration-type-${index}`" :value="line.expiration_type || (line.expires_on ? 'legacy' : '')" class="field" :disabled="disabled" @change="changeType">
      <option v-if="!line.expiration_type && line.expires_on" value="legacy" disabled>種別未設定（既存データ）</option>
      <option value="">期限なし</option>
      <option value="best_before">賞味期限</option>
      <option value="use_by">消費期限</option>
    </select>
  </div>
  <div>
    <label :for="`expires-on-${index}`" class="mb-2 block text-sm font-medium">期限日 <span v-if="line.expiration_type" class="text-xs text-red-600">必須</span></label>
    <input :id="`expires-on-${index}`" v-model="line.expires_on" type="date" class="field" :min="line.manufactured_on || undefined" :required="Boolean(line.expiration_type)" :disabled="disabled || (!line.expiration_type && !line.expires_on)" />
    <p v-if="!line.expiration_type && line.expires_on" class="mt-2 text-xs text-amber-800">既存の期限日を保持しています。変更する場合は期限種別を選択してください。</p>
  </div>
</template>
