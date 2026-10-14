/*
 * 2.9 menu settings browser QA (docs/modules/menu_settings.md): headless Chromium against
 * tests/browser/panels_server.php (same seeded sandbox as the 2.6 panels QA).
 *
 *   NODE_PATH=/opt/node-tools/node_modules HC_TEST_DB_NAME=hotelcast_test_v \
 *   PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tests/browser/menu_settings_qa.js
 *
 * As a customer admin: moves Broadcast to the top with the Up buttons, drags an item with HTML5 drag & drop,
 * hides three items, saves, checks the sidebar order and the "Hidden items" group; Gujarati page; phone layout
 * (Up / Down buttons, offcanvas sidebar). Screenshots (JPEG ≤ 200 KB) go to docs/screenshots/2.9/
 * (HC_SCREENSHOTS overrides). Exit code 0 = pass.
 */
'use strict';
const { chromium } = require('playwright-core');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const HC = path.resolve(__dirname, '../..');
const OUT = process.env.HC_SCREENSHOTS || path.resolve(HC, '../docs/screenshots/2.9');
const EXE = process.env.HC_CHROMIUM || '/opt/pw-browsers/chromium';
const results = [];
const ERRORS = [];
let failed = 0;
function check(ok, what, extra) { results.push((ok ? 'PASS ' : 'FAIL ') + what + (extra ? ' — ' + extra : '')); if (!ok) failed++; }

function startServer() {
  return new Promise((resolve, reject) => {
    const srv = spawn('php', [path.join(HC, 'tests/browser/panels_server.php')], { env: process.env, stdio: ['pipe', 'pipe', 'inherit'] });
    const rl = readline.createInterface({ input: srv.stdout });
    rl.once('line', (line) => { try { resolve({ srv, info: JSON.parse(line) }); } catch (e) { reject(new Error('server said: ' + line)); } });
    srv.once('exit', (code) => reject(new Error('server exited ' + code)));
  });
}
async function shot(page, name, opts) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, name);
  for (const q of [72, 62, 52, 42, 34]) {
    await page.screenshot({ path: file, type: 'jpeg', quality: q, ...(opts || {}) });
    if (fs.statSync(file).size <= 200 * 1024) break;
  }
  check(fs.statSync(file).size <= 200 * 1024, 'screenshot ' + name, Math.round(fs.statSync(file).size / 1024) + ' KB');
}
async function login(browser, url, user, lang, viewport) {
  const mobile = viewport && viewport.width < 500;
  const context = await browser.newContext({ viewport: viewport || { width: 1440, height: 900 }, locale: lang === 'gu' ? 'gu-IN' : 'en-IN', ...(mobile ? { isMobile: true, hasTouch: true } : {}) });
  context.on('page', (p) => {
    p.on('pageerror', (e) => ERRORS.push('pageerror: ' + e.message));
    p.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) ERRORS.push('console: ' + m.text()); });
    p.on('response', (r) => { if (r.status() >= 400 && !/favicon\.ico|fonts\.g/.test(r.url())) ERRORS.push('HTTP ' + r.status() + ' ' + r.url()); });
  });
  const page = await context.newPage();
  await page.goto(url + 'admin/login.php');
  await page.fill('input[name=username]', user);
  await page.fill('input[name=password]', 'Passw0rd!');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  if (lang) {
    await page.click('.hc-topbar .dropdown button[title]');
    await Promise.all([page.waitForNavigation(), page.click('.js-lang[data-lang="' + lang + '"]')]);
  }
  return page;
}
const navKeys = (page) => page.$$eval('.hc-nav [data-nav]', (els) => els.map((e) => e.getAttribute('data-nav')));
const editorKeys = (page) => page.$$eval('[data-menu-list] .hc-menu-item', (els) => els.map((e) => e.dataset.key));

(async () => {
  const { srv, info } = await startServer();
  const url = info.url;
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  try {
    // ---- Customer admin, English: Settings → Menu
    let page = await login(browser, url, 'mandiradmin');
    await page.goto(url + 'admin/settings.php');
    await Promise.all([page.waitForNavigation(), page.click('[data-menu-settings-link]')]);
    check(/menu_settings\.php\?scope=business/.test(page.url()), 'Settings → Menu link');
    const before = await editorKeys(page);
    check(before[0] === 'index' && before.includes('broadcast'), 'editor lists the sidebar items', before.slice(0, 6).join(','));
    check(JSON.stringify(before) === JSON.stringify(await navKeys(page)), 'editor order = sidebar order');
    await shot(page, 'menu_settings_en.jpg', { fullPage: true });

    // Broadcast to the top with the Up buttons (keyboard / mobile path).
    const up = page.locator('.hc-menu-item[data-key="broadcast"] [data-move="up"]');
    for (let i = 0; i < 40 && !(await up.isDisabled()); i++) await up.click();
    let keys = await editorKeys(page);
    check(keys[0] === 'broadcast', 'Up buttons move Broadcast to the top', keys.slice(0, 3).join(','));
    check(await page.locator('[data-menu-live]').evaluate((el) => new Promise((r) => setTimeout(() => r(el.textContent), 80))) !== '', 'move announced (aria-live)');
    // HTML5 drag & drop: the 5th item onto the lower half of Dashboard (→ third place).
    const dragKey = keys[4];
    await page.locator('.hc-menu-item[data-key="index"]').scrollIntoViewIfNeeded();
    const from = await page.locator('.hc-menu-item[data-key="' + dragKey + '"] .hc-menu-handle').boundingBox();
    const to = await page.locator('.hc-menu-item[data-key="index"]').boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x + 40, to.y + to.height - 4, { steps: 12 });
    await page.mouse.up();
    keys = await editorKeys(page);
    check(keys.indexOf(dragKey) === 2, 'drag & drop moves ' + dragKey + ' right after Dashboard', keys.slice(0, 4).join(','));
    // Hide three items; required ones have no switch.
    for (const k of ['logs', 'apk', 'power']) await page.locator('#show_' + k).uncheck();
    check(await page.locator('#show_index').count() === 0 && await page.locator('.hc-menu-item[data-key="index"] [data-menu-locked]').count() === 1, 'Dashboard is locked');
    check(await page.locator('.hc-menu-item.is-hidden').count() === 3, 'three items marked hidden');
    await Promise.all([page.waitForNavigation(), page.click('[data-menu-save]')]);
    keys = await navKeys(page);
    check(keys[0] === 'broadcast', 'sidebar: Broadcast first', keys.slice(0, 4).join(','));
    check(!keys.includes('logs') && !keys.includes('apk') && !keys.includes('power'), 'sidebar: 3 items hidden');
    const more = page.locator('[data-nav-more-group]');
    check(await more.count() === 1, 'Hidden items group present');
    await page.goto(url + 'admin/index.php');
    await page.click('[data-nav-more-group] > summary');
    check(await page.locator('[data-nav-hidden="logs"]').isVisible(), 'hidden item reachable from the More group');
    await page.evaluate(() => { const s = document.querySelector('.hc-sidebar'); if (s) s.scrollTop = s.scrollHeight; });
    await shot(page, 'sidebar_custom_menu.jpg', { clip: { x: 0, y: 0, width: 520, height: 900 } });
    // A hidden page still opens by URL, with the group opened on it.
    await page.goto(url + 'admin/logs.php');
    check(await page.locator('[data-nav-more-group][open] [data-nav-hidden="logs"].active').count() === 1, 'hidden page opens, active inside the open group');

    // ---- Gujarati
    page = await login(browser, url, 'mandiradmin', 'gu');
    await page.goto(url + 'admin/menu_settings.php');
    check((await page.textContent('h1')).includes('મેનુ સેટિંગ્સ'), 'Gujarati title');
    check((await page.textContent('[data-nav-more-group] summary')).includes('છુપાવેલી આઇટમ (3)'), 'Gujarati Hidden items group');
    await shot(page, 'menu_settings_gu.jpg', { fullPage: true });

    // ---- Phone: staff personal menu (Up / Down buttons), offcanvas sidebar
    page = await login(browser, url, 'shreestaff', null, { width: 390, height: 844 });
    await page.goto(url + 'admin/profile.php');
    await Promise.all([page.waitForNavigation(), page.click('[data-profile-my-menu] a')]);
    check(/scope=me/.test(page.url()), 'Profile → My menu');
    const sw = (await editorKeys(page))[1];
    await page.locator('.hc-menu-item[data-key="' + sw + '"] [data-move="up"]').tap();
    check((await editorKeys(page))[0] === sw, 'tap Up on a phone');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal scroll on a phone');
    await shot(page, 'menu_settings_mobile.jpg', { fullPage: true });
    await Promise.all([page.waitForNavigation(), page.click('[data-menu-save]')]);
    await page.click('.hc-topbar button[data-bs-toggle="offcanvas"]');
    await page.waitForTimeout(500);
    check((await navKeys(page))[0] === sw, 'phone sidebar uses the personal order');
    await shot(page, 'sidebar_mobile.jpg');
  } catch (e) {
    check(false, 'exception', e.stack || String(e));
  } finally {
    await browser.close();
    srv.stdin.end();
  }
  check(ERRORS.length === 0, 'no JS / HTTP errors', ERRORS.slice(0, 5).join(' | '));
  console.log(results.join('\n'));
  console.log(failed ? `\n${failed} FAILED` : '\nALL PASS');
  process.exit(failed ? 1 : 0);
})();
