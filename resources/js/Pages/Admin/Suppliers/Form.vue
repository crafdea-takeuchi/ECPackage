<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ supplier: Object, products: Array });
const makeSetting = () => ({ id: null, product_id: '', supplier_product_code: '', default_purchase_price: '', minimum_order_quantity: '', lead_time_days: '', is_primary: false, status: 'active' });
const normalizeSetting = setting => ({
  id: setting.id ?? null,
  product_id: setting.product_id,
  supplier_product_code: setting.supplier_product_code ?? '',
  default_purchase_price: setting.default_purchase_price ?? '',
  minimum_order_quantity: setting.minimum_order_quantity ?? '',
  lead_time_days: setting.lead_time_days ?? '',
  is_primary: Boolean(setting.is_primary),
  status: setting.status ?? 'active',
});
const form = useForm({
  code: props.supplier?.code ?? '',
  name: props.supplier?.name ?? '',
  contact_name: props.supplier?.contact_name ?? '',
  email: props.supplier?.email ?? '',
  phone: props.supplier?.phone ?? '',
  postal_code: props.supplier?.postal_code ?? '',
  address: props.supplier?.address ?? '',
  notes: props.supplier?.notes ?? '',
  status: props.supplier?.status ?? 'active',
  lock_version: props.supplier?.lock_version ?? 0,
  purchase_settings: props.supplier?.purchase_settings?.map(normalizeSetting) ?? [],
});
const activeSettings = computed(() => form.purchase_settings.filter(setting => setting.status === 'active').length);

function usedByAnother(productId, index) {
  return productId && form.purchase_settings.some((setting, current) => current !== index && String(setting.product_id) === String(productId) && setting.status === 'active');
}

function addSetting() {
  form.purchase_settings.push(makeSetting());
}

function deactivateSetting(index) {
  const setting = form.purchase_settings[index];
  if (setting.id) {
    setting.status = 'inactive';
    setting.is_primary = false;
  } else {
    form.purchase_settings.splice(index, 1);
  }
}

function restoreSetting(index) {
  form.purchase_settings[index].status = 'active';
}

function save() {
  form.transform(data => ({ ...data, purchase_settings: data.purchase_settings.map(({ id, ...setting }) => ({ ...setting, is_primary: Boolean(setting.is_primary) })) }));
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      if (props.supplier) {
        form.lock_version = props.supplier.lock_version;
        form.purchase_settings = props.supplier.purchase_settings.map(normalizeSetting);
        form.defaults();
      }
    },
  };
  if (props.supplier) form.put(`/admin/suppliers/${props.supplier.id}`, options);
  else form.post('/admin/suppliers', options);
}
</script>

<template>
  <AdminLayout>
    <Head :title="supplier ? '仕入先を編集' : '仕入先を追加'" />
    <Link href="/admin/suppliers" class="nav-link text-sm">← 仕入先一覧へ</Link>
    <div class="mt-5 mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">仕入先の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ supplier ? '仕入先を編集' : '仕入先を追加' }}</h1><p class="mt-3 text-sm text-slate-500">基本情報と、販売商品ごとの仕入条件を設定します。</p></div>

    <form @submit.prevent="save" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>

      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-2">
        <div><label for="supplier-name" class="mb-2 block text-sm font-medium">仕入先名 <span class="ml-2 text-xs text-red-600">必須</span></label><input id="supplier-name" v-model="form.name" class="field" maxlength="150" placeholder="例：〇〇食品株式会社" /></div>
        <div><label for="supplier-code" class="mb-2 block text-sm font-medium">仕入先コード</label><input id="supplier-code" v-model="form.code" class="field" maxlength="50" placeholder="空欄の場合は自動採番" /><p class="mt-2 text-xs text-slate-500">半角英数字・ハイフン・アンダースコアが使えます。</p></div>
        <div><label for="contact-name" class="mb-2 block text-sm font-medium">担当者名</label><input id="contact-name" v-model="form.contact_name" class="field" maxlength="100" /></div>
        <div><label for="email" class="mb-2 block text-sm font-medium">メールアドレス</label><input id="email" v-model="form.email" type="email" class="field" maxlength="255" /></div>
        <div><label for="phone" class="mb-2 block text-sm font-medium">電話番号</label><input id="phone" v-model="form.phone" class="field" maxlength="50" /></div>
        <div><label for="postal-code" class="mb-2 block text-sm font-medium">郵便番号</label><input id="postal-code" v-model="form.postal_code" class="field" maxlength="20" /></div>
        <div class="lg:col-span-2"><label for="address" class="mb-2 block text-sm font-medium">住所</label><input id="address" v-model="form.address" class="field" maxlength="500" /></div>
        <div class="lg:col-span-2"><label for="notes" class="mb-2 block text-sm font-medium">備考</label><textarea id="notes" v-model="form.notes" class="field min-h-24" maxlength="2000"></textarea></div>
        <div><label for="supplier-state" class="mb-2 block text-sm font-medium">状態</label><select id="supplier-state" v-model="form.status" class="field"><option value="active">有効</option><option value="inactive">無効</option></select><p class="mt-2 text-xs text-slate-500">無効にしても、仕入条件と将来の入庫履歴は保持されます。</p></div>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-6"><div><h2 class="font-semibold">仕入商品設定</h2><p class="mt-2 text-xs text-slate-500">有効 {{ activeSettings }} 件／最大100件。同じ販売商品を複数の仕入先に設定できます。</p></div><button type="button" @click="addSetting" :disabled="form.purchase_settings.length >= 100 || !products.length" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-40">＋ 仕入商品を追加</button></div>
        <div class="space-y-4 p-6">
          <article v-for="(setting, index) in form.purchase_settings" :key="setting.id ?? `new-${index}`" class="rounded-xl border p-5" :class="setting.status === 'active' ? 'border-slate-200' : 'border-slate-100 bg-slate-50 opacity-70'">
            <div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">仕入商品 {{ index + 1 }} <span v-if="setting.status === 'inactive'" class="ml-2 text-xs font-normal text-slate-500">無効</span></h3><button v-if="setting.status === 'active'" type="button" @click="deactivateSetting(index)" class="text-sm text-red-700">{{ setting.id ? '無効にする' : '取り除く' }}</button><button v-else type="button" @click="restoreSetting(index)" class="text-sm text-emerald-800">有効に戻す</button></div>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div class="lg:col-span-2"><label :for="`setting-product-${index}`" class="mb-2 block text-sm font-medium">販売商品 <span class="text-xs text-red-600">必須</span></label><select :id="`setting-product-${index}`" v-model="setting.product_id" class="field" :disabled="Boolean(setting.id)"><option value="">選択してください</option><option v-for="product in products" :key="product.id" :value="product.id" :disabled="usedByAnother(product.id, index)">{{ product.sku }}｜{{ product.name }}{{ product.registration_status === 'provisional' ? '（仮登録）' : '' }}</option></select><p v-if="setting.id" class="mt-1 text-xs text-slate-500">保存後の販売商品は変更できません。</p></div>
              <div><label :for="`supplier-product-code-${index}`" class="mb-2 block text-sm font-medium">仕入先商品コード</label><input :id="`supplier-product-code-${index}`" v-model="setting.supplier_product_code" class="field" maxlength="100" /></div>
              <div><label :for="`purchase-price-${index}`" class="mb-2 block text-sm font-medium">基準仕入単価</label><input :id="`purchase-price-${index}`" v-model="setting.default_purchase_price" type="number" min="0" step="0.0001" class="field" placeholder="税抜" /></div>
              <div><label :for="`minimum-quantity-${index}`" class="mb-2 block text-sm font-medium">最小発注数</label><input :id="`minimum-quantity-${index}`" v-model="setting.minimum_order_quantity" type="number" min="0.0001" step="0.0001" class="field" /></div>
              <div><label :for="`lead-time-${index}`" class="mb-2 block text-sm font-medium">標準納期（日）</label><input :id="`lead-time-${index}`" v-model="setting.lead_time_days" type="number" min="0" max="3650" step="1" class="field" /></div>
            </div>
            <label class="mt-4 inline-flex items-center gap-2 text-sm"><input v-model="setting.is_primary" type="checkbox" :disabled="setting.status !== 'active' || form.status !== 'active'" class="accent-emerald-800" />この販売商品の通常の仕入先にする</label><p v-if="form.status !== 'active'" class="mt-2 text-xs text-slate-500">仕入先を無効にして保存すると、通常の仕入先指定は解除されます。</p>
          </article>
          <div v-if="!form.purchase_settings.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500"><p>{{ products.length ? '仕入商品はまだ設定されていません。' : '先に販売商品を登録すると、仕入条件を設定できます。' }}</p><Link v-if="!products.length" href="/admin/products/create" class="mt-3 inline-block text-emerald-800 underline">販売商品を登録する</Link></div>
        </div>
      </section>

      <div class="flex justify-end"><button type="submit" class="btn-primary min-w-56" :disabled="form.processing">{{ form.processing ? '保存中…' : supplier ? '変更を保存' : '仕入先を作成' }}</button></div>
    </form>
  </AdminLayout>
</template>
