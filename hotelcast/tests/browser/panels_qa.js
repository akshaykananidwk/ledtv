/*
 * 2.6 panels browser QA (docs/modules/panels.md): headless Chromium against tests/browser/panels_server.php.
 *
 *   NODE_PATH=/opt/node-tools/node_modules HC_TEST_DB_NAME=hotelcast_test_v \
 *   PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tests/browser/panels_qa.js
 *
 * Logs in as the Super Admin, a reseller and a customer admin (EN and GU), checks the panel attribute, the
 * sidebar, the global search, the Customer 360 tabs and switches, the impersonation banner, and saves
 * screenshots (JPEG ≤ 200 KB) to docs/screenshots/2.6/ (HC_SCREENSHOTS overrides). Exit code 0 = pass.
 */
'use strict';
const { chromium } = require('playwright-core');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const HC = path.resolve(__dirname, '../..');
const OUT = process.env.HC_SCREENSHOTS || path.resolve(HC, '../docs/screenshots/2.6');
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
async function shot(page, name, full) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, name);
  for (const q of [72, 62, 52, 42, 34]) {
    await page.screenshot({ path: file, type: 'jpeg', quality: q, fullPage: !!full });
    if (fs.statSync(file).size <= 200 * 1024) break;
  }
  check(fs.statSync(file).size <= 200 * 1024, 'screenshot ' + name, Math.round(fs.statSync(file).size / 1024) + ' KB');
}
async function login(browser, url, user, lang, viewport) {
  // One fresh context per login so the previous session never redirects the login page.
  const context = await browser.newContext({ viewport: viewport || { width: 1440, height: 900 }, locale: lang === 'gu' ? 'gu-IN' : 'en-IN', ...(viewport && viewport.width < 500 ? { isMobile: true, hasTouch: true } : {}) });
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
    // The UI language is a per-user setting: switch it through the header menu (ajax set_language + reload).
    await page.click('.hc-topbar .dropdown button[title]');
    await Promise.all([page.waitForNavigation(), page.click('.js-lang[data-lang="' + lang + '"]')]);
  }
  return page;
}
const panelOf = (page) => page.getAttribute('body', 'data-panel');
/** Click a switch, answer the confirm modal when one opens, wait for the toast. */
async function flip(page, locator) {
  await locator.click();
  const modal = page.locator('#hcConfirmModal.show');
  try { await modal.waitFor({ state: 'visible', timeout: 1500 }); await page.click('#hcConfirmModal [data-ok]'); } catch (e) { /* no confirmation needed */ }
  await page.waitForSelector('#hcToasts .toast', { timeout: 10000 });
  await page.waitForTimeout(500);
}
const navKeys = (page) => page.$$eval('[data-nav]', (els) => els.map((e) => e.getAttribute('data-nav')));

(async () => {
  const { srv, info } = await startServer();
  const browser = await chromium.launch({ executablePath: EXE });
  const errors = ERRORS;
  try {
    // ---- Super Admin console (EN)
    let page = await login(browser, info.url, 'superadmin');
    check(/platform_overview\.php/.test(page.url()), 'super admin lands on the overview', page.url());
    check((await panelOf(page)) === 'platform', 'body data-panel=platform');
    let keys = await navKeys(page);
    check(keys.includes('platform_overview') && keys.includes('platform_hotels') && keys.includes('platform_screens'), 'console sidebar has platform items', keys.join(','));
    check(!keys.some((k) => ['index', 'rooms', 'content', 'playlists', 'users', 'settings'].includes(k)), 'console sidebar has no customer modules');
    check(await page.$('[data-panel-badge]') !== null, 'Super Admin badge in the header');
    check(await page.$('[data-alerts]') !== null, 'alerts list shows (suspended / expiring / offline / invoice)');
    await shot(page, 'super-admin-overview.jpg');
    // Platform switch: close and reopen the online sign-up.
    const sw = page.locator('[data-kind="signup_open"] input');
    await flip(page, sw);
    check(!(await sw.isChecked()), 'sign-up switch toggled off via ajax');
    await flip(page, sw);
    check(await sw.isChecked(), 'sign-up switch toggled on again');

    await page.goto(info.url + 'admin/platform_hotels.php');
    check((await page.$$('[data-archive-customer]')).length >= 4, 'customers list has archive buttons (delete only after archiving)');
    check((await page.$$('[data-kind="customer_status"]')).length >= 4, 'customers list has status switches');
    await shot(page, 'super-admin-customers.jpg');

    // Global search from the header box.
    await page.fill('.hc-global-search input', 'tv-shree');
    await Promise.all([page.waitForNavigation(), page.press('.hc-global-search input', 'Enter')]);
    check(/platform_search\.php/.test(page.url()), 'header search goes to the results page');
    check(await page.$('[data-search-group="screens"]') !== null, 'search finds TVs by device id');
    await page.goto(info.url + 'admin/platform_search.php?q=' + encodeURIComponent('sanjivani'));
    check(await page.$('[data-search-group="customers"]') !== null && await page.$('[data-search-group="users"]') !== null, 'search finds customers and users by e-mail');
    await shot(page, 'super-admin-global-search.jpg');

    // Customer 360.
    const shree = info.hotels.shree;
    await page.goto(info.url + 'admin/platform_customer.php?id=' + shree + '&tab=plan');
    check((await panelOf(page)) === 'platform', 'Customer 360 renders inside the console');
    const rows = await page.$$('[data-feature-switches] [data-feature]');
    check(rows.length > 10, 'plan tab lists feature switches', rows.length + ' features');
    const fsw = page.locator('[data-feature="tickers"] input');
    const was = await fsw.isChecked();
    await flip(page, fsw);
    check((await fsw.isChecked()) !== was, 'feature switch toggles via ajax');
    await flip(page, fsw);
    check((await fsw.isChecked()) === was, 'feature switch toggles back');
    await shot(page, 'customer-360-plan.jpg');
    await page.goto(info.url + 'admin/platform_customer.php?id=' + shree + '&tab=screens');
    check(await page.$('#psTable') !== null, 'screens tab shows the TV table with commands');
    check((await page.$$('[data-screen-switches] [data-screen]')).length >= 8, 'screens tab has on/off switches per screen');
    await shot(page, 'customer-360-screens.jpg');
    await page.goto(info.url + 'admin/platform_customer.php?id=' + shree + '&tab=users');
    check(await page.$('[data-user-create]') !== null, 'users tab has the create form');
    await page.fill('#nu_u', 'qa_user');
    await page.fill('#nu_n', 'QA User');
    await page.fill('#nu_e', 'qa@shreemandir.test');
    await page.fill('#nu_p', 'Passw0rd!');
    await page.selectOption('#nu_r', 'manager');
    await page.uncheck('#nu_m');
    await Promise.all([page.waitForNavigation(), page.click('[data-user-create] button[type=submit], [data-user-create] button:not([type])')]);
    check((await page.textContent('body')).includes('qa_user'), 'user created from the console');
    await shot(page, 'customer-360-users.jpg');
    await page.goto(info.url + 'admin/platform_customer.php?id=' + shree + '&tab=settings');
    await page.click('[data-delete-customer]');
    await page.waitForSelector('#hcDeleteCustomer.show');
    check(await page.isDisabled('#hcDeleteCustomer [data-delete-submit]'), 'delete needs the typed name');
    await page.fill('#hcDeleteCustomer [data-delete-input]', 'Shree Mandir Rajkot');
    check(!(await page.isDisabled('#hcDeleteCustomer [data-delete-submit]')), 'delete enabled after typing the exact name');
    await shot(page, 'customer-360-delete-modal.jpg');
    await page.keyboard.press('Escape');

    // Impersonation: open the workspace → customer theme + banner, exit → console.
    await page.goto(info.url + 'admin/platform_customer.php?id=' + shree);
    await Promise.all([page.waitForNavigation(), page.click('form[action$="platform_customer.php"] button:has(.bi-box-arrow-in-right)')]);
    check((await panelOf(page)) === 'customer', 'workspace uses the customer panel', await panelOf(page));
    check(await page.$('[data-impersonation-banner]') !== null, 'impersonation banner shown');
    keys = await navKeys(page);
    check(keys.includes('rooms') && !keys.includes('platform_hotels'), 'inside the workspace the sidebar is the customer\'s');
    await shot(page, 'impersonation-banner.jpg');
    await Promise.all([page.waitForNavigation(), page.click('.hc-impersonation-exit')]);
    check(/platform_overview\.php/.test(page.url()) && (await panelOf(page)) === 'platform', 'Exit workspace returns to the console');

    // Mobile width.
    const mp = await login(browser, info.url, 'superadmin', null, { width: 390, height: 844 });
    check(await mp.isHidden('#hcSidebar'), 'sidebar collapsed on mobile');
    await shot(mp, 'super-admin-overview-mobile.jpg');
    await mp.click('button[data-bs-toggle="offcanvas"]');
    await mp.waitForSelector('#hcSidebar.show');
    await mp.waitForTimeout(400);
    await shot(mp, 'super-admin-sidebar-mobile.jpg');
    const docW = await mp.evaluate(() => document.documentElement.scrollWidth);
    check(docW <= 390, 'no horizontal overflow on mobile', docW + 'px');
    await mp.context().close();

    // ---- Super Admin console (GU)
    const gp = await login(browser, info.url, 'superadmin', 'gu');
    check((await gp.textContent('body')).includes('સુપર એડમિન કન્સોલ'), 'Gujarati console name');
    await shot(gp, 'super-admin-overview-gu.jpg');
    await gp.goto(info.url + 'admin/platform_customer.php?id=' + shree + '&tab=plan');
    await shot(gp, 'customer-360-plan-gu.jpg');
    await gp.context().close();

    // ---- Reseller panel
    await page.context().close();
    page = await login(browser, info.url, 'reseller');
    check(/reseller_overview\.php/.test(page.url()), 'reseller lands on its overview', page.url());
    check((await panelOf(page)) === 'reseller', 'body data-panel=reseller');
    keys = await navKeys(page);
    check(keys.includes('reseller') && keys.includes('reseller_invoices') && keys.includes('reseller_support'), 'reseller sidebar items', keys.join(','));
    check(!keys.some((k) => k.startsWith('platform_') && k !== 'platform_screens' && k !== 'platform_demo' && k !== 'platform_chains'), 'no platform-only items for the reseller');
    const body = await page.textContent('body');
    check(body.includes('Sanjivani Hospital') && !body.includes('Shree Mandir'), 'reseller overview shows only own customers');
    await shot(page, 'reseller-overview.jpg');
    await page.goto(info.url + 'admin/platform_search.php?q=Shree');
    check((await page.textContent('body')).includes('Nothing found'), 'reseller search cannot find other customers');
    await page.goto(info.url + 'admin/reseller.php');
    await shot(page, 'reseller-customers.jpg');

    // ---- Customer workspace (EN + GU)
    await page.context().close();
    page = await login(browser, info.url, 'mandiradmin');
    check((await panelOf(page)) === 'customer', 'customer admin: customer panel');
    keys = await navKeys(page);
    check(!keys.some((k) => k.startsWith('platform_') || k.startsWith('reseller')), 'customer sidebar has no platform / reseller items');
    check(await page.$('[data-panel-badge]') === null && await page.$('.hc-global-search') === null, 'no console badge / search for the customer');
    check((await page.textContent('.hc-brand')).includes('Shree Mandir Rajkot'), 'customer header shows the business name');
    await shot(page, 'customer-dashboard.jpg');
    const gcp = await login(browser, info.url, 'mandiradmin', 'gu');
    await shot(gcp, 'customer-dashboard-gu.jpg');
    await gcp.context().close();
    for (const u of ['admin/platform_overview.php', 'admin/platform_search.php?q=x', 'admin/reseller_overview.php']) {
      const r = await page.goto(info.url + u);
      check(r.status() === 403, 'customer admin gets 403 on ' + u, String(r.status()));
    }
  } catch (e) {
    check(false, 'exception', e.stack || String(e));
  } finally {
    await browser.close();
    srv.stdin.end();
  }
  const appErrors = errors.filter((e) => !/HTTP 403 .*(platform_overview|platform_search|reseller_overview)/.test(e));
  check(appErrors.length === 0, 'no JavaScript / HTTP errors', appErrors.slice(0, 5).join(' | '));
  console.log(results.join('\n'));
  console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
  process.exit(failed ? 1 : 0);
})();
