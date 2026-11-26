import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { trackPageView } from './analytics';
const systemName = document.documentElement.dataset.systemName || 'EC02';
// 初回表示と画面遷移のたびに計測する。タイトルの更新を待ってから送る。
router.on('navigate', event => setTimeout(() => trackPageView(event.detail.page)));
createInertiaApp({
    title: title => title ? `${title} | ${systemName}` : systemName,
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#256b56' },
});
