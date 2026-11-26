import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { analyticsPath, resetAnalyticsForTesting, trackPageView } from '../../resources/js/analytics.js';

function environment() {
  const appended = [];
  const win = { location: { origin: 'https://shop.example.com' } };
  const doc = {
    title: '商品一覧 | テストストア',
    head: { appendChild: el => appended.push(el) },
    createElement: tag => ({ tag }),
  };

  return { win, doc, appended, events: () => (win.dataLayer ?? []).map(args => [...args]) };
}

beforeEach(() => resetAnalyticsForTesting());

test('nothing is loaded or sent without a measurement ID', () => {
  const { win, doc, appended } = environment();

  trackPageView({ url: '/shop', props: { googleAnalyticsId: null } }, win, doc);

  assert.equal(appended.length, 0);
  assert.equal(win.gtag, undefined);
});

test('loads gtag once and sends a page view for each navigation', () => {
  const { win, doc, appended, events } = environment();

  trackPageView({ url: '/', props: { googleAnalyticsId: 'G-ABC123XYZ9' } }, win, doc);
  doc.title = 'カート | テストストア';
  trackPageView({ url: '/cart', props: { googleAnalyticsId: 'G-ABC123XYZ9' } }, win, doc);

  assert.equal(appended.length, 1);
  assert.equal(appended[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ9');
  assert.equal(appended[0].async, true);
  const sent = events();
  assert.equal(sent[0][0], 'js');
  assert.deepEqual(sent.filter(args => args[0] === 'config'), [['config', 'G-ABC123XYZ9', { send_page_view: false }]]);
  assert.deepEqual(sent.filter(args => args[0] === 'event').map(args => [args[2].page_location, args[2].page_title]), [
    ['https://shop.example.com/', '商品一覧 | テストストア'],
    ['https://shop.example.com/cart', 'カート | テストストア'],
  ]);
});

test('pages without a measurement ID, such as admin pages, are not sent', () => {
  const { win, doc, events } = environment();

  trackPageView({ url: '/shop', props: { googleAnalyticsId: 'G-ABC123XYZ9' } }, win, doc);
  trackPageView({ url: '/admin/products', props: { googleAnalyticsId: null } }, win, doc);

  assert.deepEqual(events().filter(args => args[0] === 'event').map(args => args[2].page_path), ['/shop']);
});

test('personal values in URLs are not sent', () => {
  assert.equal(analyticsPath('/reset-password/secret-token?email=taro%40example.com'), '/reset-password');
  assert.equal(analyticsPath('/email/verify/1/hash?expires=1&signature=abc'), '/email/verify');
  assert.equal(analyticsPath('/login?email=taro%40example.com'), '/login');
  assert.equal(analyticsPath('/orders/12?token=abc'), '/orders/12');
  assert.equal(analyticsPath('/shop?q=%E3%82%B0%E3%83%9F&page=2&email=x%40example.com&utm=1'), '/shop?q=%E3%82%B0%E3%83%9F&page=2');
  assert.equal(analyticsPath('/shop?email=x%40example.com'), '/shop');
});

test('campaign parameters are kept on every page', () => {
  assert.equal(analyticsPath('/?utm_source=newsletter&utm_medium=email&email=x%40example.com'), '/?utm_source=newsletter&utm_medium=email');
  assert.equal(analyticsPath('/shop?q=grape&gclid=abc'), '/shop?q=grape&gclid=abc');
});
