<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import ProductImage from '../../Components/ProductImage.vue';
import ProductVariantSelect from '../../Components/ProductVariantSelect.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ products: Object, filters: Object, categories: Array });
const page = usePage();
const search = reactive({
  q: props.filters.q ?? '',
  category_id: props.filters.category_id ?? '',
  availability: props.filters.availability ?? '',
  attribute: props.filters.attribute ?? '',
});
const quantities = reactive({});
const selections = reactive({});
watch(() => props.products.data, products => {
  products.forEach(product => {
    product.variants.forEach(variant => { quantities[variant.id] ??= '1'; });
    if (!product.variants.some(variant => String(variant.id) === selections[product.group_key])) selections[product.group_key] = '';
  });
}, { immediate: true });
const selectedFor = product => product.variants.find(variant => String(variant.id) === selections[product.group_key]);
const chosen = product => product.variant_count === 1 ? product.variants[0] : selectedFor(product);
const canOrder = variant => variant && !variant.purchase_unavailable_reason && (Number(variant.available_quantity) > 0 || variant.shortage_policy === 'backorder');
const stockStatus = product => selectedFor(product) ? (Number(selectedFor(product).available_quantity) > 0 ? 'available' : (selectedFor(product).shortage_policy === 'backorder' ? 'backorder' : 'sold_out')) : product.stock_status;
const detailId = product => chosen(product)?.id ?? product.id;
const quantityStep = product => 1 / (10 ** Number(chosen(product)?.unit?.quantity_scale ?? 0));
const pending = reactive({ productId: null });
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);

function submit() {
  router.get('/shop', search, { preserveState: true, replace: true });
}

function reset() {
  Object.assign(search, { q: '', category_id: '', availability: '', attribute: '' });
  router.get('/shop');
}

function add(product) {
  const variant = chosen(product);
  if (!canOrder(variant)) return;
  pending.productId = variant.id;
  router.post('/cart', {
    product_id: variant.id,
    quantity: quantities[variant.id],
  }, {
    preserveScroll: true,
    onFinish: () => {
      pending.productId = null;
    },
  });
}
</script>

<template>
  <Head title="商品一覧" />

  <AppLayout>
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-xs font-bold tracking-widest text-emerald-700">ONLINE SHOP</p>
        <h1 class="mt-3 text-3xl font-bold">商品一覧</h1>
        <p class="mt-3 text-sm text-slate-500">現在販売中の商品をご覧いただけます。</p>
      </div>
      <Link href="/cart" class="rounded-lg border border-emerald-700 bg-white px-5 py-3 text-sm font-semibold text-emerald-800">
        カートを見る（{{ page.props.cart.count }}）
      </Link>
    </div>

    <div v-if="page.props.errors.quantity || page.props.errors.product_id" role="alert" class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">
      {{ page.props.errors.quantity || page.props.errors.product_id }}
    </div>

    <form class="my-7 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submit">
      <div>
        <label for="shop-q" class="mb-2 block text-sm font-medium">商品名・商品コード・基本商品名</label>
        <input id="shop-q" v-model="search.q" class="field" maxlength="150">
      </div>
      <div>
        <label for="shop-category" class="mb-2 block text-sm font-medium">商品分類</label>
        <select id="shop-category" v-model="search.category_id" class="field">
          <option value="">すべて</option>
          <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
        </select>
      </div>
      <div>
        <label for="shop-stock" class="mb-2 block text-sm font-medium">在庫状態</label>
        <select id="shop-stock" v-model="search.availability" class="field">
          <option value="">すべて</option>
          <option value="available">在庫あり</option>
          <option value="sold_out">在庫なし</option>
          <option value="backorder">取り寄せ可能</option>
        </select>
      </div>
      <div><label for="shop-attribute" class="mb-2 block text-sm font-medium">属性・バリエーション</label><input id="shop-attribute" v-model="search.attribute" class="field" maxlength="150" placeholder="例：24cm、赤"></div>
      <div class="flex items-end gap-3">
        <button type="submit" class="btn-primary">検索</button>
        <button type="button" class="nav-link px-2 py-3" @click="reset">解除</button>
      </div>
    </form>

    <p class="mb-4 text-sm text-slate-500">{{ products.total }}件の商品グループ<span v-if="search.q || search.category_id || search.availability || search.attribute">（価格・種類数・在庫は検索条件に一致する公開商品の情報です）</span></p>
    <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="product in products.data" :key="product.group_key" class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6">
        <Link :href="`/shop/${detailId(product)}`" class="mb-4 block">
          <ProductImage :image="product.images?.[0]" :name="product.name" />
        </Link>

        <div class="flex flex-wrap gap-2">
          <span v-if="product.kind === 'bundle'" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800">セット商品</span>
          <span v-for="category in product.categories" :key="category.id" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{{ category.name }}</span>
        </div>

        <p v-if="product.variant_count === 1" class="mt-5 text-xs text-slate-400">{{ product.sku }}</p>
        <p v-else class="mt-5 text-xs font-semibold text-emerald-700">{{ product.variant_count }}種類</p>
        <h2 class="mt-2 text-lg font-semibold text-emerald-950">
          <Link :href="`/shop/${detailId(product)}`" class="transition hover:text-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
            {{ product.name }}
          </Link>
        </h2>

        <dl v-if="product.attribute_summary.length" class="mt-4 space-y-1 text-sm text-slate-600"><div v-for="attribute in product.attribute_summary" :key="attribute.name"><dt class="inline font-medium">{{ attribute.name }}：</dt><dd class="inline">{{ attribute.values.join('・') }}{{ attribute.has_more ? ' ほか' : '' }}</dd></div></dl>
        <div v-if="product.kind === 'bundle' && product.variant_count === 1" class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
          <p class="font-semibold text-slate-700">セット内容</p>
          <ul class="mt-1 space-y-1">
            <li v-for="component in product.bundle_components" :key="component.id">
              {{ component.component_product.name }} × {{ money(component.quantity) }} {{ component.component_product.unit.name }}
            </li>
          </ul>
        </div>

        <p class="mt-5 text-2xl font-bold tabular-nums">
          {{ money(selectedFor(product)?.price_including_tax ?? product.price_including_tax) }}<template v-if="!selectedFor(product) && product.has_price_range">～{{ money(product.max_price_including_tax) }}</template>
          <span class="ml-1 text-xs font-normal text-slate-500">円（税込）<template v-if="product.variant_count === 1"> / {{ product.unit.name }}</template></span>
        </p>
        <p class="mt-3 text-sm" :class="stockStatus(product) === 'available' ? 'text-emerald-700' : (stockStatus(product) === 'backorder' ? 'text-amber-700' : 'text-red-600')">
          {{ stockStatus(product) === 'available' ? '在庫あり' : (stockStatus(product) === 'backorder' ? '取り寄せ可能' : '在庫切れ') }}
          <span v-if="stockStatus(product) === 'available' && (selectedFor(product) ? selectedFor(product).shortage_policy === 'backorder' : product.can_backorder)" class="ml-2 text-xs">取り寄せ対応あり</span>
        </p>

        <Link :href="`/shop/${detailId(product)}`" class="mt-4 inline-block text-sm font-semibold text-emerald-800 hover:underline">
          詳細を見る →
        </Link>

        <div class="mt-auto pt-4">
          <div v-if="product.variant_count > 1" class="mb-4">
            <label :for="`shop-variant-${product.id}`" class="mb-2 block text-sm font-medium">バリエーション</label>
            <ProductVariantSelect :id="`shop-variant-${product.id}`" v-model="selections[product.group_key]" :variants="product.variants" :disabled="product.variants.some(variant => variant.id === pending.productId)" />
          </div>
          <p v-if="selectedFor(product)" class="mb-2 text-xs text-slate-500">{{ selectedFor(product).sku }} / {{ selectedFor(product).unit.name }}</p>
          <div class="flex items-end gap-3">
            <div class="w-28">
              <label :for="`shop-quantity-${product.id}`" class="mb-2 block text-xs text-slate-500">数量</label>
              <input :id="`shop-quantity-${product.id}`" :value="quantities[chosen(product)?.id] ?? '1'" type="number" :min="quantityStep(product)" :step="quantityStep(product)" class="field" :disabled="!canOrder(chosen(product))" @input="chosen(product) && (quantities[chosen(product).id] = $event.target.value)">
            </div>
            <p v-if="chosen(product)?.purchase_unavailable_reason" role="status" class="w-full text-sm text-amber-800">{{ chosen(product).purchase_unavailable_reason }}</p>
            <button type="button" class="btn-primary grow" :disabled="!canOrder(chosen(product)) || pending.productId === chosen(product)?.id" @click="add(product)">{{ pending.productId === chosen(product)?.id ? '追加中…' : 'カートに追加' }}</button>
          </div>
        </div>
      </article>
    </section>

    <div v-if="!products.data.length" class="rounded-xl border border-slate-200 bg-white p-12 text-center">
      <p class="font-semibold">条件に一致する商品がありません。</p>
    </div>

    <nav aria-label="商品一覧のページ切り替え" class="mt-7 flex items-center justify-between text-sm">
      <span>{{ products.current_page }} / {{ products.last_page }} ページ</span>
      <div class="flex gap-5">
        <Link v-if="products.prev_page_url" :href="products.prev_page_url" class="nav-link">← 前へ</Link>
        <Link v-if="products.next_page_url" :href="products.next_page_url" class="nav-link">次へ →</Link>
      </div>
    </nav>
  </AppLayout>
</template>
