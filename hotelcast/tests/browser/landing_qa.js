/*
 * 2.6.1 landing page browser QA: headless Chromium against tests/browser/landing_server.php.
 *
 *   NODE_PATH=/opt/node-tools/node_modules HC_TEST_DB_NAME=hotelcast_test_x \
 *   PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tests/browser/landing_qa.js
 *
 * English / Gujarati / Hindi at 360, 390, 768 and 1280 px: no horizontal overflow, no console errors / CSP
 * violations / failed requests, tap targets >= 44 px, the mobile menu opens and closes, reveal-on-scroll
 * leaves nothing hidden. Screenshots (PNG) to docs/screenshots/2.6.1/ (HC_SCREENSHOTS overrides). Exit 0 = pass.
 */
'use strict';
const { chromium } = require('playwright-core');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const HC = path.resolve(__dirname, '../..');
const OUT = process.env.HC_SCREENSHOTS || path.resolve(HC, '../docs/screenshots/2.6.1');
const EXE = process.env.HC_CHROMIUM || '/opt/pw-browsers/chromium';
const results = [];
const ERRORS = [];
let failed = 0;
function check(ok, what, extra) { results.push((ok ? 'PASS ' : 'FAIL ') + what + (extra ? ' — ' + extra : '')); if (!ok) failed++; }

function startServer() {
  return new Promise((resolve, reject) => {
    const srv = spawn('php', [path.join(HC, 'tests/browser/landing_server.php')], { env: process.env, stdio: ['pipe', 'pipe', 'inherit'] });
    const rl = readline.createInterface({ input: srv.stdout });
    rl.once('line', (line) => { try { resolve({ srv, info: JSON.parse(line) }); } catch (e) { reject(new Error('server said: ' + line)); } });
    srv.once('exit', (code) => reject(new Error('server exited ' + code)));
  });
}

async function open(browser, url, width, opts) {
  opts = opts || {};
  const mobile = width < 600;
  const context = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, deviceScaleFactor: 1,
    reducedMotion: opts.motion ? 'no-preference' : 'reduce', ...(mobile ? { isMobile: true, hasTouch: true } : {}) });
  const page = await context.newPage();
  page.on('pageerror', (e) => ERRORS.push(width + ' pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') ERRORS.push(width + ' console ' + m.type() + ': ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) ERRORS.push('HTTP ' + r.status() + ' ' + r.url()); });
  page.on('requestfailed', (r) => ERRORS.push('request failed ' + r.url()));
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function overflow(page) {
  return page.evaluate(() => {
    const w = document.documentElement.clientWidth;
    const bad = [];
    document.querySelectorAll('body *').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width && (r.right > w + 1 || r.left < -1) && getComputedStyle(el).position !== 'fixed' && !el.closest('.z-ticker,.hero-art,.blob,.skip')) bad.push(el.className || el.tagName);
    });
    return { scroll: document.documentElement.scrollWidth, width: w, bad: bad.slice(0, 5) };
  });
}

async function smallTargets(page) {
  return page.evaluate(() => {
    const out = [];
    document.querySelectorAll('a[href], button, summary').forEach((el) => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      if (!r.width || cs.visibility === 'hidden' || el.closest('.skip')) return;
      if (r.height < 44 - 0.5) out.push((el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    });
    return out;
  });
}

(async () => {
  const { srv, info } = await startServer();
  const browser = await chromium.launch({ executablePath: fs.existsSync(EXE + '/chrome') ? EXE + '/chrome' : undefined });
  fs.mkdirSync(OUT, { recursive: true });
  try {
    for (const lang of ['en', 'gu', 'hi']) {
      for (const width of [360, 390, 768, 1280]) {
        const { context, page } = await open(browser, info.url + '?lang=' + lang, width);
        const o = await overflow(page);
        check(o.scroll <= o.width, `${lang} ${width}px no horizontal scroll`, `${o.scroll}/${o.width} ${o.bad.join(',')}`);
        const small = await smallTargets(page);
        check(small.length === 0, `${lang} ${width}px tap targets >= 44px`, small.slice(0, 6).join(' | '));
        check((await page.getAttribute('html', 'lang')) === lang, `${lang} ${width}px html lang`);
        const sections = await page.$$eval('#usecases,#features,#how,#plans,#faq,#contact', (els) => els.length);
        check(sections === 6, `${lang} ${width}px sections`, String(sections));
        if (width === 1280) {
          const h = await page.$$eval('.navlinks a', (els) => Math.max(...els.map((e) => e.getBoundingClientRect().height)));
          check(h <= 48, `${lang} 1280px nav links on one line`, String(h));
        }
        if (lang === 'en' && width === 1280) {
          await page.screenshot({ path: path.join(OUT, 'landing-desktop-en.png'), fullPage: true });
          await page.evaluate(() => { document.querySelector('.topbar').style.position = 'static'; document.querySelector('.totop').style.display = 'none'; });
          const plans = await page.$('#plans');
          await plans.screenshot({ path: path.join(OUT, 'landing-plans.png') });
          const cards = await page.$$eval('#plans .plan', (els) => els.map((e) => e.querySelector('h3').textContent.trim()));
          check(cards.length >= 1 && cards.includes('Business'), 'plan cards from the database', cards.join(', '));
        }
        if (width === 390 && lang !== 'en') {
          await page.screenshot({ path: path.join(OUT, `landing-mobile-${lang}.png`), fullPage: true });
        }
        if (width === 390 && lang === 'gu') {
          const navHidden = await page.isHidden('#sitenav');
          check(navHidden, 'mobile: menu closed at start');
          await page.click('.menu-btn');
          check(await page.isVisible('#sitenav'), 'mobile: menu opens');
          check((await page.getAttribute('.menu-btn', 'aria-expanded')) === 'true', 'mobile: aria-expanded');
          await page.screenshot({ path: path.join(OUT, 'landing-mobile-menu-open.png') });
          await page.click('#sitenav a[href="#plans"]');
          await page.waitForTimeout(400);
          check(await page.isHidden('#sitenav'), 'mobile: menu closes after a link');
        }
        await context.close();
      }
    }
    // Motion allowed: reveal-on-scroll must leave nothing hidden after scrolling through the page.
    const { context, page } = await open(browser, info.url, 1280, { motion: true });
    const pre = await page.$$eval('.reveal.pre', (els) => els.length);
    check(pre > 0, 'reveal: below-the-fold items wait', String(pre));
    for (let y = 0; y < 20000; y += 500) { await page.mouse.wheel(0, 500); await page.waitForTimeout(40); }
    await page.waitForTimeout(800);
    const left = await page.$$eval('.reveal.pre', (els) => els.length);
    check(left === 0, 'reveal: everything visible after scrolling', String(left));
    check(await page.isVisible('.totop.show'), 'back-to-top button appears');
    await context.close();
    // Logo / CTA links resolve.
    const r = await fetch(info.url + 'signup.php');
    check(r.status === 200, 'signup.php reachable (sign-up enabled)');
  } catch (e) {
    check(false, 'exception', e.stack);
  } finally {
    await browser.close();
    srv.stdin.end();
  }
  // Shrink the PNGs (256-colour palette) when ImageMagick is available; the page itself is not affected.
  for (const f of fs.readdirSync(OUT).filter((n) => /^landing-.*\.png$/.test(n))) {
    const file = path.join(OUT, f);
    const r = require('child_process').spawnSync('convert', [file, '-dither', 'FloydSteinberg', '-colors', '256', '-define', 'png:compression-level=9', 'PNG8:' + file + '.tmp']);
    if (r.status === 0 && fs.existsSync(file + '.tmp')) fs.renameSync(file + '.tmp', file);
  }
  check(ERRORS.length === 0, 'no console errors / failed requests', ERRORS.slice(0, 10).join(' | '));
  console.log(results.join('\n'));
  for (const f of fs.readdirSync(OUT)) console.log('  ' + f + ' ' + Math.round(fs.statSync(path.join(OUT, f)).size / 1024) + ' KB');
  console.log(failed ? `\n${failed} FAILED` : '\nALL PASS');
  process.exit(failed ? 1 : 0);
})();
