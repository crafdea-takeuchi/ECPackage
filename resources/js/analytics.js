// Googleアナリティクス（GA4）でお客様向け画面のページビューを計測する。
// Inertia の画面遷移は再読み込みを伴わないため、遷移ごとに page_view を送る。
const SHOP_QUERY_KEYS = ['q', 'category_id', 'availability', 'attribute', 'page'];
// 広告・キャンペーンの流入元を判別するためのパラメータは、どのページでも残す。
const CAMPAIGN_QUERY_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid'];

let configuredId = null;

// 計測に送るパス。パスワード再設定のトークンやメールアドレスなど、個人に結び付く値は送らない。
export function analyticsPath(url) {
  const [path, query = ''] = String(url || '/').split('#')[0].split('?');
  if (path.startsWith('/reset-password')) return '/reset-password';
  if (path.startsWith('/email/verify')) return '/email/verify';
  if (!query) return path;
  const source = new URLSearchParams(query);
  const kept = new URLSearchParams();
  for (const key of [...(path === '/shop' ? SHOP_QUERY_KEYS : []), ...CAMPAIGN_QUERY_KEYS]) {
    if (source.get(key)) kept.set(key, source.get(key));
  }
  const search = kept.toString();
  return search ? `${path}?${search}` : path;
}

function ensureGtag(id, win, doc) {
  if (!win.gtag) {
    win.dataLayer = win.dataLayer || [];
    win.gtag = function gtag() { win.dataLayer.push(arguments); };
    win.gtag('js', new Date());
    const script = doc.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    doc.head.appendChild(script);
  }
  if (configuredId !== id) {
    win.gtag('config', id, { send_page_view: false });
    configuredId = id;
  }
}

export function trackPageView(page, win = window, doc = document) {
  const id = page?.props?.googleAnalyticsId;
  if (!id) return;
  ensureGtag(id, win, doc);
  const path = analyticsPath(page.url);
  win.gtag('event', 'page_view', {
    send_to: id,
    page_location: win.location.origin + path,
    page_path: path,
    page_title: doc.title,
  });
}

export function resetAnalyticsForTesting() {
  configuredId = null;
}
