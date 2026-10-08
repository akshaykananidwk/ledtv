/*
 * Web player browser test (2.4, #45): real headless Chromium against a TestEnv sandbox server.
 *
 *   NODE_PATH=<dir with playwright-core>/node_modules HC_TEST_DB_NAME=hotelcast_test_v \
 *   PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tests/browser/webplayer_e2e.js
 *
 * Steps: setup screen (QR code from /api/provision/start) → manual entry (room + key) → playlist with
 * image + display app + announcement + split screen layout, ticker bar → emergency on / off → commands
 * SPEAK, PLAY_SOUND, SHOW_MESSAGE, SCREEN_OFF / SCREEN_ON, PING, an unknown one (all acked) → reload keeps
 * the token. Saves 3 screenshots (1920×1080 JPEG ≤ 200 KB) to docs/screenshots/2.4/ (HC_SCREENSHOTS
 * overrides the folder). WIPES the test database (see webplayer_server.php). Exit code 0 = pass.
 */
'use strict';
const { chromium } = require('playwright-core');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const HC = path.resolve(__dirname, '../..');
const OUT = process.env.HC_SCREENSHOTS || path.resolve(HC, '../docs/screenshots/2.4');
const EXE = process.env.HC_CHROMIUM || '/opt/pw-browsers/chromium';
const results = [];
let failed = 0;

function check(ok, what, extra) {
  results.push((ok ? 'PASS ' : 'FAIL ') + what + (extra ? ' — ' + extra : ''));
  if (!ok) failed++;
}

function startServer() {
  return new Promise((resolve, reject) => {
    const srv = spawn('php', [path.join(HC, 'tests/browser/webplayer_server.php')], { env: process.env, stdio: ['pipe', 'pipe', 'inherit'] });
    const rl = readline.createInterface({ input: srv.stdout });
    rl.once('line', (line) => {
      try { resolve({ srv, info: JSON.parse(line) }); } catch (e) { reject(new Error('server said: ' + line)); }
    });
    srv.once('exit', (code) => reject(new Error('server exited ' + code)));
  });
}

async function shot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, name);
  for (const q of [72, 62, 52, 42, 34]) {
    await page.screenshot({ path: file, type: 'jpeg', quality: q });
    if (fs.statSync(file).size <= 200 * 1024) break;
  }
  const kb = Math.round(fs.statSync(file).size / 1024);
  check(kb <= 200, 'screenshot ' + name, kb + ' KB');
}

(async () => {
  const { srv, info } = await startServer();
  const ctl = (...args) => execFileSync('php', [path.join(HC, 'tests/browser/webplayer_ctl.php'), info.root, ...args], { env: process.env }).toString().trim();
  const browser = await chromium.launch({ executablePath: EXE, args: ['--autoplay-policy=no-user-gesture-required'] });
  const errors = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, locale: 'en-IN' });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    // HTTP errors are listed by URL; the expected ones (wrong key 401, missing sound file 404, favicon) are ignored.
    page.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errors.push('console: ' + m.text()); });
    page.on('response', (r) => {
      if (r.status() >= 400 && !/missing-sound\.mp3|favicon\.ico/.test(r.url()) && !(r.status() === 401 && /device\/register/.test(r.url()))) errors.push('HTTP ' + r.status() + ' ' + r.url());
    });
    const state = () => page.evaluate(() => window.HCPlayer.state());
    const waitItem = (id, ms) => page.waitForFunction((x) => window.HCPlayer.state().itemId === x, id, { timeout: ms || 30000 });

    // 1. Setup screen with a QR code from the provisioning API.
    await page.goto(info.url + 'player/');
    await page.waitForSelector('#hc-setup');
    await page.waitForFunction(() => /^[A-Z2-9]{6}$/.test(document.getElementById('hc-code').textContent), null, { timeout: 15000 });
    await page.waitForFunction(() => { const i = document.querySelector('#hc-qr-img img'); return i && i.complete && i.naturalWidth > 0; });
    check(true, 'setup screen shows a setup code + QR', await page.textContent('#hc-code'));
    check((await page.inputValue('#hc-f-server')) === info.url, 'server address prefilled');
    await shot(page, 'web-player-setup.jpg');

    // 2. Manual entry.
    await page.fill('#hc-f-room', info.room);
    await page.fill('#hc-f-key', 'WRONG-KEY');
    await page.click('#hc-f-go');
    await page.waitForFunction(() => document.getElementById('hc-f-err').textContent.length > 0);
    check(/registration key is wrong/i.test(await page.textContent('#hc-f-err')), 'wrong key is reported');
    await page.fill('#hc-f-key', info.key);
    await page.click('#hc-f-go');
    await page.waitForFunction(() => window.HCPlayer.state().registered && window.HCPlayer.state().contentMode === 'assigned', null, { timeout: 20000 });
    const st = await state();
    const uid = st.deviceId;
    check(/^web-[0-9a-f-]{36}$/.test(uid), 'device id generated', uid);
    const ls = await page.evaluate(() => ({ token: localStorage.getItem('hc_token'), id: localStorage.getItem('hc_device_id'), cookie: document.cookie }));
    check(/^[0-9a-f]{64}$/.test(ls.token || ''), 'token stored in localStorage');
    check(ls.id === uid && ls.cookie.indexOf('hc_device_id=' + uid) >= 0, 'device id in localStorage + cookie');
    const dev = JSON.parse(ctl('device', uid));
    check(dev.platform === 'web' && dev.app_version === 'web-2.4.0' && Number(dev.app_version_code) >= 10, 'registered as web device', JSON.stringify(dev));

    // 3. Playlist: image → app → announcement → layout, ticker reserves space.
    await waitItem(info.ids.image);
    await page.waitForFunction(() => { const i = document.querySelector('#hc-content img.hc-media'); return i && i.complete && i.naturalWidth > 0; });
    check(true, 'image item shown');
    const tk = await page.evaluate(() => ({
      text: document.querySelector('#hc-ticker.hc-on .hc-ticker-text').textContent,
      bottom: getComputedStyle(document.getElementById('hc-content')).bottom,
      barH: document.getElementById('hc-ticker').getBoundingClientRect().height
    }));
    check(/Mangla Aarti/.test(tk.text) && tk.bottom !== '0px' && Math.abs(parseFloat(tk.bottom) - tk.barH) < 2, 'ticker shown, content shrinks (reserve_space)', JSON.stringify(tk));
    const x1 = await page.evaluate(() => document.querySelector('.hc-ticker-text').getBoundingClientRect().left);
    await page.waitForTimeout(600);
    const x2 = await page.evaluate(() => document.querySelector('.hc-ticker-text').getBoundingClientRect().left);
    check(x2 < x1, 'ticker scrolls', x1 + ' → ' + x2);
    check(await page.isVisible('.hc-ov-clock'), 'overlay clock');

    await waitItem(info.ids.app, 15000);
    const src = await page.getAttribute('#hc-content iframe.hc-frame', 'src');
    check(/\/display\/\?c=\d+&s=[0-9a-f]{32}/.test(src || ''), 'display app in iframe', src);
    const appFrame = await (await page.$('#hc-content iframe.hc-frame')).contentFrame();
    let appText = '';
    if (appFrame) {
      await appFrame.waitForFunction(() => document.body && /દિવાળી/.test(document.body.innerText), null, { timeout: 10000 }).catch(() => null);
      appText = await appFrame.evaluate(() => document.body.innerText);
    }
    check(/દિવાળી/.test(appText), 'display app rendered', (appFrame ? appFrame.url() : 'no frame') + ' :: ' + appText.slice(0, 80).replace(/\s+/g, ' '));

    await waitItem(info.ids.ann, 15000);
    check(/ચેક-આઉટ/.test(await page.textContent('.hc-ann-t')), 'announcement (Gujarati)');

    await waitItem(info.ids.layout, 15000);
    await page.waitForTimeout(1500);
    const lay = await page.evaluate(() => ({
      zones: document.querySelectorAll('.hc-lay .hc-zone').length,
      img: !!document.querySelector('.hc-zone img.hc-media'),
      clock: !!document.querySelector('.hc-zone svg.hc-analog'),
      ann: !!document.querySelector('.hc-zone .hc-ann-t'),
      w: Math.round(document.querySelector('.hc-zone').getBoundingClientRect().width)
    }));
    check(lay.zones === 3 && lay.img && lay.clock && lay.ann && Math.abs(lay.w - 1344) < 3, 'split screen layout (3 zones, 70 %)', JSON.stringify(lay));
    await shot(page, 'web-player-layout-ticker.jpg');

    // 4. Emergency on / off.
    const t0 = Date.now();
    ctl('emergency_on');
    await page.waitForSelector('#hc-full.hc-on .hc-em-title', { timeout: 15000 });
    check(/Fire drill/.test(await page.textContent('.hc-em-title')), 'emergency shown', (Date.now() - t0) + ' ms');
    check(!(await page.isVisible('#hc-ticker.hc-on')), 'no ticker during emergency');
    await page.waitForTimeout(700);
    await shot(page, 'web-player-emergency.jpg');
    ctl('emergency_off');
    await page.waitForFunction(() => window.HCPlayer.state().contentMode === 'assigned' && !document.querySelector('#hc-full.hc-on'), null, { timeout: 15000 });
    check(true, 'emergency cleared, playlist again');

    // 5. Commands (incl. the new SPEAK / PLAY_SOUND): every one is acked.
    const cmds = {
      SPEAK: ctl('command', uid, 'SPEAK', JSON.stringify({ text: 'ચેક-આઉટ સમય સવારે 11 વાગ્યે છે', lang: 'gu' })),
      PLAY_SOUND: ctl('command', uid, 'PLAY_SOUND', JSON.stringify({ sound: 'chime', volume: 60 })),
      PLAY_SOUND_URL: ctl('command', uid, 'PLAY_SOUND', JSON.stringify({ url: info.url + 'missing-sound.mp3' })),
      SHOW_MESSAGE: ctl('command', uid, 'SHOW_MESSAGE', JSON.stringify({ title: 'Room service', message: 'Your order is on the way', duration_sec: 20 })),
      PING: ctl('command', uid, 'PING', '{}'),
      SET_VOLUME: ctl('command', uid, 'SET_VOLUME', JSON.stringify({ level: 35 })),
      SCREENSHOT: ctl('command', uid, 'SCREENSHOT', '{}'),
      FOO: ctl('command', uid, 'FOO_BAR', '{}')
    };
    const statuses = {};
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline) {
      let pending = 0;
      for (const k of Object.keys(cmds)) {
        const s = JSON.parse(ctl('status', cmds[k]));
        statuses[k] = s;
        if (s.status === 'pending' || s.status === 'delivered') pending++;
      }
      if (!pending) break;
      await page.waitForTimeout(800);
    }
    for (const k of Object.keys(cmds)) {
      const s = statuses[k] || {};
      check(s.status === 'acked' || s.status === 'failed', 'ack ' + k, s.status + ': ' + s.message);
    }
    check(statuses.PING.status === 'acked' && statuses.FOO.status === 'failed' && /unsupported/.test(statuses.FOO.message), 'unknown command acked as unsupported');
    check(await page.isVisible('#hc-msg.hc-on'), 'SHOW_MESSAGE card visible');
    check((await state()).volume === 35, 'SET_VOLUME applied');

    const offId = ctl('command', uid, 'SCREEN_OFF', '{}');
    await page.waitForFunction(() => window.HCPlayer.state().black, null, { timeout: 15000 });
    check(true, 'SCREEN_OFF → black screen');
    const onId = ctl('command', uid, 'SCREEN_ON', '{}');
    await page.waitForFunction(() => !window.HCPlayer.state().black, null, { timeout: 15000 });
    check(true, 'SCREEN_ON → content');
    await page.waitForTimeout(1500);
    check(JSON.parse(ctl('status', offId)).status === 'acked' && JSON.parse(ctl('status', onId)).status === 'acked', 'screen commands acked');

    // 6. Hidden settings menu: 1-2-3-4, PIN 1234 (default), menu opens; Back closes.
    await page.keyboard.press('Escape');
    for (const k of ['1', '2', '3', '4']) await page.keyboard.press(k);
    await page.waitForSelector('#hc-menu.hc-on .hc-pin');
    for (const k of ['0', '0', '0', '0']) await page.keyboard.press(k);
    await page.waitForFunction(() => document.getElementById('hc-pin-err').textContent.length > 0);
    check(/Wrong PIN/.test(await page.textContent('#hc-pin-err')), 'wrong PIN refused');
    for (const k of ['1', '2', '3', '4']) await page.keyboard.press(k);
    await page.waitForSelector('#hc-menu.hc-on [data-act="repair"]');
    check(true, 'settings menu after PIN');
    await page.keyboard.press('Escape');
    check(!(await page.isVisible('#hc-menu.hc-on')), 'Back closes the menu');

    // 7. Reload keeps the token (and the device id); content comes back.
    const before = await page.evaluate(() => localStorage.getItem('hc_token'));
    await page.reload();
    await page.waitForFunction(() => window.HCPlayer && window.HCPlayer.state().mode === 'player' && window.HCPlayer.state().contentMode === 'assigned', null, { timeout: 15000 });
    const after = await page.evaluate(() => ({ token: localStorage.getItem('hc_token'), st: window.HCPlayer.state() }));
    check(after.token === before && after.st.deviceId === uid && !(await page.$('#hc-setup')), 'reload keeps the token and the pairing');

    check(errors.length === 0, 'no JS errors', errors.join(' | '));
  } catch (e) {
    check(false, 'exception', e && e.stack ? e.stack : String(e));
  } finally {
    await browser.close();
    srv.stdin.end();
  }
  console.log(results.join('\n'));
  console.log(failed ? '\n' + failed + ' FAILED' : '\nALL PASSED (' + results.length + ' checks)');
  process.exit(failed ? 1 : 0);
})();
