<script setup>
import { computed, ref, watch } from 'vue';
import ProductImage from './ProductImage.vue';

const props = defineProps({
  images: {
    type: Array,
    default: () => [],
  },
  name: {
    type: String,
    default: '商品',
  },
});

const selectedId = ref(null);
const selectedImage = computed(() => props.images.find(image => image.id === selectedId.value) ?? props.images[0] ?? null);

watch(
  () => props.images,
  images => {
    if (!images.some(image => image.id === selectedId.value)) {
      selectedId.value = images[0]?.id ?? null;
    }
  },
  { immediate: true },
);
</script>

<template>
  <section aria-label="商品画像">
    <ProductImage :image="selectedImage" :name="name" full class="border border-slate-200 bg-white" />

    <div v-if="images.length > 1" class="mt-3 grid grid-cols-5 gap-2">
      <button
        v-for="image in images"
        :key="image.id"
        type="button"
        class="rounded-lg border bg-white p-1 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
        :class="selectedImage?.id === image.id ? 'border-emerald-700 ring-1 ring-emerald-700' : 'border-slate-200 hover:border-slate-400'"
        :aria-label="(image.alt_text || name) + 'を表示'"
        :aria-pressed="selectedImage?.id === image.id"
        @click="selectedId = image.id"
      >
        <ProductImage :image="image" :name="name" />
      </button>
    </div>
  </section>
</template>
