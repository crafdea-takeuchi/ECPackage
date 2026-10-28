<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({ image: Object, name: { type: String, default: '商品' }, full: Boolean });
const failed = ref(false);
const src = computed(() => props.full ? props.image?.url : props.image?.thumbnail_url);
watch(src, () => { failed.value = false; });
</script>

<template>
  <div class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-50">
    <img v-if="src && !failed" :src="src" :alt="image.alt_text || name" class="h-full w-full object-contain" :loading="full ? 'eager' : 'lazy'" decoding="async" @error="failed = true" />
    <span v-else class="px-2 text-center text-xs text-slate-400" role="img" :aria-label="name + '：画像なし'">画像なし</span>
  </div>
</template>

