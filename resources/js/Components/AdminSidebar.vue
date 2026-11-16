<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  groups: {
    type: Array,
    required: true,
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

function isActive(link) {
  const path = page.url.split('?')[0];

  return link.exact ? path === link.href : path === link.href || path.startsWith(link.href + '/');
}

function linkClasses(link) {
  return [
    'block rounded-md px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700',
    isActive(link)
      ? 'bg-emerald-50 text-emerald-950'
      : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  ];
}
</script>

<template>
  <aside
    id="admin-navigation"
    aria-label="管理メニュー"
    :aria-hidden="menuVisible ? undefined : 'true'"
    :inert="!menuVisible"
    class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col overflow-y-auto border-r border-slate-200 bg-white p-4 shadow-xl transition-transform duration-200 lg:static lg:z-0 lg:w-64 lg:shrink-0 lg:self-stretch lg:translate-x-0 lg:shadow-none"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
  >
    <div class="flex items-center justify-between border-b border-slate-100 px-1 pb-3">
      <p class="text-base font-semibold text-slate-900">管理メニュー</p>
      <button
        type="button"
        class="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 lg:hidden"
        aria-label="管理メニューを閉じる"
        @click="emit('close')"
      >
        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 6l12 12M18 6 6 18" />
        </svg>
      </button>
    </div>

    <nav class="mt-4 space-y-5">
      <section v-for="group in props.groups" :key="group.label">
        <p class="px-3 text-xs font-semibold text-slate-400">{{ group.label }}</p>
        <div class="mt-1 space-y-0.5">
          <Link
            v-for="link in group.links"
            :key="link.href"
            :href="link.href"
            :class="linkClasses(link)"
            @click="emit('close')"
          >
            <svg v-if="link.icon === 'settings'" aria-hidden="true" class="mr-2 inline-block size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3" /><path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 1 3h4l1-3 3-1 2-3-2-2 1-3-3-2-1-3z" /></svg>
            {{ link.label }}
          </Link>
        </div>
      </section>
    </nav>

    <!--
    <div class="mt-5 border-t border-slate-100 pt-4">
      <Link href="/" @click="emit('close')">お客様側を見る</Link>
    </div>
    -->
  </aside>
</template>
