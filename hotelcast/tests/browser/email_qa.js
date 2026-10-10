/*
 * 2.8 email browser QA (docs/modules/email.md): headless Chromium against tests/browser/email_server.php.
 *
 *   NODE_PATH=/opt/node-tools/node_modules HC_TEST_DB_NAME=hotelcast_test_z \
 *   PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tests/browser/email_qa.js
 *
 * Login page with "Forgot password?", the forgot form + neutral answer, the reset form (EN / GU), the
 * Super Admin → Platform settings → Email (SMTP) card + log, and a rendered reset e-mail. Screenshots
 * (JPEG ≤ 200 KB) go to docs/screenshots/2.8/ (HC_SCREENSHOTS overrides). Exit code 0 = pass.
 */
'use strict';
const { chromium } = require('playwright-core');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const HC = path.resolve(__dirname, '../..');
const OUT = process.env.HC_SCREENSHOTS || path.resolve(HC, '../docs/screenshots/2.8');
const EXE = process.env.HC_CHROMIUM || '/opt/pw-browsers/chromium';
const results = [];
const ERRORS = [];
let failed = 0;
function check(ok, what, extra) { results.push((ok ? 'PASS ' : 'FAIL ') + what + (extra ? ' — ' + extra : '')); if (!ok) failed++; }

function startServer() {
  return new Promise((resolve, reject) => {
    const srv = spawn('php', [path.join(HC, 'tests/browser/email_server.php')], { env: process.env, stdio: ['pipe', 'pipe', 'inherit'] });
    const rl = readline.createInterface({ input: srv.stdout });
    rl.once('line', (line) => { try { resolve({ srv, info: JSON.parse(line) }); } catch (e) { reject(new Error('server said: ' + line)); } });
    srv.once('exit', (code) => reject(new Error('server exited ' + code)));
  });
}
async function shot(page, name, full) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, name);
  for (const q of [76, 66, 56, 46, 36]) {
    await page.screenshot({ path: file, type: 'jpeg', quality: q, fullPage: !!full });
    if (fs.statSync(file).size <= 200 * 1024) break;
  }
  check(fs.statSync(file).size <= 200 * 1024, 'screenshot ' + name, Math.round(fs.statSync(file).size / 1024) + ' KB');
}
async function ctx(browser, viewport) {
  const context = await browser.newContext({ viewport: viewport || { width: 1280, height: 860 } });
  context.on('page', (p) => {
    p.on('pageerror', (e) => ERRORS.push('pageerror: ' + e.message));
    p.on('response', (r) => { if (r.status() >= 400 && r.status() !== 429 && !/favicon\.ico|fonts\.g/.test(r.url())) ERRORS.push('HTTP ' + r.status() + ' ' + r.url()); });
  });
  return context;
}

(async () => {
  const { srv, info } = await startServer();
  const browser = await chromium.launch({ executablePath: EXE });
  try {
    const mobile = { width: 420, height: 860 };
    // ---- Login with "Forgot password?" (EN, GU)
    let c = await ctx(browser, mobile);
    let page = await c.newPage();
    await page.goto(info.url + 'admin/login.php?lang=en');
    check(await page.isVisible('[data-forgot-link]'), 'login has "Forgot password?"');
    check((await page.textContent('label[for=username]')).trim() === 'Email or username', 'login label "Email or username"');
    await shot(page, 'login-forgot-en.jpg');
    await page.goto(info.url + 'admin/login.php?lang=gu');
    check((await page.textContent('[data-forgot-link]')).includes('પાસવર્ડ ભૂલી ગયા'), 'Gujarati link text');
    await shot(page, 'login-forgot-gu.jpg');
    // ---- Forgot form + neutral answer
    await Promise.all([page.waitForNavigation(), page.click('[data-forgot-link]')]);
    check(/forgot_password\.php/.test(page.url()), 'link opens the forgot page');
    await shot(page, 'forgot-form-gu.jpg');
    await page.goto(info.url + 'admin/forgot_password.php?lang=en');
    await shot(page, 'forgot-form-en.jpg');
    await page.fill('#login', 'nobody@example.test');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(await page.isVisible('[data-pwreset-sent]'), 'neutral "if an account exists" answer');
    await shot(page, 'forgot-sent-en.jpg');
    await c.close();
    // ---- Reset form (EN, GU user language)
    for (const lang of ['en', 'gu']) {
      c = await ctx(browser, mobile);
      page = await c.newPage();
      await page.goto(info.url + 'admin/reset_password.php?token=' + info.tokens[lang]);
      check(!/token=/.test(page.url()), 'token removed from the address bar (' + lang + ')');
      check(await page.isVisible('#password_confirm'), 'reset form shown (' + lang + ')');
      await shot(page, 'reset-form-' + lang + '.jpg');
      await c.close();
    }
    // ---- Super Admin → Platform settings → Email (SMTP)
    for (const lang of ['en', 'gu']) {
      c = await ctx(browser, { width: 1400, height: 900 });
      page = await c.newPage();
      await page.goto(info.url + 'admin/login.php?lang=' + lang);
      await page.fill('input[name=username]', 'owner@krishnacloud.test');
      await page.fill('input[name=password]', 'Passw0rd!');
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
      check(/platform_overview\.php/.test(page.url()), 'Super Admin logs in with the email address (' + lang + ')', page.url());
      if (lang === 'gu') {
        await page.click('.hc-topbar .dropdown button[title]');
        await Promise.all([page.waitForNavigation(), page.click('.js-lang[data-lang="gu"]')]);
      }
      await page.goto(info.url + 'admin/platform_settings.php?tab=email');
      check(await page.isVisible('[data-smtp-card]'), 'SMTP card (' + lang + ')');
      check(await page.inputValue('#m_pw') === '', 'saved password not echoed (' + lang + ')');
      check(await page.isVisible('[data-mail-log]'), 'email log (' + lang + ')');
      await shot(page, 'smtp-settings-' + lang + '.jpg', true);
      if (lang === 'en') {
        // Test email to an unreachable server shows the exact error.
        await page.fill('#m_h', '127.0.0.1');
        await page.fill('#m_p', '1');
        await page.selectOption('#m_enc', 'none');
        await page.fill('#m_to', 'owner@krishnacloud.test');
        await Promise.all([page.waitForNavigation(), page.click('[data-mail-test-btn]')]);
        check(await page.isVisible('[data-mail-test="failed"]'), 'test email failure shown');
        check((await page.textContent('[data-mail-error]')).includes('SMTP connect to 127.0.0.1:1 failed'), 'exact SMTP error');
        await page.click('[data-mail-test="failed"] summary');
        await shot(page, 'smtp-test-error-en.jpg');
      }
      await c.close();
    }
    // ---- Rendered reset e-mail
    for (const lang of ['en', 'gu']) {
      c = await ctx(browser, { width: 760, height: 900 });
      page = await c.newPage();
      await page.goto(info.url + 'email_preview_' + lang + '.html');
      await shot(page, 'email-reset-' + lang + '.jpg', true);
      await c.close();
    }
  } catch (e) {
    check(false, 'run', e.stack);
  } finally {
    await browser.close();
    srv.stdin.end();
  }
  for (const e of ERRORS) { check(false, 'browser error', e); }
  console.log(results.join('\n'));
  console.log(failed ? `\n${failed} FAILED` : '\nALL PASSED');
  process.exit(failed ? 1 : 0);
})();
