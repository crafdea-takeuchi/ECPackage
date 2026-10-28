<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ProductImageEditor from '../../../Components/ProductImageEditor.vue';
import CategoryPicker from '../../../Components/CategoryPicker.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ product: Object, units: Array, categories: Array, groups: Array, componentProducts: Array, taxRates: Array });
const confirmed = computed(() => props.product?.registration_status === 'confirmed');
const initialValues = Object.fromEntries((props.product?.attribute_values ?? []).map(value => [value.product_attribute_definition_id, value.value]));
const imageRows = () => (props.product?.images ?? []).map(image => ({ ...image, file: null }));
const form = useForm({ images: imageRows(), images_present: true, kind: props.product?.kind ?? 'single', name: props.product?.name ?? '', sku: props.product?.sku ?? '', product_group_id: props.product?.product_group_id ?? '', unit_id: props.product?.unit_id ?? props.units.find(unit => unit.code === 'piece')?.id ?? props.units[0]?.id ?? '', default_sale_price: props.product?.default_sale_price ?? '', tax_rate_id: props.product?.tax_rate_id ?? props.taxRates.find(taxRate => taxRate.code === 'reduced')?.id ?? '', registration_status: props.product?.registration_status ?? 'provisional', publication_status: props.product?.publication_status ?? 'private', shortage_policy: props.product?.shortage_policy ?? 'reject', lock_version: props.product?.lock_version ?? 0, category_ids: props.product?.categories?.map(category => category.id) ?? [], attribute_values: initialValues, bundle_components: props.product?.bundle_components?.map(component => ({ component_product_id: component.component_product_id, quantity: component.quantity })) ?? [] });
const selectedGroup = computed(() => props.groups.find(group => group.id === Number(form.product_group_id)));
const activeDefinitions = computed(() => selectedGroup.value?.definitions ?? []);
const archivedValues = computed(() => (props.product?.attribute_values ?? []).filter(value => value.definition?.status === 'inactive'));
function changeGroup() { form.attribute_values = {}; form.clearErrors('product_group_id', 'attribute_values'); }
const componentFor = row => props.componentProducts.find(product => Number(product.id) === Number(row.component_product_id));
const componentUsedElsewhere = (productId, index) => form.bundle_components.some((row, rowIndex) => rowIndex !== index && Number(row.component_product_id) === Number(productId));
function addComponent() { form.bundle_components.push({ component_product_id: '', quantity: '1' }); }
function changeKind() {
  if (form.kind === 'bundle') {
    form.unit_id = props.units.find(unit => unit.code === 'set')?.id ?? form.unit_id;
    form.product_group_id = ''; form.attribute_values = {};
    if (!form.bundle_components.length) addComponent();
  } else {
    form.unit_id = props.units.find(unit => unit.code === 'piece')?.id ?? form.unit_id;
    form.bundle_components = [];
  }
}
function save(status) {
  form.registration_status = status;
  if (status === 'provisional') form.publication_status = 'private';
  form.transform(data => ({
    ...data,
    ...(props.product ? { _method: 'put' } : {}),
    images: data.images.map(row => ({ id: row.id ?? null, file: row.file, alt_text: row.alt_text || '' })),
  }));
  const options = { preserveScroll: true, onSuccess: () => {
    form.lock_version = props.product.lock_version;
    form.sku = props.product.sku;
    form.images = imageRows();
    form.defaults();
  } };
  form.post(props.product ? '/admin/products/' + props.product.id : '/admin/products', options);
}
</script>
<template>
  <AdminLayout>
    <Head :title="product ? '販売商品を編集' : '販売商品を登録'" />
    <Link href="/admin/products" class="nav-link text-sm">← 販売商品一覧へ</Link>
    <div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">販売商品の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ product ? '販売商品を編集' : '販売商品を登録' }}</h1><p class="mt-3 text-sm text-slate-500">基本商品を選ぶと、味・品種・産地などの属性入力欄が表示されます。</p></div>
    <form @submit.prevent="save(confirmed ? 'confirmed' : 'provisional')" class="grid items-start gap-6 lg:grid-cols-[2fr_1fr]" novalidate>
      <section class="rounded-xl border border-slate-200 bg-white p-6 sm:p-8">
        <h2 class="mb-6 font-semibold">基本情報</h2>
        <div v-if="form.hasErrors" role="alert" class="mb-6 rounded-lg bg-red-50 p-4 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
        <div class="space-y-6">
          <div><label for="kind" class="mb-2 block text-sm font-medium">商品の種類 <span class="ml-2 text-xs text-red-600">必須</span></label><select id="kind" v-model="form.kind" class="field" :disabled="confirmed" @change="changeKind"><option value="single">単品</option><option value="bundle">セット商品</option></select><p class="mt-2 text-xs text-slate-500">セット商品は構成する単品の在庫から販売可能数を計算します。本登録後は変更できません。</p></div>
          <div><label for="name" class="mb-2 block text-sm font-medium">販売商品名 <span class="ml-2 text-xs text-red-600">必須</span></label><input id="name" v-model="form.name" class="field" maxlength="200" placeholder="販売商品名を入力" /><p v-if="form.errors.name" class="mt-2 text-sm text-red-600">{{ form.errors.name }}</p></div>
          <div><label for="sku" class="mb-2 block text-sm font-medium">商品コード</label><input id="sku" v-model="form.sku" class="field" maxlength="50" placeholder="空欄の場合は自動採番" /><p class="mt-2 text-xs text-slate-500">半角英数字・ハイフン・アンダースコアが使えます。</p></div>
          <div v-if="form.kind === 'single'"><label for="product-group" class="mb-2 block text-sm font-medium">基本商品 <span class="ml-2 text-xs font-normal text-slate-500">任意</span></label><select id="product-group" v-model="form.product_group_id" class="field" @change="changeGroup"><option value="">基本商品なし</option><option v-for="group in groups" :key="group.id" :value="group.id" :disabled="group.status !== 'active' && product?.product_group_id !== group.id">{{ group.name }}{{ group.status === 'inactive' ? '（無効）' : '' }}</option></select><p class="mt-2 text-xs text-slate-500">「果汁グミ」「ジャガイモ」など、販売商品をまとめる基本商品を選びます。</p></div>
          <section v-if="form.kind === 'single' && selectedGroup" class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-5"><div class="mb-5 flex flex-wrap items-center justify-between gap-3"><div><h3 class="font-semibold text-emerald-950">{{ selectedGroup.name }}の属性</h3><p class="mt-1 text-xs text-emerald-800">仮登録では未入力でも保存できます。</p></div><Link :href="`/admin/product-groups/${selectedGroup.id}/edit`" class="text-sm text-emerald-800 underline">属性設定を見る</Link></div><div v-if="activeDefinitions.length" class="grid gap-5 sm:grid-cols-2"><div v-for="definition in activeDefinitions" :key="definition.id"><label :for="`attribute-${definition.id}`" class="mb-2 block text-sm font-medium">{{ definition.name }} <span v-if="definition.is_required" class="ml-1 text-xs text-red-600">本登録時必須</span></label><select v-if="definition.input_type === 'select'" :id="`attribute-${definition.id}`" v-model="form.attribute_values[definition.id]" class="field"><option value="">選択してください</option><option v-for="option in definition.options" :key="option" :value="option">{{ option }}</option></select><input v-else :id="`attribute-${definition.id}`" v-model="form.attribute_values[definition.id]" :inputmode="definition.input_type === 'number' ? 'decimal' : 'text'" class="field" maxlength="255" :placeholder="definition.input_type === 'number' ? '数値を入力' : `${definition.name}を入力`" /><p v-if="form.errors[`attribute_values.${definition.id}`]" class="mt-2 text-sm text-red-600">{{ form.errors[`attribute_values.${definition.id}`] }}</p></div></div><p v-else class="text-sm text-slate-500">この基本商品には属性が設定されていません。</p></section>
          <div v-if="form.kind === 'single' && archivedValues.length" class="rounded-lg bg-slate-50 p-4 text-sm"><p class="font-semibold">無効になった属性の保存値</p><dl class="mt-2 space-y-1"><div v-for="value in archivedValues" :key="value.id" class="flex gap-3"><dt class="text-slate-500">{{ value.definition.name }}</dt><dd>{{ value.value }}</dd></div></dl></div>
          <section v-if="form.kind === 'bundle'" class="rounded-xl border border-blue-100 bg-blue-50/50 p-5"><div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="font-semibold text-blue-950">セットの構成商品</h3><p class="mt-1 text-xs leading-5 text-blue-800">本登録済みの単品と、1セットに必要な数量を設定します。セット商品を構成品にはできません。</p></div><button type="button" class="rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-900" @click="addComponent">＋ 構成商品を追加</button></div><div class="mt-5 space-y-3"><div v-for="(row, index) in form.bundle_components" :key="index" class="grid items-end gap-3 rounded-lg bg-white p-4 sm:grid-cols-[1fr_11rem_auto]"><div><label :for="`component-${index}`" class="mb-2 block text-sm font-medium">構成商品</label><select :id="`component-${index}`" v-model="row.component_product_id" class="field"><option value="">選択してください</option><option v-for="item in componentProducts" :key="item.id" :value="item.id" :disabled="componentUsedElsewhere(item.id, index)">{{ item.sku }}｜{{ item.name }}</option></select></div><div><label :for="`component-quantity-${index}`" class="mb-2 block text-sm font-medium">1セットあたり</label><div class="flex items-center gap-2"><input :id="`component-quantity-${index}`" v-model="row.quantity" type="number" min="0.0001" step="0.0001" class="field" /><span class="whitespace-nowrap text-sm text-slate-500">{{ componentFor(row)?.unit?.name ?? '' }}</span></div></div><button type="button" class="pb-3 text-sm text-red-700" @click="form.bundle_components.splice(index, 1)">削除</button></div><p v-if="!form.bundle_components.length" class="rounded-lg bg-white p-5 text-sm text-slate-500">構成商品を追加してください。</p></div></section>
          <div class="grid gap-6 sm:grid-cols-2"><div><label for="unit" class="mb-2 block text-sm font-medium">数量の単位 <span class="ml-2 text-xs text-red-600">必須</span></label><select id="unit" v-model="form.unit_id" :disabled="confirmed" class="field disabled:bg-slate-100"><option value="" disabled>選択してください</option><option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option></select><p class="mt-2 text-xs text-slate-500">本登録後は変更できません。</p></div><div><label for="price" class="mb-2 block text-sm font-medium">標準販売単価 <span class="ml-2 text-xs text-slate-500">本登録時必須</span></label><input id="price" v-model="form.default_sale_price" type="text" inputmode="decimal" class="field" placeholder="例：1200" /><p class="mt-2 text-xs text-slate-500">税抜の基準単価。小数4桁まで入力できます。</p></div><div><label for="tax-rate" class="mb-2 block text-sm font-medium">税率区分</label><select id="tax-rate" v-model="form.tax_rate_id" class="field"><option value="">設定しない</option><option v-for="taxRate in taxRates" :key="taxRate.id" :value="taxRate.id">{{ taxRate.name }}（{{ Number(taxRate.rate) }}%）</option></select><p v-if="form.errors.tax_rate_id" class="mt-2 text-sm text-red-600">{{ form.errors.tax_rate_id }}</p></div></div>
        </div>
        <div class="mt-8"><CategoryPicker v-model="form.category_ids" :categories="categories" :retained-ids="product?.categories?.map(category => category.id) ?? []" /></div>
        <ProductImageEditor v-model="form.images" :disabled="form.processing" @reset="form.images = imageRows()" />
        <p v-if="form.progress" class="mt-3 text-sm" role="status">アップロード中 {{ form.progress.percentage }}%</p>
      </section>
      <aside class="space-y-5"><section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="font-semibold">登録・公開設定</h2><p class="mt-4 text-sm">現在の状態：<strong>{{ product ? (confirmed ? '本登録' : '仮登録') : '未保存' }}</strong></p><label for="visibility" class="mt-6 mb-2 block text-sm font-medium">公開状態（本登録時）</label><select id="visibility" v-model="form.publication_status" class="field"><option value="private">非公開</option><option value="public">公開</option></select><p class="mt-3 text-xs leading-6 text-slate-500">本登録しても自動で公開にはなりません。</p><label for="shortage-policy" class="mt-6 mb-2 block text-sm font-medium">在庫不足時の扱い</label><select id="shortage-policy" v-model="form.shortage_policy" class="field"><option value="reject">在庫数を超える注文は受け付けない</option><option value="backorder">不足分を取り寄せとして受け付ける</option></select><p class="mt-3 text-xs leading-6 text-slate-500">取り寄せ分は在庫をマイナスにせず、注文明細へ不足数を記録します。</p></section><section class="rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm leading-7 text-emerald-900"><p class="font-semibold">登録前の確認</p><p>仮登録は販売商品名だけで保存できます。本登録では単位・標準販売単価・必須属性を確認してください。</p></section><button type="button" @click="save('confirmed')" :disabled="form.processing || !units.length" class="btn-primary w-full">{{ form.processing ? '保存中…' : confirmed ? '変更を保存' : '本登録する' }}</button><button v-if="!confirmed" type="submit" :disabled="form.processing || !units.length" class="w-full rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50">仮登録する</button></aside>
    </form>
  </AdminLayout>
</template>
