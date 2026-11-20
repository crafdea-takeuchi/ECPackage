<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close']);
const page = usePage();
const desktop = ref(false);
const menuVisible = computed(() => props.open || desktop.value);
let desktopMediaQuery;

function syncDesktop(event) {
  desktop.value = event.matches;
}

onMounted(() => {
  desktopMediaQuery = window.matchMedia('(min-width: 64rem)');
  syncDesktop(desktopMediaQuery);
  desktopMediaQuery.addEventListener('change', syncDesktop);
});

onBeforeUnmount(() => {
  desktopMediaQuery?.removeEventListener('change', syncDesktop);
});

function isActive(href, exact = false) {
  const path = page.url.split('?')[0];

  return exact ? path === href : path === href || path.startsWith(href + '/');
}

function linkClasses(href, exact = false) {
  return [
    'block rounded-md px-3 py-2.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700',
    isActive(href, exact)
      ? 'bg-emerald-50 text-emerald-950'
      : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  ];
}
</script>

<template>
  <aside
    id="customer-navigation"
    aria-label="メインメニュー"
    :aria-hidden="menuVisible ? undefined : 'true'"
    :inert="!menuVisible"
    class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col overflow-y-auto border-r border-slate-200 bg-white p-4 shadow-xl transition-transform duration-200 lg:static lg:z-0 lg:w-56 lg:shrink-0 lg:self-stretch lg:translate-x-0 lg:shadow-none"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
  >
    <div class="flex items-center justify-between border-b border-slate-100 px-1 pb-3">
      <p class="text-base font-semibold text-slate-900">メニュー</p>
      <button
        type="button"
        class="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 lg:hidden"
        aria-label="メニューを閉じる"
        @click="emit('close')"
      >
        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 6l12 12M18 6 6 18" />
        </svg>
      </button>
    </div>

    <nav class="mt-3 space-y-1">
      <Link href="/" :class="linkClasses('/', true)" @click="emit('close')">ホーム</Link>
      <Link href="/shop" :class="linkClasses('/shop')" @click="emit('close')">商品一覧</Link>
      <Link href="/cart" :class="linkClasses('/cart')" @click="emit('close')">
        <span class="flex items-center justify-between gap-3">
          <span>カート</span>
          <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
            {{ page.props.cart.count }}
          </span>
        </span>
      </Link>
      <Link
        v-if="page.props.auth.user?.role === 'customer'"
        href="/orders"
        :class="linkClasses('/orders')"
        @click="emit('close')"
      >
        注文履歴
      </Link>
      <Link
        v-if="page.props.auth.user?.role === 'admin'"
        href="/admin"
        :class="linkClasses('/admin')"
        @click="emit('close')"
      >
        管理画面
      </Link>
    </nav>

    <div class="mt-5 border-t border-slate-100 pt-4">
      <template v-if="page.props.auth.user">
        <p class="px-3 text-xs text-slate-500">ログイン中</p>
        <p class="mt-1 px-3 text-sm font-medium text-slate-800">{{ page.props.auth.user.name }} さん</p>
        <Link
          href="/logout"
          method="post"
          as="button"
          class="mt-3 w-full rounded-md px-3 py-2.5 text-left text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
          @click="emit('close')"
        >
          ログアウト
        </Link>
      </template>
      <template v-else>
        <Link href="/login" :class="linkClasses('/login', true)" @click="emit('close')">ログイン</Link>
        <Link href="/register" :class="linkClasses('/register', true)" @click="emit('close')">新規登録</Link>
      </template>
    </div>
  </aside>
</template>
