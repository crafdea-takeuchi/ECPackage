<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ProductGallery from '../../Components/ProductGallery.vue';
import ProductImage from '../../Components/ProductImage.vue';
import ProductVariantSelect from '../../Components/ProductVariantSelect.vue';

const props = defineProps({
  product: Object,
  variants: Array,
});

const page = usePage();
const quantity = ref('1');
const selectedVariantId = ref(String(props.product.id));
const pending = ref(false);
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
const available = computed(() => Number(props.product.available_quantity) > 0);
const canOrder = computed(() => !props.product.purchase_unavailable_reason && (available.value || props.product.shortage_policy === 'backorder'));
const quantityStep = computed(() => {
  const scale = Number(props.product.unit?.quantity_scale ?? 0);

  return scale > 0 ? 1 / (10 ** scale) : 1;
});
const description = computed(() => props.product.description || props.product.group?.description || '');

function selectVariant() {
  if (selectedVariantId.value !== String(props.product.id)) {
    router.get(`/shop/${selectedVariantId.value}`);
  }
}

function addToCart() {
  if (!canOrder.value || pending.value) return;
  pending.value = true;
  router.post('/cart', {
    product_id: props.product.id,
    quantity: quantity.value,
  }, {
    preserveScroll: true,
    onFinish: () => {
      pending.value = false;
    },
  });
}
</script>

<template>
  <Head :title="product.name">
    <meta
      v-if="description"
      head-key="description"
      name="description"
      :content="description.slice(0, 160)"
    >
  </Head>

  <AppLayout>
    <nav aria-label="パンくずリスト" class="mb-6 text-sm text-slate-500">
      <Link href="/shop" class="nav-link">商品一覧</Link>
      <span class="mx-2" aria-hidden="true">/</span>
      <span class="text-slate-700">{{ product.name }}</span>
    </nav>

    <div v-if="page.props.errors.quantity || page.props.errors.product_id" role="alert" class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">
      {{ page.props.errors.quantity || page.props.errors.product_id }}
    </div>

    <article class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-12">
      <ProductGallery :images="product.images" :name="product.name" />

      <div>
        <div class="flex flex-wrap gap-2">
          <span v-if="product.kind === 'bundle'" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800">セット商品</span>
          <span v-for="category in product.categories" :key="category.id" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{{ category.name }}</span>
        </div>

        <p class="mt-5 text-xs text-slate-400">{{ product.sku }}</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-emerald-950">{{ product.name }}</h1>
        <p v-if="product.group" class="mt-2 text-sm text-slate-500">基本商品：{{ product.group.name }}</p>

        <div v-if="variants.length > 1" class="mt-6">
          <label for="product-variant" class="mb-2 block text-sm font-semibold text-slate-800">バリエーション</label>
          <ProductVariantSelect v-model="selectedVariantId" :variants="variants" @change="selectVariant" />
        </div>

        <p v-if="description" class="mt-6 whitespace-pre-line text-sm leading-7 text-slate-700">{{ description }}</p>

        <dl v-if="product.attribute_values?.length" class="mt-6 grid gap-x-8 gap-y-3 rounded-xl bg-white p-5 sm:grid-cols-2">
          <div v-for="attribute in product.attribute_values" :key="attribute.id">
            <dt class="text-xs text-slate-500">{{ attribute.definition.name }}</dt>
            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ attribute.value }}</dd>
          </div>
        </dl>

        <div class="mt-7 border-y border-slate-200 py-6">
          <p class="text-3xl font-bold tabular-nums">
            {{ money(product.price_including_tax) }}
            <span class="ml-1 text-sm font-normal text-slate-500">円（税込） / {{ product.unit.name }}</span>
          </p>
          <p class="mt-3 text-sm" :class="available ? 'text-emerald-700' : 'text-red-600'">
            {{ available ? `在庫 ${money(product.available_quantity)} ${product.unit.name}` : (product.shortage_policy === 'backorder' ? '取り寄せで注文できます' : '在庫なし') }}
          </p>
        </div>

        <p v-if="product.purchase_unavailable_reason" role="status" class="mt-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">{{ product.purchase_unavailable_reason }}</p>
        <div class="mt-6 flex flex-wrap items-end gap-3">
          <div class="w-32">
            <label for="detail-quantity" class="mb-2 block text-xs text-slate-500">数量</label>
            <input
              id="detail-quantity"
              v-model="quantity"
              type="number"
              :min="quantityStep"
              :step="quantityStep"
              class="field"
              :disabled="!canOrder"
            >
          </div>
          <button type="button" class="btn-primary min-w-52 grow" :disabled="!canOrder || pending" @click="addToCart">
            {{ pending ? '追加中…' : 'カートに追加' }}
          </button>
        </div>
        <Link href="/cart" class="mt-4 inline-block text-sm nav-link">カートを見る（{{ page.props.cart.count }}）→</Link>
      </div>
    </article>

    <section v-if="product.kind === 'bundle'" class="mt-10 rounded-xl border border-slate-200 bg-white p-6">
      <h2 class="text-lg font-bold text-slate-900">セット内容</h2>
      <ul class="mt-4 divide-y divide-slate-100">
        <li v-for="component in product.bundle_components" :key="component.id" class="flex items-center gap-4 py-4 first:pt-0 last:pb-0">
          <ProductImage :image="component.component_product.images?.[0]" :name="component.component_product.name" class="w-16 shrink-0" />
          <div class="min-w-0">
            <p class="font-semibold text-slate-800">{{ component.component_product.name }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ money(component.quantity) }} {{ component.component_product.unit.name }}</p>
          </div>
        </li>
      </ul>
    </section>

    <div class="mt-10 border-t border-slate-200 pt-6">
      <Link href="/shop" class="nav-link text-sm">← 商品一覧へ戻る</Link>
    </div>
  </AppLayout>
</template>
