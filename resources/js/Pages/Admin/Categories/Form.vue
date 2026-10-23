<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ category: Object, parents: Array });
const form = useForm({
  name: props.category?.name ?? '',
  parent_id: props.category?.parent_id ?? '',
  sort_order: props.category?.sort_order ?? 0,
  status: props.category?.status ?? 'active',
  lock_version: props.category?.lock_version ?? 0,
});
const selectedParent = computed(() => props.parents.find(parent => parent.id === Number(form.parent_id)));
const preview = computed(() => [selectedParent.value?.path, form.name.trim() || '商品分類名'].filter(Boolean).join(' → '));
function save() {
  const options = { preserveScroll: true };
  if (props.category) form.put(`/admin/categories/${props.category.id}`, options);
  else form.post('/admin/categories', options);
}
</script>
<template>
  <AdminLayout>
    <Head :title="category ? '商品分類を編集' : '商品分類を追加'" />
    <Link href="/admin/categories" class="nav-link text-sm">← 商品分類一覧へ</Link>
    <div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">商品分類の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ category ? '商品分類を編集' : '商品分類を追加' }}</h1><p class="mt-3 text-sm text-slate-500">親分類を選ぶと、その下に分類されます。</p></div>
    <form @submit.prevent="save" class="grid items-start gap-6 lg:grid-cols-[2fr_1fr]" novalidate>
      <section class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8">
        <div v-if="form.hasErrors" role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
        <div><label for="category-name" class="mb-2 block text-sm font-medium">商品分類名 <span class="ml-2 text-xs text-red-600">必須</span></label><input id="category-name" v-model="form.name" class="field" maxlength="100" placeholder="例：ジャガイモ" :aria-invalid="!!form.errors.name" /></div>
        <div><label for="parent" class="mb-2 block text-sm font-medium">親分類</label><select id="parent" v-model="form.parent_id" class="field"><option value="">なし（最上位）</option><option v-for="parent in parents" :key="parent.id" :value="parent.id">{{ parent.path }}{{ parent.effective_active ? '' : '（無効な階層）' }}</option></select><p class="mt-2 text-xs leading-6 text-slate-500">変更すると、この商品分類と配下の分類がまとめて移動します。自分自身や配下の分類は選べません。</p></div>
        <div class="grid gap-6 sm:grid-cols-2">
          <div><label for="sort-order" class="mb-2 block text-sm font-medium">表示順</label><input id="sort-order" v-model="form.sort_order" type="number" min="0" max="999999" step="1" class="field" /><p class="mt-2 text-xs text-slate-500">小さい数字から表示します。</p></div>
          <div><label for="status" class="mb-2 block text-sm font-medium">状態</label><select id="status" v-model="form.status" class="field"><option value="active">有効</option><option value="inactive">無効</option></select></div>
        </div>
      </section>
      <aside class="space-y-5">
        <section class="rounded-xl border border-emerald-100 bg-emerald-50 p-6"><h2 class="text-sm font-semibold text-emerald-900">保存後の分類</h2><p class="mt-4 break-words text-lg font-semibold leading-8 text-emerald-900">{{ preview }}</p><p v-if="form.status === 'inactive' || (selectedParent && !selectedParent.effective_active)" class="mt-4 text-sm leading-6 text-amber-800">この階層は新しく商品に設定できなくなります。すでに設定されている商品との関連は残ります。</p></section>
        <p class="px-1 text-sm leading-7 text-slate-500">無効化は配下の分類にも影響します。再び有効にすると、個別に無効化していない配下の分類も利用できるようになります。</p>
        <button type="submit" class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? '保存中…' : category ? '変更を保存' : '商品分類を作成' }}</button>
      </aside>
    </form>
  </AdminLayout>
</template>
