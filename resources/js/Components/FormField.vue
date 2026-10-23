<script setup>
defineProps({ id: String, label: String, type: { type: String, default: 'text' }, modelValue: String, error: String, autocomplete: String, hint: String });
defineEmits(['update:modelValue']);
</script>
<template>
  <div>
    <label :for="id" class="mb-2 block text-sm font-medium">{{ label }}</label>
    <input :id="id" :name="id" :type="type" :value="modelValue" :autocomplete="autocomplete" required :aria-invalid="!!error" :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined" class="field" @input="$emit('update:modelValue', $event.target.value)">
    <p v-if="hint" :id="`${id}-hint`" class="mt-2 text-xs text-slate-500">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" role="alert" class="mt-2 text-sm text-red-700">{{ error }}</p>
  </div>
</template>
