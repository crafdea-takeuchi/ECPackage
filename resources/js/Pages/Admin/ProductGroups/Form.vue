<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ productGroup: Object });
const makeAttribute = () => ({ id: null, name: '', code: '', input_type: 'text', options: [], options_text: '', is_required: false, sort_order: 0, status: 'active' });
const form = useForm({
  name: props.productGroup?.name ?? '', description: props.productGroup?.description ?? '', status: props.productGroup?.status ?? 'active', lock_version: props.productGroup?.lock_version ?? 0,
  attributes: props.productGroup?.definitions?.map(definition => ({ ...definition, options: definition.options ?? [], options_text: (definition.options ?? []).join('、') })) ?? [],
});
const activeCount = computed(() => form.attributes.filter(attribute => attribute.status === 'active').length);
function addAttribute() { form.attributes.push(makeAttribute()); }
function removeAttribute(index) {
  if (form.attributes[index].id) form.attributes[index].status = 'inactive';
  else form.attributes.splice(index, 1);
}
function restoreAttribute(index) { form.attributes[index].status = 'active'; }
function save() {
  form.transform(data => ({ ...data, attributes: data.attributes.map(({ options_text, ...attribute }) => ({ ...attribute, options: attribute.input_type === 'select' ? options_text.split(/[、,\n]/).map(value => value.trim()).filter(Boolean) : [] })) }));
  const options = { preserveScroll: true, onSuccess: () => { if (props.productGroup) { form.lock_version = props.productGroup.lock_version; form.attributes = props.productGroup.definitions.map(definition => ({ ...definition, options: definition.options ?? [], options_text: (definition.options ?? []).join('、') })); form.defaults(); } } };
  if (props.productGroup) form.put(`/admin/product-groups/${props.productGroup.id}`, options);
  else form.post('/admin/product-groups', options);
}
</script>
<template>
  <AdminLayout>
    <Head :title="productGroup ? '基本商品を編集' : '基本商品を追加'" />
    <Link href="/admin/product-groups" class="nav-link text-sm">← 基本商品一覧へ</Link>
    <div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">基本商品の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ productGroup ? '基本商品を編集' : '基本商品を追加' }}</h1><p class="mt-3 text-sm text-slate-500">基本商品の共通情報と、販売商品に入力する属性を設定します。</p></div>
    <form @submit.prevent="save" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6"><div><label for="group-name" class="mb-2 block text-sm font-medium">基本商品名 <span class="ml-2 text-xs text-red-600">必須</span></label><input id="group-name" v-model="form.name" class="field" maxlength="150" placeholder="例：果汁グミ" /></div><div><label for="description" class="mb-2 block text-sm font-medium">説明</label><textarea id="description" v-model="form.description" class="field min-h-28" maxlength="2000" placeholder="この基本商品にまとめる販売商品の説明"></textarea></div></div>
        <div><label for="group-status" class="mb-2 block text-sm font-medium">状態</label><select id="group-status" v-model="form.status" class="field"><option value="active">有効</option><option value="inactive">無効</option></select><p class="mt-3 text-xs leading-6 text-slate-500">無効にすると、新しい商品には設定できません。既存商品との関連と属性値は保持されます。</p></div>
      </section>
      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-6"><div><h2 class="font-semibold">属性項目</h2><p class="mt-2 text-xs text-slate-500">有効 {{ activeCount }} 件／最大20件。必須項目は商品の本登録時に確認します。</p></div><button type="button" @click="addAttribute" :disabled="form.attributes.length >= 20" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-40">＋ 属性を追加</button></div>
        <div class="space-y-4 p-6">
          <article v-for="(attribute, index) in form.attributes" :key="attribute.id ?? `new-${index}`" class="rounded-xl border p-5" :class="attribute.status === 'active' ? 'border-slate-200' : 'border-slate-100 bg-slate-50 opacity-70'">
            <div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">属性 {{ index + 1 }} <span v-if="attribute.status === 'inactive'" class="ml-2 text-xs font-normal text-slate-500">無効</span></h3><button v-if="attribute.status === 'active'" type="button" @click="removeAttribute(index)" class="text-sm text-red-700">{{ attribute.id ? '無効にする' : '取り除く' }}</button><button v-else type="button" @click="restoreAttribute(index)" class="text-sm text-emerald-800">有効に戻す</button></div>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4"><div><label :for="`attribute-name-${index}`" class="mb-2 block text-sm font-medium">属性名</label><input :id="`attribute-name-${index}`" v-model="attribute.name" class="field" maxlength="80" placeholder="例：味" /></div><div><label :for="`attribute-code-${index}`" class="mb-2 block text-sm font-medium">属性コード</label><input :id="`attribute-code-${index}`" v-model="attribute.code" class="field" maxlength="50" placeholder="例：flavor" /><p class="mt-1 text-xs text-slate-500">英小文字・数字・_</p></div><div><label :for="`attribute-type-${index}`" class="mb-2 block text-sm font-medium">入力形式</label><select :id="`attribute-type-${index}`" v-model="attribute.input_type" class="field"><option value="text">文字</option><option value="number">数値</option><option value="select">選択式</option></select></div><div><label :for="`attribute-order-${index}`" class="mb-2 block text-sm font-medium">表示順</label><input :id="`attribute-order-${index}`" v-model="attribute.sort_order" type="number" min="0" max="999999" class="field" /></div></div>
            <div v-if="attribute.input_type === 'select'" class="mt-4"><label :for="`attribute-options-${index}`" class="mb-2 block text-sm font-medium">選択肢</label><textarea :id="`attribute-options-${index}`" v-model="attribute.options_text" class="field min-h-20" placeholder="ブドウ、ミカン（読点・カンマ・改行で区切る）"></textarea></div>
            <label class="mt-4 inline-flex items-center gap-2 text-sm"><input v-model="attribute.is_required" type="checkbox" class="accent-emerald-800" />本登録時に必須</label>
          </article>
          <div v-if="!form.attributes.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">属性が不要な基本商品は、空のまま保存できます。</div>
        </div>
      </section>
      <div class="flex justify-end"><button type="submit" class="btn-primary min-w-56" :disabled="form.processing">{{ form.processing ? '保存中…' : productGroup ? '変更を保存' : '基本商品を作成' }}</button></div>
    </form>
  </AdminLayout>
</template>
