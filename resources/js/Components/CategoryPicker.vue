<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
const props = defineProps({ categories: Array, modelValue: Array, retainedIds: { type: Array, default: () => [] } });
const emit = defineEmits(['update:modelValue']);
const query = ref('');
const visible = computed(() => props.categories.filter(category => category.path.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
function toggle(id, checked) {
  emit('update:modelValue', checked ? [...new Set([...props.modelValue, id])] : props.modelValue.filter(value => value !== id));
}
</script>
<template>
  <fieldset class="border-t border-slate-200 pt-6">
    <legend class="px-1 text-sm font-semibold">商品分類 <span class="ml-2 text-xs font-normal text-slate-500">任意・複数選択可</span></legend>
    <p class="mb-3 text-xs leading-6 text-slate-500">選ばなくても登録できます。選択中：{{ modelValue.length }} 件</p>
    <template v-if="categories.length">
      <label for="product-category-search" class="sr-only">商品分類候補を検索</label>
      <input id="product-category-search" v-model="query" class="field mb-3" placeholder="商品分類名で絞り込み" />
      <div class="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
        <label v-for="category in visible" :key="category.id" class="flex items-start gap-3 rounded-md p-3 text-sm hover:bg-slate-50" :class="!category.effective_active ? 'text-slate-400' : 'text-slate-700'">
          <input type="checkbox" class="mt-1 accent-emerald-800" :value="category.id" :checked="modelValue.includes(category.id)" :disabled="!category.effective_active && !retainedIds.includes(category.id)" @change="toggle(category.id, $event.target.checked)" />
          <span class="break-words">{{ category.path }}<span v-if="!category.effective_active" class="ml-2 text-xs">（無効{{ retainedIds.includes(category.id) ? '・関連を保持中' : '' }}）</span></span>
        </label>
        <p v-if="!visible.length" class="p-4 text-sm text-slate-500">一致する商品分類がありません。</p>
      </div>
    </template>
    <p v-else class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">商品分類はまだありません。販売商品を保存した後、<Link href="/admin/categories" class="text-emerald-800 underline">商品分類管理</Link>から追加できます。</p>
  </fieldset>
</template>
