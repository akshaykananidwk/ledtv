/*!
 * Krishna Cloud TV Management — web player 2.5 (HotelCast #45).
 * Plain ES5 (old Samsung Tizen / LG webOS browsers), no build step, no dependencies
 * (hls.js is loaded from assets/vendor only when an HLS stream needs it).
 *
 * Behaves like the Android TV app: setup (QR claim or manual) → POST /api/device/register (device type
 * "web") → long-poll GET /api/device/command/{id} → render the Content object → run + ack commands →
 * heartbeat. The token, the device id and the last content stay in localStorage (device id also in a
 * cookie), so a reload or a power cut keeps the pairing and shows the last content while offline.
 * See docs/modules/web_player.md.
 */
(function (window, document) {
  'use strict';

  // =================================================================== config, i18n, helpers
  var CFG = {};
  try { CFG = JSON.parse((document.getElementById('hc-config') || {}).textContent || '{}') || {}; } catch (e0) { CFG = {}; }
  var VERSION = CFG.version || '2.4.0';
  var VCODE = CFG.versionCode || 11;
  var API = CFG.api || '../api/index.php?r=';
  var DICT = CFG.strings || {};
  var ROOT = document.getElementById('hc-root') || document.body;
  var BOOT_AT = now();
  var DEFAULT_ITEM_SEC = 10;

  function now() { return new Date().getTime(); }
  function iso(ms) { return new Date(ms || now()).toISOString(); }
  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html !== undefined && html !== null) n.innerHTML = html;
    return n;
  }
  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function color(c, d) { return /^#[0-9a-fA-F]{6}$/.test(String(c)) ? String(c) : d; }
  function num(v, d) { v = Number(v); return isFinite(v) ? v : d; }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function toArr(list) { return Array.prototype.slice.call(list || []); }
  function each(list, fn) { for (var i = 0; i < list.length; i++) fn(list[i], i); }
  function on(t, ev, fn, opt) { if (t && t.addEventListener) t.addEventListener(ev, fn, opt || false); }
  function trim(s) { return String(s === undefined || s === null ? '' : s).replace(/^\s+|\s+$/g, ''); }
  function query(name, src) {
    var m = new RegExp('[?&#]' + name + '=([^&#]*)').exec(src === undefined ? window.location.search : src);
    if (!m) return null;
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  }
  function remove(n) { if (n && n.parentNode) n.parentNode.removeChild(n); }
  function setStyle(n, prop, val) {
    n.style[prop] = val;
    var cap = prop.charAt(0).toUpperCase() + prop.slice(1);
    n.style['webkit' + cap] = val;
  }

  // Small in-memory log (UPLOAD_LOGS command).
  var LOG = [];
  function log(msg) {
    LOG.push(iso() + ' ' + msg);
    if (LOG.length > 400) LOG.shift();
    try { if (window.console && window.console.log) window.console.log('[player] ' + msg); } catch (e) { /* ignore */ }
  }
  on(window, 'error', function (e) { log('JS error: ' + (e && e.message) + ' @' + (e && e.lineno)); });

  // =================================================================== storage (localStorage, cookie fallback)
  function cookieGet(k) {
    var m = new RegExp('(?:^|; )' + k + '=([^;]*)').exec(document.cookie || '');
    if (!m) return null;
    try { return decodeURIComponent(m[1]); } catch (e) { return null; }
  }
  function cookieSet(k, v) {
    if (String(v).length > 3000) return;
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = k + '=' + encodeURIComponent(v) + '; path=/; max-age=315360000; SameSite=Lax' + secure;
  }
  function cookieDel(k) { document.cookie = k + '=; path=/; max-age=0'; }
  var store = {
    get: function (k) {
      var v = null;
      try { v = window.localStorage.getItem(k); } catch (e) { v = null; }
      return v === null || v === undefined ? cookieGet(k) : v;
    },
    /** cookieToo: small identity values (device id, token) are mirrored in a cookie. */
    set: function (k, v, cookieToo) {
      var ok = false;
      try { window.localStorage.setItem(k, v); ok = true; } catch (e) { ok = false; }
      if (cookieToo || (!ok && String(v).length <= 3000)) cookieSet(k, v);
      return ok;
    },
    del: function (k) {
      try { window.localStorage.removeItem(k); } catch (e) { /* ignore */ }
      if (cookieGet(k) !== null) cookieDel(k);
    }
  };

  // =================================================================== language
  var LANG = (function () {
    var l = store.get('hc_lang') || query('lang') || CFG.lang || 'en';
    return DICT[l] ? l : 'en';
  })();
  function t(key, rep) {
    var s = (DICT[LANG] && DICT[LANG][key]) || key;
    if (rep) {
      for (var k in rep) {
        if (Object.prototype.hasOwnProperty.call(rep, k)) s = s.split(':' + k).join(String(rep[k]));
      }
    }
    return s;
  }

  // =================================================================== identity & platform
  function uuid() {
    var b = [], i, c = window.crypto || window.msCrypto;
    if (c && c.getRandomValues && window.Uint8Array) {
      var a = new Uint8Array(16);
      c.getRandomValues(a);
      for (i = 0; i < 16; i++) b.push(a[i]);
    } else {
      for (i = 0; i < 16; i++) b.push(Math.floor(Math.random() * 256));
    }
    b[6] = (b[6] & 15) | 64;
    b[8] = (b[8] & 63) | 128;
    var h = '';
    for (i = 0; i < 16; i++) {
      h += (b[i] < 16 ? '0' : '') + b[i].toString(16);
      if (i === 3 || i === 5 || i === 7 || i === 9) h += '-';
    }
    return h;
  }
  var UA = navigator.userAgent || '';
  function osName() {
    var m;
    if (/Tizen/i.test(UA)) { m = /Tizen\s*([\d.]+)/i.exec(UA); return 'Tizen' + (m ? ' ' + m[1] : ''); }
    if (/Web0S|webOS/i.test(UA)) return 'webOS';
    if (/AFT[A-Z]|Silk\//.test(UA)) return 'Fire OS';
    if (/CrOS/.test(UA)) return 'ChromeOS';
    if ((m = /Android\s*([\d.]+)/.exec(UA))) return 'Android ' + m[1];
    if (/Windows NT/.test(UA)) return 'Windows';
    if (/iPhone|iPad|iPod/.test(UA)) return 'iOS';
    if (/Mac OS X/.test(UA)) return 'macOS';
    if (/Linux/.test(UA)) return /aarch64|armv7|armv8|arm/i.test(UA) ? 'Linux ARM' : 'Linux';
    return 'Web';
  }
  function browserName() {
    var rules = [[/SamsungBrowser\/([\d.]+)/, 'Samsung Internet'], [/Silk\/([\d.]+)/, 'Silk'], [/Edg\/([\d.]+)/, 'Edge'],
      [/OPR\/([\d.]+)/, 'Opera'], [/Firefox\/([\d.]+)/, 'Firefox'], [/Chromium\/([\d.]+)/, 'Chromium'],
      [/Chrome\/([\d.]+)/, 'Chrome'], [/Version\/([\d.]+).*Safari/, 'Safari']];
    for (var i = 0; i < rules.length; i++) {
      var m = rules[i][0].exec(UA);
      if (m) return rules[i][1] + ' ' + m[1].split('.')[0];
    }
    return 'Browser';
  }
  var OS = osName();
  var MODEL = ('Web player · ' + browserName() + ' · ' + OS).slice(0, 100);

  // =================================================================== state
  var S = {
    deviceId: null, token: store.get('hc_token'), room: store.get('hc_room') || '',
    pinHash: store.get('hc_pin') || '', pollInterval: 8, hbInterval: 60,
    content: null, hash: '', localOff: false, override: false,
    volume: clamp(num(store.get('hc_volume'), 100), 0, 100), muted: store.get('hc_muted') === '1',
    soundOk: null, gestured: false, online: true, failures: 0,
    polling: false, pollSeq: 0, pollXhr: null, pollTimer: null, hbTimer: null,
    currentItemId: null, played: [], done: [], mode: 'boot', main: null, cec: null
  };
  (function () {
    var id = store.get('hc_device_id');
    if (!/^[A-Za-z0-9-]{8,64}$/.test(id || '')) id = 'web-' + uuid();
    S.deviceId = id;
    store.set('hc_device_id', id, true);
    try { S.done = JSON.parse(store.get('hc_done') || '[]') || []; } catch (e) { S.done = []; }
    var cec = query('cec');
    if (cec !== null) {
      if (/^\d{2,5}$/.test(cec)) store.set('hc_cec', cec); else store.del('hc_cec');
    }
    S.cec = store.get('hc_cec');
  })();

  // =================================================================== HTTP
  /** cb(status, json, xhr); status 0 = network error / timeout. */
  function api(method, route, qs, body, cb, timeoutMs) {
    var xhr = new XMLHttpRequest();
    var url = API + encodeURIComponent(route).replace(/%2F/g, '/') + (qs ? '&' + qs : '');
    var finished = false;
    function finish(status) {
      if (finished) return;
      finished = true;
      var j = null;
      try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
      if (cb) cb(status, j, xhr);
    }
    try {
      xhr.open(method, url, true);
      xhr.timeout = timeoutMs || 20000;
      xhr.setRequestHeader('Accept', 'application/json');
      if (body !== null && body !== undefined && !(window.FormData && body instanceof window.FormData)) xhr.setRequestHeader('Content-Type', 'application/json');
      if (S.token) {
        xhr.setRequestHeader('Authorization', 'Bearer ' + S.token);
        xhr.setRequestHeader('X-Device-Id', S.deviceId);
      }
      xhr.onreadystatechange = function () { if (xhr.readyState === 4) finish(xhr.status); };
      xhr.ontimeout = function () { finish(0); };
      xhr.onerror = function () { finish(0); };
      xhr.send(body === null || body === undefined ? null : (window.FormData && body instanceof window.FormData ? body : JSON.stringify(body)));
    } catch (e) {
      log('HTTP ' + route + ' failed: ' + e.message);
      setTimeout(function () { finish(0); }, 0);
    }
    return xhr;
  }
  function retryAfter(xhr, d) {
    var r = 0;
    try { r = parseInt(xhr.getResponseHeader('Retry-After'), 10); } catch (e) { r = 0; }
    return r > 0 ? r * 1000 : d;
  }
  function errCode(j) { return j && j.error && j.error.code ? j.error.code : ''; }

  // =================================================================== SHA-256 (settings PIN; crypto.subtle needs https)
  function sha256(str) {
    var ascii = unescape(encodeURIComponent(str));
    function rr(v, a) { return (v >>> a) | (v << (32 - a)); }
    var maxWord = Math.pow(2, 32), i, j, result = '', words = [], bitLen = ascii.length * 8;
    var hash = [], k = [], primes = 0, composite = {};
    for (var cand = 2; primes < 64; cand++) {
      if (!composite[cand]) {
        for (i = 0; i < 313; i += cand) composite[i] = cand;
        hash[primes] = (Math.pow(cand, 0.5) * maxWord) | 0;
        k[primes++] = (Math.pow(cand, 1 / 3) * maxWord) | 0;
      }
    }
    ascii += '\x80';
    while (ascii.length % 64 - 56) ascii += '\x00';
    for (i = 0; i < ascii.length; i++) {
      j = ascii.charCodeAt(i);
      words[i >> 2] |= j << ((3 - i) % 4) * 8;
    }
    words[words.length] = (bitLen / maxWord) | 0;
    words[words.length] = bitLen;
    for (j = 0; j < words.length;) {
      var w = words.slice(j, j += 16), old = hash;
      hash = hash.slice(0, 8);
      for (i = 0; i < 64; i++) {
        var w15 = w[i - 15], w2 = w[i - 2], a = hash[0], e = hash[4];
        var t1 = hash[7] + (rr(e, 6) ^ rr(e, 11) ^ rr(e, 25)) + ((e & hash[5]) ^ ((~e) & hash[6])) + k[i] +
          (w[i] = (i < 16) ? w[i] : (w[i - 16] + (rr(w15, 7) ^ rr(w15, 18) ^ (w15 >>> 3)) + w[i - 7] + (rr(w2, 17) ^ rr(w2, 19) ^ (w2 >>> 10))) | 0);
        var t2 = (rr(a, 2) ^ rr(a, 13) ^ rr(a, 22)) + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
        hash = [(t1 + t2) | 0].concat(hash);
        hash[4] = (hash[4] + t1) | 0;
      }
      for (i = 0; i < 8; i++) hash[i] = (hash[i] + old[i]) | 0;
    }
    for (i = 0; i < 8; i++) {
      for (j = 3; j + 1; j--) {
        var b = (hash[i] >> (j * 8)) & 255;
        result += (b < 16 ? '0' : '') + b.toString(16);
      }
    }
    return result;
  }

  // =================================================================== screen scale
  // The TV app works in dp on a 960 dp wide screen. 1 dp = UNIT px; the stage font-size is 5 dp, so
  // 1 dp = 0.2em and every size below is in em (a resize only changes the stage font-size).
  var UNIT = 1;
  var DP = 0.2;
  function measure() {
    var w = window.innerWidth || document.documentElement.clientWidth || 1280;
    var h = window.innerHeight || document.documentElement.clientHeight || 720;
    UNIT = Math.max(0.2, Math.min(w, h * 16 / 9) / 960);
    var stage = document.getElementById('hc-stage');
    if (stage) stage.style.fontSize = (UNIT * 5) + 'px';
  }
  on(window, 'resize', measure);

  // =================================================================== shell (setup / player)
  function clearRoot() {
    stopPlayback();
    ROOT.innerHTML = '';
  }

  // ------------------------------------------------------------------- setup screen
  var prov = null;
  function showSetup(note) {
    S.mode = 'setup';
    clearRoot();
    stopLoops();
    var server = trim(store.get('hc_server') || pageRoot());
    var html = '<div class="hc-setup" id="hc-setup">' +
      '<div class="hc-setup-head">' + (CFG.logo ? '<img alt="" src="' + esc(CFG.logo) + '">' : '') +
      '<div><div class="hc-product">' + esc(CFG.product || 'Krishna Cloud TV Management') + '</div><h1>' + esc(t('Set up this screen')) + '</h1></div></div>' +
      (note ? '<div class="hc-note">' + esc(note) + '</div>' : '') +
      '<div class="hc-setup-cols">' +
      '<div class="hc-card hc-qr"><h2>' + esc(t('Scan with your phone')) + '</h2>' +
      '<div class="hc-qr-img" id="hc-qr-img"><div class="hc-spinner"></div></div>' +
      '<div class="hc-code-label">' + esc(t('Setup code')) + '</div><div class="hc-code" id="hc-code">······</div>' +
      '<p>' + esc(t('Open the camera on your phone, scan the code and choose the screen. This screen starts by itself.')) + '</p>' +
      '<div class="hc-status" id="hc-qr-status">' + esc(t('Getting a setup code…')) + '</div></div>' +
      '<form class="hc-card hc-manual" id="hc-form" autocomplete="off"><h2>' + esc(t('Or enter the details')) + '</h2>' +
      '<label>' + esc(t('Server address')) + '<input id="hc-f-server" type="url" inputmode="url" spellcheck="false" value="' + esc(server) + '"></label>' +
      '<label>' + esc(t('Screen name / ID')) + '<input id="hc-f-room" type="text" maxlength="20" spellcheck="false" value="' + esc(query('room') || S.room || '') + '"></label>' +
      '<label>' + esc(t('Registration key')) + '<input id="hc-f-key" type="text" maxlength="64" spellcheck="false" autocapitalize="off"></label>' +
      '<div class="hc-help">' + esc(t('Find the registration key in Admin → Settings → Devices.')) + '</div>' +
      '<button type="submit" id="hc-f-go">' + esc(t('Connect')) + '</button>' +
      '<div class="hc-err" id="hc-f-err" role="alert"></div></form></div>' +
      '<div class="hc-setup-foot">' + esc(t('Device ID')) + ': ' + esc(S.deviceId) + ' · web-' + esc(VERSION) + ' · ' + esc(OS) + '</div></div>';
    ROOT.innerHTML = html;
    on(document.getElementById('hc-form'), 'submit', function (e) {
      e.preventDefault();
      manualConnect();
    });
    provStart();
    // Setup values handed over in the URL fragment (manual setup redirected from another server's player).
    var hroom = query('room', window.location.hash), hkey = query('key', window.location.hash);
    if (hroom !== null && hkey !== null) {
      try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) { window.location.hash = ''; }
      document.getElementById('hc-f-room').value = hroom;
      document.getElementById('hc-f-key').value = hkey;
      manualConnect();
    }
  }
  function pageRoot() {
    var a = document.createElement('a');
    a.href = '../';
    return a.href;
  }
  function normServer(u) {
    u = trim(u);
    if (u === '') return '';
    if (!/^https?:\/\//i.test(u)) u = 'http://' + u;
    u = u.replace(/\/+$/, '').replace(/\/(player|admin|api)(\/.*)?$/i, '');
    return u.toLowerCase() + '/';
  }
  function setupError(msg) {
    var e = document.getElementById('hc-f-err');
    if (e) e.textContent = msg || '';
    var b = document.getElementById('hc-f-go');
    if (b) { b.disabled = false; b.textContent = t('Connect'); }
  }
  function manualConnect() {
    var server = normServer(document.getElementById('hc-f-server').value);
    var room = trim(document.getElementById('hc-f-room').value);
    var key = trim(document.getElementById('hc-f-key').value);
    if (room === '' || key === '') { setupError(t('Please fill in the screen name / ID and the registration key.')); return; }
    if (server !== '' && server !== normServer(pageRoot())) {
      // Another server: open that server's web player (same origin as its API, no CORS needed) and hand
      // over the values in the fragment, which is never sent over the network.
      store.set('hc_server', server);
      window.location.href = server + 'player/#room=' + encodeURIComponent(room) + '&key=' + encodeURIComponent(key);
      return;
    }
    var b = document.getElementById('hc-f-go');
    b.disabled = true;
    b.textContent = t('Connecting…');
    setupError('');
    b.disabled = true;
    register(room, key, setupError);
  }

  function provStart() {
    if (S.mode !== 'setup') return;
    clearTimeout(S.provTimer);
    api('POST', 'provision/start', null, { device_id: S.deviceId, model: MODEL, app_version: 'web-' + VERSION }, function (st, j, xhr) {
      if (S.mode !== 'setup') return;
      if (st === 200 && j && j.ok) {
        prov = j.data;
        prov.expiresAt = now() + num(prov.expires_in, 900) * 1000;
        var img = document.getElementById('hc-qr-img');
        if (img) img.innerHTML = '<img alt="QR" src="' + esc((CFG.qr || 'qr.php') + '?code=' + encodeURIComponent(prov.code)) + '">';
        var code = document.getElementById('hc-code');
        if (code) code.textContent = prov.code;
        qrStatus('');
        S.provTimer = setTimeout(provStatus, clamp(num(prov.poll_interval, 3), 2, 30) * 1000);
      } else {
        qrStatus(t('QR setup is not available. Use the manual setup.'));
        S.provTimer = setTimeout(provStart, st === 429 ? retryAfter(xhr, 60000) : 60000);
      }
    });
  }
  function qrStatus(s) { var n = document.getElementById('hc-qr-status'); if (n) n.textContent = s; }
  function provStatus() {
    if (S.mode !== 'setup' || !prov) return;
    if (now() > prov.expiresAt) { qrStatus(t('The code expired. Getting a new one…')); provStart(); return; }
    var again = clamp(num(prov.poll_interval, 3), 2, 30) * 1000;
    api('GET', 'provision/status', 'code=' + encodeURIComponent(prov.code) + '&secret=' + encodeURIComponent(prov.secret), null, function (st, j, xhr) {
      if (S.mode !== 'setup') return;
      var d = st === 200 && j && j.ok ? j.data : null;
      if (d && d.status === 'claimed') {
        qrStatus(t('Assigned to screen :room. Connecting…', { room: d.room_number }));
        register(String(d.room_number), String(d.registration_key), function (msg) {
          qrStatus(msg);
          S.provTimer = setTimeout(provStatus, 10000);
        });
        return;
      }
      if (d && d.status === 'pending') { S.provTimer = setTimeout(provStatus, again); return; }
      if ((d && (d.status === 'expired' || d.status === 'used')) || st === 404) {
        qrStatus(t('The code expired. Getting a new one…'));
        provStart();
        return;
      }
      S.provTimer = setTimeout(provStatus, st === 429 ? retryAfter(xhr, 10000) : Math.max(again, 5000));
    });
  }

  function register(room, key, onError) {
    var body = {
      device_id: S.deviceId, room_number: room, registration_key: key,
      app_version: 'web-' + VERSION, app_version_code: VCODE, android_version: OS.slice(0, 20), model: MODEL,
      device_type: 'web', platform: 'web', user_agent: UA.slice(0, 255)
    };
    api('POST', 'device/register', null, body, function (st, j, xhr) {
      if (st === 200 && j && j.ok) {
        var d = j.data;
        S.token = d.token;
        S.room = d.room && d.room.number ? String(d.room.number) : room;
        S.pinHash = d.settings_pin_hash || '';
        if (d.poll_interval > 0) S.pollInterval = clamp(d.poll_interval, 3, 60);
        if (d.heartbeat_interval > 0) S.hbInterval = clamp(d.heartbeat_interval, 15, 3600);
        store.set('hc_token', S.token); // cookie copy only when localStorage is not available
        store.set('hc_room', S.room);
        store.set('hc_pin', S.pinHash);
        store.del('hc_content');
        store.del('hc_hash');
        S.content = null;
        S.hash = '';
        log('Registered as screen ' + S.room);
        startPlayer();
        return;
      }
      var code = errCode(j);
      var msg = code === 'INVALID_REGISTRATION_KEY' ? t('The registration key is wrong.')
        : code === 'ROOM_NOT_FOUND' ? t('Screen :room does not exist. Add it in the admin panel first.', { room: room })
          : code === 'LICENSE_LIMIT' ? t('TV limit reached. Remove an old TV in the admin panel or upgrade your plan.')
            : code === 'HOTEL_SUSPENDED' ? t('Service paused') + ' — ' + t('Please contact the administrator.')
              : st === 429 ? t('Too many attempts. Please try again in a minute.')
                : st === 0 ? t('Cannot reach the server. Check the address and the network.')
                  : (j && j.error && j.error.message) || ('HTTP ' + st);
      log('Register failed: ' + (code || st));
      if (onError) onError(msg);
    });
  }

  // ------------------------------------------------------------------- player shell
  function buildStage() {
    ROOT.innerHTML = '<div id="hc-stage" class="hc-stage">' +
      '<div id="hc-content" class="hc-content"></div>' +
      '<div id="hc-overlay" class="hc-overlay"></div>' +
      '<div id="hc-ticker" class="hc-ticker"></div>' +
      '<div id="hc-full" class="hc-full"></div>' +
      '<div id="hc-black" class="hc-black"></div>' +
      '<div id="hc-msg" class="hc-msg"></div>' +
      '<div id="hc-hint" class="hc-hint"></div>' +
      '<div id="hc-dot" class="hc-dot" title=""></div>' +
      '<div id="hc-menu" class="hc-menu"></div></div>';
    measure();
  }
  function $(id) { return document.getElementById(id); }

  function startPlayer() {
    S.mode = 'player';
    clearTimeout(S.provTimer);
    prov = null;
    buildStage();
    if (!S.content) {
      try {
        var cached = store.get('hc_content');
        if (cached) {
          S.content = JSON.parse(cached);
          S.hash = store.get('hc_hash') || (S.content && S.content.hash) || '';
          log('Showing cached content ' + S.hash);
        }
      } catch (e) { S.content = null; S.hash = ''; }
    }
    render();
    stopLoops();
    poll();
    S.hbTimer = setTimeout(heartbeat, 2000);
    armBackTrap();
  }
  function stopLoops() {
    clearTimeout(S.pollTimer);
    clearTimeout(S.hbTimer);
    S.pollSeq++;
    if (S.pollXhr) { try { S.pollXhr.abort(); } catch (e) { /* ignore */ } }
    S.pollXhr = null;
    S.polling = false;
  }

  // =================================================================== polling / heartbeat
  function poll() {
    if (!S.token || S.mode !== 'player') return;
    clearTimeout(S.pollTimer);
    var seq = ++S.pollSeq, t0 = now(), wait = 20;
    S.polling = true;
    S.pollXhr = api('GET', 'device/command/' + S.deviceId, 'hash=' + encodeURIComponent(S.hash || '') + '&wait=' + wait, null, function (st, j, xhr) {
      if (seq !== S.pollSeq) return; // superseded (pollNow / stop)
      S.polling = false;
      S.pollXhr = null;
      var next = S.pollInterval * 1000;
      if (st === 200 && j && j.ok) {
        S.failures = 0;
        setOnline(true);
        var d = j.data || {};
        if (d.poll_interval > 0) S.pollInterval = clamp(d.poll_interval, 3, 60);
        if (d.content && d.content_changed !== false) applyContent(d.content, d.content_hash);
        var cmds = d.commands || [];
        if (cmds.length) handleCommands(cmds);
        var took = now() - t0;
        // The server long-polls (admin setting): ask again right away; otherwise poll every poll_interval.
        if (took >= 4000) next = 300;
        if (cmds.length || d.content) next = Math.min(next, 1500);
        next = Math.max(next, 300);
      } else if (st === 401) {
        onInvalidToken();
        return;
      } else {
        S.failures++;
        setOnline(st >= 400 && st < 500 && st !== 429);
        next = st === 429 ? retryAfter(xhr, 30000) : Math.min(60000, S.pollInterval * 1000 * Math.pow(2, Math.min(3, S.failures - 1)));
        log('Poll failed (' + st + ' ' + errCode(j) + '), next in ' + Math.round(next / 1000) + ' s');
      }
      S.pollTimer = setTimeout(poll, next);
    }, (wait + 15) * 1000);
  }
  function pollNow() {
    if (S.mode !== 'player') return;
    S.pollSeq++;
    if (S.pollXhr) { try { S.pollXhr.abort(); } catch (e) { /* ignore */ } }
    S.pollXhr = null;
    S.polling = false;
    clearTimeout(S.pollTimer);
    S.pollTimer = setTimeout(poll, 50);
  }

  function heartbeat() {
    if (!S.token || S.mode !== 'player') return;
    clearTimeout(S.hbTimer);
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var body = {
      app_version: 'web-' + VERSION, app_version_code: VCODE, android_version: OS.slice(0, 20), model: MODEL,
      network_type: conn && (conn.type || conn.effectiveType) ? String(conn.type || conn.effectiveType) : 'web',
      current_content_hash: /^[a-f0-9]{40}$/.test(S.hash || '') ? S.hash : null,
      current_item_id: S.currentItemId, screen_on: !isBlack(), uptime_sec: Math.round((now() - BOOT_AT) / 1000)
    };
    api('POST', 'device/heartbeat', null, body, function (st, j) {
      if (st === 200 && j && j.ok) {
        var d = j.data || {};
        if (d.poll_interval > 0) S.pollInterval = clamp(d.poll_interval, 3, 60);
        if (d.heartbeat_interval > 0) S.hbInterval = clamp(d.heartbeat_interval, 15, 3600);
        if (d.settings_pin_hash) { S.pinHash = d.settings_pin_hash; store.set('hc_pin', S.pinHash); }
        flushPlayed();
      } else if (st === 401) {
        onInvalidToken();
        return;
      }
      S.hbTimer = setTimeout(heartbeat, S.hbInterval * 1000);
    });
  }
  function heartbeatSoon() { clearTimeout(S.hbTimer); S.hbTimer = setTimeout(heartbeat, 800); }

  function reportPlayed(item, startedAt) {
    if (!item || !(item.id > 0)) return;
    var sec = Math.round((now() - startedAt) / 1000);
    if (sec < 1) return;
    var p = { content_id: item.id, started_at: iso(startedAt), duration_sec: sec };
    if (item.ad_campaign_id) p.ad_campaign_id = item.ad_campaign_id;
    S.played.push(p);
    if (S.played.length > 200) S.played.shift();
  }
  function flushPlayed() {
    if (!S.played.length) return;
    var batch = S.played.splice(0, 200);
    api('POST', 'device/played', null, { items: batch }, function (st) {
      if (st === 0) S.played = batch.concat(S.played).slice(0, 200);
    });
  }

  function onInvalidToken() {
    log('Token rejected: back to setup');
    stopLoops();
    S.token = null;
    S.content = null;
    S.hash = '';
    store.del('hc_token');
    store.del('hc_content');
    store.del('hc_hash');
    showSetup(t('This screen was removed from the server. Please set it up again.'));
  }

  function setOnline(ok) {
    S.online = ok;
    var d = $('hc-dot');
    if (d) {
      d.className = 'hc-dot' + (ok ? '' : ' hc-on');
      d.title = ok ? '' : t('Offline — showing the last content');
    }
  }

  function applyContent(c, hash) {
    hash = hash || c.hash || '';
    c.hash = c.hash || hash;
    S.content = c;
    S.hash = hash;
    S.override = false;
    var saved = false;
    try { saved = store.set('hc_content', JSON.stringify(c)); } catch (e) { saved = false; }
    if (saved) store.set('hc_hash', hash); else { store.del('hc_content'); store.del('hc_hash'); }
    log('New content ' + hash + ' mode=' + c.mode + ' items=' + (c.items ? c.items.length : 0));
    render();
  }

  // =================================================================== rendering
  function isEmergency(c) { return !!(c && (c.mode === 'emergency' || c.emergency)); }
  function desiredOff(c) { return S.localOff || !!(c && (c.mode === 'off' || c.screen_on === false)); }
  function isBlack() { var b = $('hc-black'); return !!(b && b.className.indexOf('hc-on') >= 0); }

  var stops = [];
  function stopPlayback() {
    if (S.main) { S.main.stop(); S.main = null; }
    each(stops.splice(0, stops.length), function (f) { try { f(); } catch (e) { /* ignore */ } });
    var c = $('hc-content');
    if (c) each(toArr(c.children), cleanupLayer);
  }

  function render() {
    if (S.mode !== 'player' || !$('hc-stage')) return;
    stopPlayback();
    var c = S.content;
    $('hc-content').innerHTML = '';
    $('hc-overlay').innerHTML = '';
    $('hc-full').innerHTML = '';
    $('hc-full').className = 'hc-full';
    hideTicker();
    setBlack(false);
    syncAlarm();
    if (!c) { showWelcome(null, t('Connecting…')); return; }
    if (isEmergency(c)) { power(true); showEmergency(c); alarmHint(); return; }
    if (desiredOff(c) && !S.override) {
      setBlack(true);
      power(false, S.localOff || c.power_off_mode !== 'black');
      return;
    }
    power(true);
    if (c.mode === 'suspended') { showSuspended(c); return; }
    applyTicker(c.overlay && c.overlay.ticker);
    overlays(c);
    var items = (c.items || []).filter(function (it) { return it && typeof it === 'object'; });
    if (!items.length) { showWelcome(c, null); return; }
    S.main = new Sequencer($('hc-content'), items, {
      transition: (c.playlist && c.playlist.transition) || 'none', loop: true, fit: mainFit(c), main: true, k: 1
    });
    S.main.start();
  }
  function mainFit(c) {
    var tk = c && c.overlay && c.overlay.ticker;
    if (tk && tk.reserve_space !== false && tk.reserve_space !== 0) return { fit: 'contain', zoom: 'cover' }[tk.video_scale] || 'fill';
    return 'contain';
  }

  function setBlack(onOff) {
    var b = $('hc-black');
    if (b) b.className = 'hc-black' + (onOff ? ' hc-on' : '');
  }

  var lastPower = null;
  /** Tell the optional local CEC bridge (Raspberry Pi, tools/raspberry-pi) to switch the TV on / to standby. */
  function power(onOff, standby) {
    var state = onOff ? 'on' : (standby === false ? 'black' : 'off');
    if (state === lastPower) return;
    lastPower = state;
    heartbeatSoon();
    if (!S.cec || state === 'black') return;
    try {
      var x = new XMLHttpRequest();
      x.open('GET', 'http://127.0.0.1:' + S.cec + '/' + (onOff ? 'on' : 'off'), true);
      x.timeout = 5000;
      x.send(null);
    } catch (e) { log('CEC bridge: ' + e.message); }
  }

  function showFull(cls, html) {
    var f = $('hc-full');
    f.className = 'hc-full hc-on ' + cls;
    f.innerHTML = html;
  }
  function showEmergency(c) {
    var em = c.emergency || {};
    var ann = (c.items || [])[0] || {};
    var title = em.title || ann.text || t('Emergency');
    var msg = em.message !== undefined ? em.message : (ann.subtitle || '');
    showFull('hc-emergency', '<div class="hc-em" style="background:' + color(em.bg_color || ann.bg_color, '#B00020') + ';color:' + color(em.text_color || ann.text_color, '#FFFFFF') + '">' +
      '<div class="hc-em-icon">&#9888;</div><div class="hc-em-title">' + esc(title) + '</div>' +
      (msg ? '<div class="hc-em-msg">' + esc(msg) + '</div>' : '') +
      '<div class="hc-em-alarm" id="hc-em-alarm">&#128266; ' + esc(t('Tap or press OK to enable the alarm sound')) + '</div></div>');
  }

  // ------------------------------------------------------------------- emergency alarm (2.4.1)
  /**
   * content.emergency.alarm {url, loop, repeat, volume}: an HTML audio element plays it while the emergency
   * is shown — looping, or `repeat` times. The same alarm in a re-sent content object keeps playing (no
   * restart); a new sound / loop setting restarts it; no alarm (stopped or silenced) stops it at once.
   * A browser cannot change the TV's own volume: the element plays at max(player volume, alarm volume),
   * even when the player is muted. Autoplay blocked → "tap / OK" hint on the emergency screen.
   */
  var alarm = { a: null, key: '', blocked: false };
  function alarmSpec(c) {
    var em = isEmergency(c) ? c.emergency : null;
    var al = em && em.alarm;
    if (!al || typeof al !== 'object' || !trim(al.url) || !/^(https?:)?\/\//i.test(trim(al.url))) return null;
    var loop = al.loop !== false && al.loop !== 0 && al.loop !== '0';
    return { url: trim(al.url), loop: loop, repeat: clamp(Math.round(num(al.repeat, 3)), 1, 10), volume: clamp(Math.round(num(al.volume, 80)), 0, 100), id: em.id || 0 };
  }
  /** Restart key: sound + loop mode (a repeat-N alarm also restarts for a new emergency). */
  function alarmKey(sp) { return sp.url + '|' + (sp.loop ? 'loop' : 'x' + sp.repeat + '|' + sp.id); }
  function stopAlarm() {
    var a = alarm.a;
    alarm.a = null;
    alarm.key = '';
    alarm.blocked = false;
    if (!a) return;
    a.onended = a.onerror = null;
    try { a.pause(); a.removeAttribute('src'); a.load(); } catch (e) { /* ignore */ }
    log('Alarm stopped');
  }
  function playAlarm() {
    var a = alarm.a;
    if (!a) return;
    var pr = tryPlay(a);
    if (pr) {
      pr.then(function () { if (alarm.a === a) { alarm.blocked = false; alarmHint(); } }, function (err) {
        if (alarm.a !== a) return;
        alarm.blocked = !!(err && err.name === 'NotAllowedError');
        if (!alarm.blocked) log('Alarm cannot play: ' + (err && err.message));
        alarmHint();
      });
    }
  }
  function syncAlarm() {
    var sp = S.mode === 'player' ? alarmSpec(S.content) : null;
    if (!sp) { stopAlarm(); return; }
    var key = alarmKey(sp), vol = Math.max(sp.volume, S.volume) / 100;
    if (alarm.a && alarm.key === key) {
      try { alarm.a.volume = vol; } catch (e) { /* read-only on some TVs */ }
      return;
    }
    stopAlarm();
    var a = new window.Audio();
    var plays = 0;
    alarm.a = a;
    alarm.key = key;
    a.loop = sp.loop;
    a.preload = 'auto';
    try { a.volume = vol; } catch (e) { /* ignore */ }
    a.onended = function () {
      if (alarm.a !== a || sp.loop) return;
      plays++;
      if (plays < sp.repeat) { a.currentTime = 0; tryPlay(a); }
    };
    a.onerror = function () { if (alarm.a === a) log('Alarm sound failed to load'); };
    a.src = sp.url;
    log('Alarm ' + (sp.loop ? 'looping' : sp.repeat + '×') + ' at ' + Math.round(vol * 100) + '%');
    playAlarm();
  }
  function alarmHint() {
    var h = $('hc-em-alarm');
    if (h) h.className = 'hc-em-alarm' + (alarm.a && alarm.blocked ? ' hc-on' : '');
  }
  on(window, 'pagehide', stopAlarm);
  function brandLogo(c) {
    return (c && c.hotel && c.hotel.logo_url) || (c && c.branding && c.branding.logo_url) || CFG.logo || null;
  }
  function showSuspended(c) {
    var s = c.suspended || {};
    var b = c.branding || {};
    var logo = (b.logo_url) || (c.hotel && c.hotel.logo_url);
    showFull('hc-suspended', '<div class="hc-welcome">' + (logo ? '<img alt="" src="' + esc(logo) + '">' : '') +
      '<div class="hc-w1">' + esc(s.title || t('Service paused')) + '</div>' +
      '<div class="hc-w2">' + esc(s.message || t('Please contact the administrator.')) + '</div>' +
      (b.support ? '<div class="hc-w3" style="color:' + color(b.color, '#FFB300') + '">' + esc(t('Support: :s', { s: b.support })) + '</div>' : '') + '</div>');
  }
  function showWelcome(c, status) {
    var hotel = (c && c.hotel && c.hotel.name) || (c && c.branding && c.branding.product) || '';
    var room = (c && c.room && c.room.number) || S.room;
    var product = (c && c.branding && c.branding.product) || CFG.product || '';
    var logo = brandLogo(c);
    var layer = el('div', 'hc-layer', '<div class="hc-welcome">' + (logo ? '<img alt="" src="' + esc(logo) + '">' : '') +
      '<div class="hc-w1">' + esc(status || (hotel ? t('Welcome to :name', { name: hotel }) : t('Welcome'))) + '</div>' +
      (room ? '<div class="hc-w2" style="color:' + color(c && c.branding && c.branding.color, '#FFB300') + '">' + esc(t('Screen :room', { room: room })) + '</div>' : '') +
      (product && product !== hotel ? '<div class="hc-w3">' + esc(product) + '</div>' : '') + '</div>');
    $('hc-content').appendChild(layer);
  }

  // ------------------------------------------------------------------- sequencer (playlist / layout zone)
  function Sequencer(box, items, o) {
    this.box = box;
    this.items = items;
    this.o = o;
    this.i = -1;
    this.timer = null;
    this.dead = false;
    this.cur = null;
    this.curItem = null;
    this.started = 0;
  }
  Sequencer.prototype.start = function () { this.next(true); };
  Sequencer.prototype.stop = function () {
    this.dead = true;
    clearTimeout(this.timer);
    this.report();
    each(toArr(this.box.children), cleanupLayer);
  };
  Sequencer.prototype.report = function () {
    if (this.o.main && this.curItem) reportPlayed(this.curItem, this.started);
    this.curItem = null;
  };
  Sequencer.prototype.next = function (first) {
    var self = this;
    if (self.dead || !self.items.length) return;
    clearTimeout(self.timer);
    var n = self.items.length;
    if (!first && self.o.loop === false && self.i >= n - 1) return; // zone without loop stays on its last item
    self.report();
    self.i = (self.i + 1) % n;
    var item = self.items[self.i];
    if (self.o.mute) item = shallow(item, { mute: true });
    var single = n === 1;
    var layer = renderItem(item, {
      loop: single && self.o.loop !== false, fit: self.o.fit, k: self.o.k || 1,
      onEnded: function () { if (!single && self.cur === layer) self.next(); },
      onError: function () { if (!single && self.cur === layer) { clearTimeout(self.timer); self.timer = setTimeout(function () { self.next(); }, 1500); } }
    });
    swapLayer(self.box, layer, first ? 'none' : (self.o.transition || 'none'));
    self.cur = layer;
    self.curItem = item;
    self.started = now();
    if (self.o.main) S.currentItemId = item.id || null;
    if (single) return;
    var d = num(item.duration, 0);
    if (d > 0) self.timer = setTimeout(function () { self.next(); }, d * 1000);
    else if (item.type !== 'video') self.timer = setTimeout(function () { self.next(); }, DEFAULT_ITEM_SEC * 1000);
  };
  function shallow(o, extra) {
    var r = {}, k;
    for (k in o) if (Object.prototype.hasOwnProperty.call(o, k)) r[k] = o[k];
    for (k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) r[k] = extra[k];
    return r;
  }
  function swapLayer(box, layer, tr) {
    var old = toArr(box.children);
    if ((tr === 'fade' || tr === 'slide') && old.length) {
      layer.className += ' hc-' + tr + '-in';
      box.appendChild(layer);
      void layer.offsetWidth; // start the CSS transition from the "in" state
      layer.className = layer.className.replace(' hc-' + tr + '-in', '');
      each(old, function (o) { o.className += ' hc-' + tr + '-out'; });
      setTimeout(function () { each(old, cleanupLayer); }, 900);
    } else {
      each(old, cleanupLayer);
      box.appendChild(layer);
    }
  }
  function cleanupLayer(o) {
    if (!o) return;
    if (o._stop) { try { o._stop(); } catch (e) { /* ignore */ } o._stop = null; }
    each(toArr(o.getElementsByTagName('video')), function (v) {
      try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) { /* ignore */ }
    });
    each(toArr(o.getElementsByTagName('iframe')), function (f) { try { f.src = 'about:blank'; } catch (e) { /* ignore */ } });
    remove(o);
  }

  // ------------------------------------------------------------------- items
  function renderItem(item, o) {
    var layer = el('div', 'hc-layer');
    var timers = [];
    var subStops = [];
    layer._stop = function () {
      each(timers, function (x) { clearInterval(x); clearTimeout(x); });
      each(subStops, function (f) { f(); });
      if (layer._hls) { try { layer._hls.destroy(); } catch (e) { /* ignore */ } layer._hls = null; }
    };
    var k = o.k || 1;
    var fs = function (sp) { return (num(sp, 26) * DP * k) + 'em'; };
    switch (item.type) {
      case 'image': {
        var img = el('img', 'hc-media');
        img.alt = '';
        setStyle(img, 'objectFit', o.fit || 'contain');
        img.src = item.url || '';
        layer.appendChild(img);
        break;
      }
      case 'video':
        layer.appendChild(makeVideo(item.url || '', item, o, layer, timers, !!o.loop && item.loop !== false));
        break;
      case 'stream':
        if (/^(rtsp|rtmp|udp|rtp):/i.test(item.url || '') || /\.mpd(\?|#|$)/i.test(item.url || '')) {
          layer.innerHTML = placeholder('&#127909;', item.title, t('This stream cannot play in a web browser.'));
        } else {
          layer.appendChild(makeVideo(item.url || '', item, o, layer, timers, true));
        }
        break;
      case 'url':
      case 'youtube': {
        var f = el('iframe', 'hc-frame');
        f.setAttribute('allow', 'autoplay; fullscreen; encrypted-media; picture-in-picture');
        f.setAttribute('allowfullscreen', '');
        f.setAttribute('frameborder', '0');
        f.setAttribute('scrolling', 'no');
        var src = item.type === 'youtube' ? youtubeSrc(item.embed_url || item.url || '', o) : (item.url || '');
        if (item.type === 'url') f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-presentation');
        f.src = src;
        layer.appendChild(f);
        if (item.type === 'url' && num(item.refresh_sec, 0) > 0) {
          timers.push(setInterval(function () { f.src = src; }, Math.max(10, num(item.refresh_sec, 0)) * 1000));
        }
        break;
      }
      case 'html':
      case 'timetable': {
        // Untrusted HTML: sandboxed (no same-origin access to the player's token).
        var h = el('iframe', 'hc-frame hc-white');
        h.setAttribute('sandbox', 'allow-scripts');
        h.setAttribute('frameborder', '0');
        setDoc(h, item.html || '');
        layer.appendChild(h);
        if (item.type === 'timetable' && num(item.refresh_sec, 0) > 0) {
          timers.push(setInterval(function () { setDoc(h, item.html || ''); }, Math.max(10, num(item.refresh_sec, 0)) * 1000));
        }
        break;
      }
      case 'announcement': {
        var bg = color(item.bg_color, '#1A237E'), fg = color(item.text_color, '#FFFFFF');
        if (item.style === 'marquee') {
          var len = String(item.text || '').length + 20;
          layer.innerHTML = '<div class="hc-marq" style="background:' + bg + ';color:' + fg + ';font-size:' + fs(item.font_size) + '">' +
            '<span style="animation-duration:' + Math.max(8, len * 0.25) + 's;-webkit-animation-duration:' + Math.max(8, len * 0.25) + 's">' +
            esc(item.text) + (item.subtitle ? ' — ' + esc(item.subtitle) : '') + '</span></div>';
        } else {
          layer.innerHTML = '<div class="hc-ann" style="background:' + bg + ';color:' + fg + '"><div class="hc-ann-t" style="font-size:' + fs(item.font_size) + '">' + esc(item.text) + '</div>' +
            (item.subtitle ? '<div class="hc-ann-s" style="font-size:' + fs(num(item.font_size, 48) * 0.5) + '">' + esc(item.subtitle) + '</div>' : '') + '</div>';
        }
        break;
      }
      case 'clock':
        renderClock(layer, item, timers, k);
        break;
      case 'layout':
        renderLayout(layer, item, subStops);
        break;
      default:
        layer.innerHTML = placeholder('&#9634;', item.title, '');
    }
    return layer;
  }
  function placeholder(icon, title, text) {
    return '<div class="hc-ph"><div class="hc-ph-i">' + icon + '</div><div class="hc-ph-t">' + esc(title || '') + '</div>' + (text ? '<div>' + esc(text) + '</div>' : '') + '</div>';
  }
  function setDoc(frame, html) {
    if ('srcdoc' in frame) frame.srcdoc = html;
    else frame.src = 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
  }
  function youtubeSrc(u, o) {
    if (!u) return '';
    var sep = u.indexOf('?') >= 0 ? '&' : '?';
    if (!/[?&]autoplay=/.test(u)) { u += sep + 'autoplay=1'; sep = '&'; }
    // Without a user gesture browsers only autoplay muted videos.
    if ((S.soundOk !== true || S.muted || o.mute) && !/[?&]mute=1/.test(u)) u += sep + 'mute=1';
    return u;
  }

  // ------------------------------------------------------------------- video / HLS / autoplay
  var hlsState = 0, hlsWaiters = [];
  function loadHls(cb) {
    if (window.Hls) { cb(true); return; }
    if (!CFG.hls || hlsState === 2) { cb(false); return; }
    hlsWaiters.push(cb);
    if (hlsState === 1) return;
    hlsState = 1;
    var s = document.createElement('script');
    s.src = CFG.hls;
    s.onload = function () { hlsState = window.Hls ? 3 : 2; var w = hlsWaiters.splice(0, hlsWaiters.length); each(w, function (f) { f(!!window.Hls); }); };
    s.onerror = function () { hlsState = 2; var w = hlsWaiters.splice(0, hlsWaiters.length); each(w, function (f) { f(false); }); };
    document.getElementsByTagName('head')[0].appendChild(s);
  }
  function isHls(u) { return /\.m3u8(\?|#|$)/i.test(u); }
  function nativeHls(v) {
    try { return !!(v.canPlayType('application/vnd.apple.mpegurl') || v.canPlayType('application/x-mpegURL')); } catch (e) { return false; }
  }
  function makeVideo(url, item, o, layer, timers, loop) {
    var v = el('video', 'hc-media');
    v.setAttribute('playsinline', '');
    v.setAttribute('webkit-playsinline', '');
    v.preload = 'auto';
    v.loop = !!loop;
    v.muted = true;
    setStyle(v, 'objectFit', o.fit || 'contain');
    v.setAttribute('data-sound', item.mute || o.mute ? '0' : '1');
    try { v.volume = S.volume / 100; } catch (e) { /* read-only on some TVs */ }
    var note = null, failures = 0;
    on(v, 'ended', function () { if (o.onEnded) o.onEnded(); });
    on(v, 'playing', function () { failures = 0; if (note) { remove(note); note = null; } });
    on(v, 'error', function () {
      failures++;
      log('Video error ' + (v.error ? v.error.code : '?') + ' ' + url);
      if (o.onError) o.onError();
      if (!note) { note = el('div', 'hc-vnote', esc(t('This video cannot play in this browser.'))); layer.appendChild(note); }
      timers.push(setTimeout(function () { if (v.parentNode) { attach(); } }, Math.min(60, 10 * failures) * 1000));
    });
    function attach() {
      if (isHls(url) && !nativeHls(v)) {
        loadHls(function (ok) {
          if (!v.parentNode && !layer.parentNode) return;
          if (ok && window.Hls.isSupported && window.Hls.isSupported()) {
            if (layer._hls) { try { layer._hls.destroy(); } catch (e) { /* ignore */ } }
            var h = new window.Hls({ enableWorker: true, lowLatencyMode: false });
            layer._hls = h;
            h.on(window.Hls.Events.ERROR, function (ev, data) {
              if (!data || !data.fatal) return;
              if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) { timers.push(setTimeout(function () { try { h.startLoad(); } catch (e) { /* ignore */ } }, 5000)); }
              else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) { try { h.recoverMediaError(); } catch (e) { /* ignore */ } }
              else if (o.onError) o.onError();
            });
            h.loadSource(url);
            h.attachMedia(v);
          } else {
            v.src = url;
          }
          startPlayback(v);
        });
      } else {
        v.src = url;
        startPlayback(v);
      }
    }
    attach();
    return v;
  }
  function tryPlay(m) {
    try {
      var p = m.play();
      return p && typeof p.then === 'function' ? p : null;
    } catch (e) { return null; }
  }
  /** Muted autoplay always works; sound only after a user gesture or when the browser allows it. */
  function startPlayback(v) {
    var sound = v.getAttribute('data-sound') === '1' && !S.muted;
    if (sound && S.soundOk !== false) {
      v.muted = false;
      var p = tryPlay(v);
      if (p) {
        p.then(function () { S.soundOk = true; }, function (err) {
          if (!err || err.name !== 'NotAllowedError') return;
          S.soundOk = false;
          v.muted = true;
          tryPlay(v);
          soundHint();
        });
      }
    } else {
      v.muted = true;
      tryPlay(v);
      if (sound) soundHint();
    }
  }
  function mediaEls() {
    return toArr(ROOT.getElementsByTagName('video'));
  }
  function applyAudio() {
    each(mediaEls(), function (v) {
      try { v.volume = (S.volume / 100) * (ducks > 0 ? 0.25 : 1); } catch (e) { /* ignore */ }
      if (v.getAttribute('data-sound') === '1') {
        v.muted = S.muted || S.soundOk === false;
        if (v.paused) tryPlay(v);
      }
    });
  }
  var hintShown = false;
  function soundHint() {
    if (hintShown || S.gestured) return;
    hintShown = true;
    var h = $('hc-hint');
    if (!h) return;
    h.innerHTML = '&#128264; ' + esc(t('Press OK to enable sound'));
    h.className = 'hc-hint hc-on';
    setTimeout(function () { h.className = 'hc-hint'; }, 12000);
  }

  // ------------------------------------------------------------------- clock
  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  /** "Now" as a Date whose local fields show the hotel's wall-clock time (content.tz_offset_min). */
  function hotelNow() {
    var d = new Date();
    var tz = S.content && typeof S.content.tz_offset_min === 'number' ? S.content.tz_offset_min : null;
    if (tz === null) return d;
    return new Date(d.getTime() + (tz + d.getTimezoneOffset()) * 60000);
  }
  function fmtTime(d, f) {
    var h = d.getHours(), h12 = h % 12 || 12;
    var map = {
      yyyy: d.getFullYear(), MM: pad(d.getMonth() + 1), dd: pad(d.getDate()), EEE: DAYS[d.getDay()],
      HH: pad(h), H: h, hh: pad(h12), h: h12, mm: pad(d.getMinutes()), ss: pad(d.getSeconds()), a: h < 12 ? 'AM' : 'PM'
    };
    return String(f || 'hh:mm a').replace(/yyyy|MM|dd|EEE|HH|H|hh|h|mm|ss|a/g, function (x) { return map[x]; });
  }
  function dateText(d) {
    try { return d.toLocaleDateString(LANG === 'en' ? undefined : LANG + '-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) { return d.toDateString(); }
  }
  function renderClock(layer, item, timers, k) {
    var box = el('div', 'hc-clock');
    box.style.background = color(item.bg_color, '#000000');
    box.style.color = color(item.text_color, '#FFFFFF');
    box.style.fontSize = k + 'em';
    layer.appendChild(box);
    if (item.style === 'analog') {
      var ticks = '';
      for (var i = 0; i < 12; i++) ticks += '<line x1="0" y1="-82" x2="0" y2="-92" stroke="currentColor" stroke-width="' + (i % 3 ? 2 : 5) + '" transform="rotate(' + (i * 30) + ')"/>';
      box.innerHTML = '<svg viewBox="-100 -100 200 200" class="hc-analog"><circle r="95" fill="none" stroke="currentColor" stroke-width="4"/>' + ticks +
        '<line class="hh" y2="-50" stroke="currentColor" stroke-width="7" stroke-linecap="round"/><line class="mh" y2="-75" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>' +
        '<line class="sh" y2="-82" stroke="#e11d48" stroke-width="2"/><circle r="5" fill="currentColor"/></svg><div class="hc-date"></div>';
      var hands = box.getElementsByTagName('line');
      var tick = function () {
        var d = hotelNow();
        hands[12].setAttribute('transform', 'rotate(' + ((d.getHours() % 12) * 30 + d.getMinutes() / 2) + ')');
        hands[13].setAttribute('transform', 'rotate(' + (d.getMinutes() * 6) + ')');
        hands[14].setAttribute('transform', 'rotate(' + (d.getSeconds() * 6) + ')');
        box.lastChild.textContent = dateText(d);
      };
      tick();
      timers.push(setInterval(tick, 1000));
    } else {
      box.innerHTML = '<div class="hc-time"></div><div class="hc-date"></div>';
      var tick2 = function () {
        var d = hotelNow();
        box.firstChild.textContent = fmtTime(d, 'hh:mm');
        box.lastChild.textContent = fmtTime(d, 'a') + ' · ' + dateText(d);
      };
      tick2();
      timers.push(setInterval(tick2, 1000));
    }
  }

  // ------------------------------------------------------------------- layout (split screen)
  function renderLayout(layer, item, subStops) {
    var L = item.layout || {};
    var pct = function (v) { return clamp(num(v, 0), 0, 100); };
    layer.className += ' hc-lay';
    layer.style.background = color(L.bg_color, '#000000');
    each((L.zones || []).slice(0, 6), function (z) {
      var box = el('div', 'hc-zone');
      box.style.left = pct(z.x) + '%';
      box.style.top = pct(z.y) + '%';
      box.style.width = pct(z.w) + '%';
      box.style.height = pct(z.h) + '%';
      layer.appendChild(box);
      var items = (z.items || []).filter(function (it) { return it && it.type !== 'layout'; });
      if (!items.length) return;
      var seq = new Sequencer(box, items, {
        transition: z.transition || 'fade', loop: z.loop !== false, mute: !!z.mute,
        fit: { fit: 'contain', fill: 'fill', zoom: 'cover' }[z.scale] || 'contain',
        k: Math.max(0.2, pct(z.w) / 100)
      });
      seq.start();
      subStops.push(function () { seq.stop(); });
    });
  }

  // ------------------------------------------------------------------- overlay (logo, clock, weather)
  function overlays(c) {
    var ov = c.overlay || {};
    var box = $('hc-overlay');
    var html = '';
    var logo = c.hotel && c.hotel.logo_url;
    if (ov.logo && logo) html += '<div class="hc-ov-logo"><img alt="" src="' + esc(logo) + '"></div>';
    var w = ov.weather && ov.weather.enabled ? ov.weather : null;
    if (ov.clock || w) {
      html += '<div class="hc-ov-tr">' + (ov.clock ? '<div class="hc-ov-clock"></div>' : '') +
        (w ? '<div class="hc-ov-w">' + esc(w.icon || '') + ' ' + esc(w.temp_c) + '°C ' + esc(w.city || '') + '</div>' : '') + '</div>';
    }
    box.innerHTML = html;
    var clk = box.querySelector ? box.querySelector('.hc-ov-clock') : null;
    if (clk) {
      var tick = function () { clk.textContent = fmtTime(hotelNow(), ov.clock_format); };
      tick();
      var iv = setInterval(tick, 1000);
      stops.push(function () { clearInterval(iv); });
    }
  }

  // ------------------------------------------------------------------- ticker bar
  var raf = window.requestAnimationFrame || window.webkitRequestAnimationFrame || function (f) { return setTimeout(function () { f(now()); }, 16); };
  var caf = window.cancelAnimationFrame || window.webkitCancelAnimationFrame || clearTimeout;
  function hideTicker() {
    var tk = $('hc-ticker');
    if (tk) { tk.className = 'hc-ticker'; tk.innerHTML = ''; tk.removeAttribute('style'); }
    var c = $('hc-content'), o = $('hc-overlay');
    if (c) { c.style.top = '0'; c.style.bottom = '0'; }
    if (o) { o.style.top = '0'; o.style.bottom = '0'; }
  }
  function applyTicker(tk) {
    if (!tk || !(tk.text || (tk.messages && tk.messages.length))) return;
    var font = clamp(num(tk.font_size, 26), 14, 72);
    var height = Math.max(clamp(num(tk.height, 56), 32, 200), font * 1.4); // the bar grows with the font
    var top = tk.position === 'top';
    var reserve = tk.reserve_space !== false && tk.reserve_space !== 0;
    var bar = $('hc-ticker');
    bar.className = 'hc-ticker hc-on ' + (top ? 'hc-top' : 'hc-bottom');
    bar.style.background = color(tk.bg_color, '#000000');
    bar.style.color = color(tk.text_color, '#FFD700');
    bar.style.height = (height * DP) + 'em';
    if (!reserve) bar.style.opacity = '0.92';
    var span = el('span', 'hc-ticker-text');
    span.style.fontSize = (font * DP) + 'em';
    span.textContent = tk.messages && tk.messages.length ? tk.messages.join('   ✦   ') : String(tk.text || '');
    bar.appendChild(span);
    if (reserve) {
      var side = top ? 'top' : 'bottom';
      $('hc-content').style[side] = (height * DP) + 'em';
      $('hc-overlay').style[side] = (height * DP) + 'em';
    }
    // Same speed as the TV app: 30 + 25 × speed dp per second, from the right edge until the text has left.
    var speed = 30 + clamp(num(tk.speed, 5), 1, 10) * 25;
    var x = null, last = 0, id = null, dead = false;
    var frame = function (ts) {
      if (dead) return;
      var tnow = typeof ts === 'number' && ts > 0 ? ts : now();
      var W = bar.clientWidth || window.innerWidth;
      var tw = span.offsetWidth || 1;
      if (x === null || x < -tw) { x = W; last = tnow; }
      var dt = Math.min(100, Math.max(0, tnow - last));
      last = tnow;
      x -= speed * UNIT * dt / 1000;
      setStyle(span, 'transform', 'translate3d(' + x.toFixed(1) + 'px,-50%,0)');
      id = raf(frame);
    };
    id = raf(frame);
    stops.push(function () { dead = true; caf(id); });
  }

  // =================================================================== commands
  function isDone(id) { for (var i = 0; i < S.done.length; i++) if (S.done[i] === id) return true; return false; }
  function markDone(id) {
    S.done.push(id);
    if (S.done.length > 200) S.done = S.done.slice(-200);
    store.set('hc_done', JSON.stringify(S.done));
  }
  function ack(id, status, msg, after) {
    api('POST', 'device/ack', null, { command_id: id, status: status, message: String(msg || '').slice(0, 250) }, function (st) {
      if (st !== 200) log('Ack ' + id + ' failed (' + st + ')');
      if (after) after();
    });
  }
  function handleCommands(list) {
    list = toArr(list).sort(function (a, b) { return num(a.id, 0) - num(b.id, 0); });
    each(list, handleCommand);
  }
  function unsupported(why) { return 'unsupported: ' + why; }
  function handleCommand(cmd) {
    var id = num(cmd && cmd.id, 0);
    if (!id) return;
    var name = String(cmd.command || '').toUpperCase().replace(/\s+/g, '');
    var p = cmd.payload && typeof cmd.payload === 'object' ? cmd.payload : {};
    if (isDone(id)) { ack(id, 'acked', 'Already handled'); return; }
    markDone(id);
    log('Command ' + id + ' ' + name);
    var answered = false;
    var done = function (status, msg, after) {
      if (answered) return;
      answered = true;
      ack(id, status, msg, after);
    };
    try {
      switch (name) {
        case 'PING': done('acked', 'pong (web player)'); break;
        case 'SHOW_CONTENT': S.hash = ''; pollNow(); done('acked', 'Content refreshed'); break;
        case 'CLEAR_CACHE': clearCache(); done('acked', 'Cache cleared (content JSON; media is cached by the browser)'); break;
        case 'SCREEN_OFF':
          S.localOff = true; S.override = false; render();
          done('acked', 'Screen off (black screen' + (S.cec ? ', TV standby via HDMI-CEC' : '') + ')');
          break;
        case 'SCREEN_ON':
          S.localOff = false; S.override = desiredOff(S.content); render();
          done('acked', 'Screen on');
          break;
        case 'RELOAD': done('acked', 'Reloading the page', reloadPage); break;
        case 'REBOOT': done('acked', 'Reloading the page (a browser cannot reboot the device)', reloadPage); break;
        case 'SET_VOLUME': {
          var lv = p.level !== undefined ? p.level : p.volume;
          if (lv === undefined || lv === null || !isFinite(Number(lv))) { done('failed', 'Missing level (0-100)'); break; }
          setVolume(clamp(Math.round(Number(lv)), 0, 100));
          done('acked', 'Volume ' + S.volume + '% (player volume; the TV\'s own volume is not changed)');
          break;
        }
        case 'MUTE': setMuted(true); done('acked', 'Muted'); break;
        case 'UNMUTE': setMuted(false); done('acked', S.soundOk === false ? 'Unmuted (sound starts after OK is pressed once: browser autoplay policy)' : 'Unmuted'); break;
        case 'SHOW_MESSAGE': {
          var title = trim(p.title), message = trim(p.message);
          if (!title && !message) { done('failed', 'Missing title/message'); break; }
          if (isEmergency(S.content)) { done('failed', 'Not shown: emergency active'); break; }
          var dur = num(p.duration_sec, 15);
          dur = clamp(dur <= 0 ? 15 : dur, 3, 3600);
          showMessage(title, message, dur);
          // 2.4.1: optional sound {url, repeat, volume} when the message appears (PLAY_SOUND path, video ducked).
          var snd = p.sound && typeof p.sound === 'object' && trim(p.sound.url) ? p.sound : null;
          if (snd) playSound({ url: snd.url, repeat: clamp(Math.round(num(snd.repeat, 1)), 1, 5), volume: snd.volume }, function () { /* acked with the message */ });
          done('acked', 'Message shown for ' + dur + ' s' + (snd ? ' with sound' : '') + (isBlack() ? ' (screen is off)' : ''));
          break;
        }
        case 'SCREENSHOT': screenshot(done); break;
        case 'UPLOAD_LOGS': uploadLogs(done); break;
        case 'SPEAK': speak(p, done); break;
        case 'PLAY_SOUND': playSound(p, done); break;
        case 'UPDATE_APP': done('failed', unsupported('the web player updates itself when the page reloads')); break;
        case 'OPEN_INPUT': done('failed', unsupported('a web browser cannot switch TV inputs')); break;
        case 'SHOW_WELCOME': done('failed', unsupported('no guest welcome card in the web player')); break;
        default: done('failed', unsupported(name || 'unknown command'));
      }
    } catch (e) {
      log('Command ' + id + ' crashed: ' + e.message);
      done('failed', 'Error: ' + e.message);
    }
  }
  function reloadPage() {
    setTimeout(function () { window.location.reload(); }, 300);
  }
  function clearCache() {
    store.del('hc_content');
    store.del('hc_hash');
    S.hash = '';
    try {
      if (window.caches && window.caches.keys) {
        window.caches.keys().then(function (keys) { each(keys, function (k) { window.caches['delete'](k); }); });
      }
    } catch (e) { /* ignore */ }
    pollNow();
  }
  function setVolume(level) {
    S.volume = level;
    store.set('hc_volume', String(level));
    applyAudio();
    if (alarm.a) syncAlarm(); // alarm plays at max(player volume, alarm volume)
  }
  function setMuted(m) {
    S.muted = m;
    store.set('hc_muted', m ? '1' : '0');
    applyAudio();
  }

  var msgTimer = null;
  function showMessage(title, message, sec) {
    var m = $('hc-msg');
    if (!m) return;
    m.innerHTML = '<div class="hc-msg-card">' + (title ? '<div class="hc-msg-t">' + esc(title) + '</div>' : '') +
      (message ? '<div class="hc-msg-m">' + esc(message) + '</div>' : '') + '</div>';
    m.className = 'hc-msg hc-on';
    clearTimeout(msgTimer);
    msgTimer = setTimeout(hideMessage, sec * 1000);
  }
  function hideMessage() {
    var m = $('hc-msg');
    if (m) m.className = 'hc-msg';
    clearTimeout(msgTimer);
  }
  function messageVisible() { var m = $('hc-msg'); return !!(m && m.className.indexOf('hc-on') >= 0); }

  function uploadLogs(done) {
    var state = {
      platform: 'web', version: 'web-' + VERSION, os: OS, model: MODEL, user_agent: UA, room: S.room,
      hash: S.hash, online: S.online, screen_off: isBlack(), volume: S.volume, muted: S.muted, sound_ok: S.soundOk,
      viewport: (window.innerWidth || 0) + 'x' + (window.innerHeight || 0), uptime_sec: Math.round((now() - BOOT_AT) / 1000)
    };
    var text = LOG.join('\n');
    api('POST', 'device/logs', null, { logs: text, state: state }, function (st, j) {
      if (st === 200 && j && j.ok) done('acked', 'Logs uploaded (' + Math.round(text.length / 1024) + ' KB)');
      else done('failed', 'Upload failed (' + (errCode(j) || st) + ')');
    }, 30000);
  }

  // ------------------------------------------------------------------- SCREENSHOT (best effort, canvas)
  // A browser cannot capture its own screen. The player redraws what it shows on a canvas: colours,
  // images and video frames (when the server allows it: same origin / CORS), texts. Web pages in iframes
  // are drawn as grey boxes. When nothing can be drawn the command is acked "unsupported".
  function screenshot(done) {
    var cv = document.createElement('canvas');
    if (!cv.getContext || !cv.toDataURL || !window.FormData || !window.Blob || !window.Uint8Array) {
      done('failed', unsupported('this browser cannot draw a screenshot'));
      return;
    }
    var W = window.innerWidth || 1280, H = window.innerHeight || 720;
    var scale = Math.min(1, 1280 / W);
    cv.width = Math.round(W * scale);
    cv.height = Math.round(H * scale);
    var data = null, partial = false;
    var draw = function (withMedia) {
      var ctx = cv.getContext('2d');
      ctx.setTransform(scale, 0, 0, scale, 0, 0);
      ctx.fillStyle = '#000';
      ctx.fillRect(0, 0, W, H);
      var all = toArr($('hc-stage').getElementsByTagName('*'));
      each(all, function (n) {
        var r = n.getBoundingClientRect();
        if (r.width < 1 || r.height < 1 || r.right < 0 || r.bottom < 0 || r.left > W || r.top > H) return;
        var cs = window.getComputedStyle(n);
        if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) return;
        if (!visibleChain(n)) return;
        var bg = cs.backgroundColor;
        if (bg && bg !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(bg)) { ctx.fillStyle = bg; ctx.fillRect(r.left, r.top, r.width, r.height); }
        var tag = n.tagName;
        if (tag === 'IMG' && withMedia && n.complete && n.naturalWidth) {
          try { ctx.drawImage(n, r.left, r.top, r.width, r.height); } catch (e) { partial = true; }
        } else if (tag === 'VIDEO' && withMedia && n.readyState >= 2) {
          try { ctx.drawImage(n, r.left, r.top, r.width, r.height); } catch (e) { partial = true; }
        } else if (tag === 'IFRAME') {
          ctx.fillStyle = '#334155';
          ctx.fillRect(r.left, r.top, r.width, r.height);
          partial = true;
        } else if ((tag === 'IMG' || tag === 'VIDEO') && !withMedia) {
          partial = true;
        }
        var own = '';
        each(toArr(n.childNodes), function (c) { if (c.nodeType === 3) own += c.nodeValue; });
        own = trim(own);
        if (own) {
          ctx.fillStyle = cs.color || '#fff';
          ctx.font = (cs.fontWeight || '400') + ' ' + cs.fontSize + ' ' + (cs.fontFamily || 'sans-serif');
          ctx.textBaseline = 'middle';
          ctx.textAlign = cs.textAlign === 'center' ? 'center' : 'left';
          var tx = ctx.textAlign === 'center' ? r.left + r.width / 2 : r.left;
          ctx.fillText(own, tx, r.top + r.height / 2, Math.max(r.width, 10) * 4);
        }
      });
      return cv.toDataURL('image/jpeg', 0.8);
    };
    try { data = draw(true); } catch (e1) {
      partial = true;
      try { data = draw(false); } catch (e2) { data = null; } // tainted canvas: redraw without media
    }
    if (!data || data.length < 100) { done('failed', unsupported('the browser does not allow drawing the screen')); return; }
    var bin = window.atob(data.split(',')[1]);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    var fd = new window.FormData();
    fd.append('image', new window.Blob([bytes], { type: 'image/jpeg' }), 'screenshot-' + now() + '.jpg');
    api('POST', 'device/screenshot', null, fd, function (st, j) {
      if (st === 200 && j && j.ok) done('acked', 'Screenshot uploaded (' + Math.round(bytes.length / 1024) + ' KB' + (partial ? ', approximate: web pages / protected media drawn as boxes' : '') + ')');
      else done('failed', 'Upload failed (' + (errCode(j) || st) + ')');
    }, 30000);
  }
  function visibleChain(n) {
    var stage = $('hc-stage');
    while (n && n !== stage) {
      var cs = window.getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) return false;
      n = n.parentNode;
    }
    return true;
  }

  // ------------------------------------------------------------------- SPEAK (speechSynthesis)
  var voices = [];
  function loadVoices() { try { voices = window.speechSynthesis ? window.speechSynthesis.getVoices() || [] : []; } catch (e) { voices = []; } }
  if (window.speechSynthesis) {
    loadVoices();
    try { window.speechSynthesis.onvoiceschanged = loadVoices; } catch (e) { /* ignore */ }
  }
  function speechLang(l, text) {
    l = String(l || '').toLowerCase().replace('_', '-');
    if (/^gu/.test(l)) return 'gu-IN';
    if (/^hi/.test(l)) return 'hi-IN';
    if (/^en/.test(l)) return 'en-IN';
    if (/[઀-૿]/.test(text)) return 'gu-IN';
    if (/[ऀ-ॿ]/.test(text)) return 'hi-IN';
    return 'en-IN';
  }
  function pickVoice(lang) {
    if (!voices.length) loadVoices();
    var want = lang.toLowerCase(), pre = want.split('-')[0], best = null, i, vl;
    for (i = 0; i < voices.length; i++) {
      vl = String(voices[i].lang || '').toLowerCase().replace('_', '-');
      if (vl === want) return voices[i];
      if (!best && vl.split('-')[0] === pre) best = voices[i];
    }
    return best;
  }
  function vol01(v, d) {
    v = num(v, NaN);
    if (!isFinite(v)) return d;
    return clamp(v > 1 ? v / 100 : v, 0, 1);
  }
  /** Lower the video sound while an announcement / sound plays (like the TV app's audio ducking). */
  var ducks = 0;
  function duck(onOff) {
    ducks = Math.max(0, ducks + (onOff ? 1 : -1));
    each(mediaEls(), function (v) {
      try { v.volume = (S.volume / 100) * (ducks > 0 ? 0.25 : 1); } catch (e) { /* read-only on some TVs */ }
    });
  }
  /** SPEAK {text, lang: gu|hi|en|auto, rate, pitch?, repeat, volume?, chime_before} (payload: docs/modules/device_schedules.md). */
  function speak(p, done) {
    var syn = window.speechSynthesis;
    if (!syn || typeof window.SpeechSynthesisUtterance === 'undefined') { done('failed', unsupported('this browser has no speech synthesis')); return; }
    var text = trim(p.text || p.message);
    if (!text) { done('failed', 'Missing text'); return; }
    var lang = speechLang(p.lang || p.language, text);
    var times = clamp(Math.round(num(p.repeat, 1)), 1, 5);
    var go = function () {
      var voice = pickVoice(lang);
      var label = lang + (voice ? ', voice ' + voice.name : ', no ' + lang + ' voice installed: browser default');
      var left = times, ducked = false;
      var finish = function () {
        left--;
        if (left <= 0 && ducked) { ducked = false; duck(false); }
      };
      try { syn.cancel(); } catch (e) { /* ignore */ }
      for (var r = 0; r < times; r++) {
        var u = new window.SpeechSynthesisUtterance(text);
        u.lang = lang;
        if (voice) u.voice = voice;
        u.rate = clamp(num(p.rate, 1), 0.5, 2);
        u.pitch = clamp(num(p.pitch, 1), 0, 2);
        u.volume = vol01(p.volume, S.volume / 100);
        u.onend = finish;
        if (r === 0) {
          u.onstart = function () {
            if (!ducked) { ducked = true; duck(true); }
            done('acked', 'Speaking (' + label + (times > 1 ? ', ×' + times : '') + ')');
          };
          u.onerror = function (ev) {
            var er = ev && ev.error ? ev.error : 'error';
            if (ducked) { ducked = false; duck(false); }
            done(er === 'interrupted' || er === 'canceled' ? 'acked' : 'failed', 'Speech ' + er + (er === 'not-allowed' ? ': blocked until OK is pressed once on the screen (browser autoplay policy)' : '') + ' (' + label + ')');
          };
        } else {
          u.onerror = finish;
        }
        syn.speak(u);
      }
      // Some TV browsers never fire onstart: ack anyway after a few seconds.
      setTimeout(function () { done('acked', 'Speech requested (' + label + ')'); }, 4000);
    };
    if (p.chime_before) {
      playTones('chime', vol01(p.volume, S.volume / 100), 1, function () { /* the chime is a courtesy: speak anyway */ });
      setTimeout(go, 1300);
    } else {
      go();
    }
  }

  // ------------------------------------------------------------------- PLAY_SOUND (HTML audio / Web Audio tones)
  var TONES = {
    chime: [[880, 0, 0.35], [660, 0.35, 0.6]],
    bell: [[1320, 0, 1.4]],
    beep: [[1000, 0, 0.25]],
    alarm: [[960, 0, 0.25], [720, 0.25, 0.25], [960, 0.5, 0.25], [720, 0.75, 0.25]],
    doorbell: [[784, 0, 0.45], [523, 0.5, 0.8]]
  };
  var audioCtx = null, audios = [];
  /** PLAY_SOUND {url, volume?, repeat} — or, without url, a built-in tone {sound: chime|bell|beep|alarm|doorbell}. */
  function playSound(p, done) {
    var url = trim(p.url || p.src);
    var name = trim(p.sound || p.name || p.tone).toLowerCase();
    var volume = vol01(p.volume, S.volume / 100);
    var times = clamp(Math.round(num(p.repeat, 1)), 1, 10);
    if (!url) {
      if (!TONES[name]) name = 'chime';
      playTones(name, volume, times, function (ok, msg) { done(ok ? 'acked' : 'failed', msg); });
      return;
    }
    if (!/^(https?:)?\/\//i.test(url) && url.charAt(0) !== '/') { done('failed', 'Invalid sound url'); return; }
    each(audios.splice(0, audios.length), function (a0) { try { a0.pause(); if (a0._unduck) a0._unduck(); } catch (e) { /* ignore */ } });
    var a = new window.Audio();
    var count = 0, ducked = false;
    var unduck = function () { if (ducked) { ducked = false; duck(false); } };
    a._unduck = unduck;
    audios.push(a);
    a.volume = volume;
    a.onplaying = function () { if (!ducked) { ducked = true; duck(true); } };
    a.onended = function () {
      count++;
      if (count < times) { a.currentTime = 0; tryPlay(a); } else unduck();
    };
    a.onerror = function () { unduck(); done('failed', 'Cannot play the sound (address or format)'); };
    // Never longer than 2 minutes (like the TV app).
    setTimeout(function () { try { a.pause(); } catch (e) { /* ignore */ } unduck(); }, 120000);
    a.src = url;
    var pr = tryPlay(a);
    if (pr) {
      pr.then(function () { done('acked', 'Playing sound' + (times > 1 ? ' ×' + times : '')); }, function (err) {
        unduck();
        done('failed', err && err.name === 'NotAllowedError' ? 'Blocked by the browser autoplay policy: press OK once on the screen' : 'Cannot play: ' + (err && err.message));
      });
    } else {
      setTimeout(function () { done(a.paused ? 'failed' : 'acked', a.paused ? 'Sound did not start' : 'Playing sound'); }, 2000);
    }
  }
  /** Built-in tone with Web Audio; cb(ok, message). */
  function playTones(name, volume, times, cb) {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { cb(false, unsupported('no Web Audio in this browser')); return; }
    try { audioCtx = audioCtx || new AC(); } catch (e) { cb(false, unsupported('Web Audio failed: ' + e.message)); return; }
    var go = function () {
      var pat = TONES[name] || TONES.chime, len = 0, t0 = audioCtx.currentTime + 0.05;
      each(pat, function (n) { len = Math.max(len, n[1] + n[2]); });
      for (var r = 0; r < times; r++) {
        each(pat, function (n) {
          var osc = audioCtx.createOscillator(), g = audioCtx.createGain();
          var st = t0 + r * (len + 0.3) + n[1];
          osc.type = 'sine';
          osc.frequency.value = n[0];
          g.gain.setValueAtTime(0.0001, st);
          g.gain.exponentialRampToValueAtTime(Math.max(0.001, volume), st + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, st + n[2]);
          osc.connect(g);
          g.connect(audioCtx.destination);
          osc.start(st);
          osc.stop(st + n[2] + 0.05);
        });
      }
      duck(true);
      setTimeout(function () { duck(false); }, Math.round(times * (len + 0.3) * 1000) + 200);
    };
    if (audioCtx.state === 'suspended' && audioCtx.resume) { try { audioCtx.resume(); } catch (e) { /* ignore */ } }
    setTimeout(function () {
      if (audioCtx.state === 'suspended') { cb(false, 'Blocked by the browser autoplay policy: press OK once on the screen'); return; }
      go();
      cb(true, 'Playing ' + name + (times > 1 ? ' ×' + times : ''));
    }, audioCtx.state === 'suspended' ? 800 : 0);
  }

  // =================================================================== remote / keyboard / gestures
  var K = { ENTER: 13, LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40 };
  function isBackKey(e) {
    var k = e.keyCode || e.which;
    return k === 27 || k === 461 || k === 10009 || k === 166 || e.key === 'GoBack' || e.key === 'BrowserBack' || (k === 8 && !isTyping());
  }
  function isOkKey(e) { var k = e.keyCode || e.which; return k === 13 || k === 23 || e.key === 'Enter' || e.key === 'Accept'; }
  function isTyping() {
    var a = document.activeElement;
    return !!(a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA'));
  }
  function digitOf(e) {
    var k = e.keyCode || e.which;
    if (k >= 48 && k <= 57) return String(k - 48);
    if (k >= 96 && k <= 105) return String(k - 96);
    return /^[0-9]$/.test(e.key || '') ? e.key : null;
  }

  function onGesture() {
    var first = !S.gestured;
    S.gestured = true;
    var h = $('hc-hint');
    if (h) h.className = 'hc-hint';
    if (S.soundOk !== true) {
      S.soundOk = true;
      applyAudio();
      // YouTube iframes were muted for autoplay: reload them with sound.
      if (S.mode === 'player' && first && ROOT.querySelector && ROOT.querySelector('iframe[src*="mute=1"]') && !S.muted) render();
    }
    if (audioCtx && audioCtx.state === 'suspended' && audioCtx.resume) { try { audioCtx.resume(); } catch (e) { /* ignore */ } }
    if (alarm.a && alarm.blocked) playAlarm();
    if (S.mode === 'player') { requestFullscreen(); requestWakeLock(); }
  }
  function requestFullscreen() {
    if (query('fs') === '0') return;
    var d = document.documentElement;
    if (document.fullscreenElement || document.webkitFullscreenElement) return;
    var f = d.requestFullscreen || d.webkitRequestFullscreen || d.webkitRequestFullScreen || d.mozRequestFullScreen || d.msRequestFullscreen;
    if (!f) return;
    try {
      var p = f.call(d);
      if (p && typeof p.then === 'function') p.then(null, function () { /* not allowed: ignore */ });
    } catch (e) { /* ignore */ }
  }
  var wakeLock = null;
  function requestWakeLock() {
    if (!navigator.wakeLock || !navigator.wakeLock.request || wakeLock || document.hidden) return;
    try {
      navigator.wakeLock.request('screen').then(function (l) {
        wakeLock = l;
        on(l, 'release', function () { wakeLock = null; });
      }, function () { wakeLock = null; });
    } catch (e) { /* ignore */ }
  }
  on(document, 'visibilitychange', function () { if (!document.hidden && S.mode === 'player') requestWakeLock(); });

  var okDownAt = 0, seqKeys = [], digits = '', backTimes = [], cornerTimes = [];
  function pushTimes(list, max) {
    var t0 = now();
    list.push(t0);
    while (list.length && t0 - list[0] > 3000) list.shift();
    return list.length >= max;
  }

  on(document, 'keydown', function (e) {
    onGesture();
    if (S.mode === 'setup') { setupKeys(e); return; }
    if (S.mode !== 'player') return;
    if (pin.open) { pinKeys(e); return; }
    if (menu.open) { menuKeys(e); return; }
    var k = e.keyCode || e.which;
    if (isBackKey(e)) {
      e.preventDefault();
      if (messageVisible()) hideMessage();
      else if (pushTimes(backTimes, 5)) { backTimes = []; openSettings(); }
      return;
    }
    // A guest pressing a key while the TV is "off" switches the picture on (like the TV app).
    if (isBlack() && desiredOff(S.content) && !isEmergency(S.content) && !isOkKey(e) && k !== 93) {
      S.override = true;
      render();
      return;
    }
    if (isOkKey(e)) {
      e.preventDefault();
      if (!okDownAt) okDownAt = now();
      else if (now() - okDownAt >= 3000) { okDownAt = -1; openSettings(); }
      return;
    }
    if (k >= K.LEFT && k <= K.DOWN) {
      e.preventDefault();
      seqKeys.push(k);
      if (seqKeys.length > 4) seqKeys.shift();
      if (seqKeys.join(',') === [K.UP, K.UP, K.DOWN, K.DOWN].join(',')) { seqKeys = []; openSettings(); }
      return;
    }
    var dg = digitOf(e);
    if (dg !== null) {
      digits = (digits + dg).slice(-4);
      if (digits === '1234') { digits = ''; openSettings(); }
      return;
    }
    if (k === 93 || e.key === 'ContextMenu') { e.preventDefault(); openSettings(); }
  });
  on(document, 'keyup', function (e) {
    if (S.mode !== 'player' || pin.open || menu.open || !isOkKey(e)) { okDownAt = 0; return; }
    var held = okDownAt > 0 ? now() - okDownAt : 0;
    var wasLong = okDownAt === -1;
    okDownAt = 0;
    if (wasLong) return;
    if (held >= 3000) { openSettings(); return; }
    if (messageVisible()) { hideMessage(); return; }
    if (isBlack() && desiredOff(S.content) && !isEmergency(S.content)) { S.override = true; render(); }
  });
  on(document, 'mousedown', function (e) {
    onGesture();
    if (S.mode !== 'player' || menu.open || pin.open) return;
    var x = e.clientX, y = e.clientY;
    if (x < (window.innerWidth || 1000) * 0.12 && y < (window.innerHeight || 600) * 0.15 && pushTimes(cornerTimes, 5)) { cornerTimes = []; openSettings(); }
    else if (messageVisible()) hideMessage();
  });
  on(document, 'touchstart', function () { onGesture(); });
  on(document, 'contextmenu', function (e) { if (S.mode === 'player') e.preventDefault(); });

  /** Kiosk: the browser's Back must not leave the player (TV browsers map the remote's Back to history.back). */
  var trapArmed = false;
  function armBackTrap() {
    if (trapArmed || !window.history || !window.history.pushState) return;
    trapArmed = true;
    try { window.history.pushState({ hc: 1 }, ''); } catch (e) { return; }
    on(window, 'popstate', function () {
      try { window.history.pushState({ hc: 1 }, ''); } catch (e) { /* ignore */ }
      if (S.mode === 'player' && !menu.open && !pin.open) {
        if (messageVisible()) hideMessage();
        else if (pushTimes(backTimes, 5)) { backTimes = []; openSettings(); }
      } else if (menu.open) closeMenu();
    });
  }

  // ------------------------------------------------------------------- setup screen keys (arrow navigation)
  function focusables(container) {
    return toArr((container || document).querySelectorAll('input, button, [data-act], [data-key]')).filter(function (n) { return !n.disabled && n.offsetParent !== null; });
  }
  function moveFocus(list, delta) {
    if (!list.length) return;
    var i = -1;
    for (var j = 0; j < list.length; j++) if (list[j] === document.activeElement) i = j;
    i = i < 0 ? 0 : (i + delta + list.length) % list.length;
    list[i].focus();
  }
  function setupKeys(e) {
    var k = e.keyCode || e.which;
    var list = focusables($('hc-setup'));
    if (k === K.DOWN || k === K.UP) { e.preventDefault(); moveFocus(list, k === K.DOWN ? 1 : -1); return; }
    if (isOkKey(e) && document.activeElement && document.activeElement.tagName === 'INPUT') {
      e.preventDefault();
      var a = document.activeElement;
      if (a.id === 'hc-f-key') manualConnect(); else moveFocus(list, 1);
      return;
    }
    if (isBackKey(e)) e.preventDefault();
  }

  // ------------------------------------------------------------------- hidden settings menu (+ PIN)
  var menu = { open: false, confirm: null };
  var pin = { open: false, value: '' };
  function openSettings() {
    if (menu.open || pin.open || S.mode !== 'player') return;
    if (S.pinHash) openPin(); else openMenu();
  }
  function openPin() {
    pin.open = true;
    pin.value = '';
    var keys = '';
    var labels = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', 'OK'];
    for (var i = 0; i < labels.length; i++) keys += '<button type="button" class="hc-key" data-key="' + esc(labels[i]) + '">' + esc(labels[i]) + '</button>';
    var m = $('hc-menu');
    m.innerHTML = '<div class="hc-menu-card hc-pin"><h2>' + esc(t('Enter the settings PIN')) + '</h2><div class="hc-pin-dots" id="hc-pin-dots"></div>' +
      '<div class="hc-pin-err" id="hc-pin-err"></div><div class="hc-keys">' + keys + '</div></div>';
    m.className = 'hc-menu hc-on';
    each(toArr(m.querySelectorAll('[data-key]')), function (b) { on(b, 'click', function () { pinPress(b.getAttribute('data-key')); }); });
    pinDots();
    m.querySelector('[data-key="1"]').focus();
  }
  function pinDots() {
    var d = $('hc-pin-dots');
    if (!d) return;
    var s = '';
    for (var i = 0; i < 4; i++) s += '<span class="' + (i < pin.value.length ? 'hc-full-dot' : '') + '"></span>';
    d.innerHTML = s;
  }
  function pinPress(key) {
    var err = $('hc-pin-err');
    if (key === '⌫') { pin.value = pin.value.slice(0, -1); pinDots(); return; }
    if (key === 'OK') { pinCheck(); return; }
    if (/^[0-9]$/.test(key) && pin.value.length < 4) {
      pin.value += key;
      if (err) err.textContent = '';
      pinDots();
      if (pin.value.length === 4) setTimeout(pinCheck, 150); // the settings PIN has 4 digits
    }
  }
  function pinCheck() {
    if (sha256(pin.value) === String(S.pinHash).toLowerCase()) {
      pin.open = false;
      openMenu();
      return;
    }
    pin.value = '';
    pinDots();
    var err = $('hc-pin-err');
    if (err) err.textContent = t('Wrong PIN');
  }
  function gridMove(list, k) {
    var i = -1;
    for (var j = 0; j < list.length; j++) if (list[j] === document.activeElement) i = j;
    if (i < 0) { list[0].focus(); return; }
    var n = k === K.LEFT ? i - 1 : k === K.RIGHT ? i + 1 : k === K.UP ? i - 3 : i + 3;
    if (n >= 0 && n < list.length) list[n].focus();
  }
  function pinKeys(e) {
    var k = e.keyCode || e.which;
    e.preventDefault();
    if (isBackKey(e)) { closeMenu(); return; }
    var dg = digitOf(e);
    if (dg !== null) { pinPress(dg); return; }
    if (k >= K.LEFT && k <= K.DOWN) { gridMove(toArr($('hc-menu').querySelectorAll('[data-key]')), k); return; }
    if (isOkKey(e) && document.activeElement && document.activeElement.getAttribute('data-key')) pinPress(document.activeElement.getAttribute('data-key'));
  }

  function openMenu() {
    menu.open = true;
    menu.confirm = null;
    var c = S.content || {};
    var info = [
      [t('Screen'), (c.room && c.room.number) || S.room || '-'],
      [t('Server'), pageRoot()],
      [t('Device ID'), S.deviceId],
      [t('Version'), 'web-' + VERSION + ' · ' + OS],
      ['', S.online ? '● online' : '○ ' + t('Offline — showing the last content')]
    ];
    var rows = '';
    each(info, function (r) { rows += '<div><b>' + esc(r[0]) + '</b> ' + esc(r[1]) + '</div>'; });
    var langName = { en: 'English', gu: 'ગુજરાતી', hi: 'हिन्दी' }[LANG] || LANG;
    var acts = [
      ['reload', t('Reload')], ['sound', t('Sound on')], ['fs', t('Full screen')], ['lang', t('Language') + ': ' + langName],
      ['repair', t('Re-pair this screen')], ['reset', t('Reset the player')], ['close', t('Close')]
    ];
    var btns = '';
    each(acts, function (a) { btns += '<button type="button" class="hc-act" data-act="' + a[0] + '">' + esc(a[1]) + '</button>'; });
    var m = $('hc-menu');
    m.innerHTML = '<div class="hc-menu-card"><h2>' + esc(t('Settings')) + '</h2><div class="hc-menu-info">' + rows + '</div>' +
      '<div class="hc-acts">' + btns + '</div><div class="hc-menu-note" id="hc-menu-note"></div></div>';
    m.className = 'hc-menu hc-on';
    each(toArr(m.querySelectorAll('[data-act]')), function (b) { on(b, 'click', function () { menuAct(b.getAttribute('data-act')); }); });
    m.querySelector('[data-act]').focus();
  }
  function closeMenu() {
    menu.open = false;
    pin.open = false;
    var m = $('hc-menu');
    if (m) { m.className = 'hc-menu'; m.innerHTML = ''; }
  }
  function menuKeys(e) {
    var k = e.keyCode || e.which;
    e.preventDefault();
    if (isBackKey(e)) { closeMenu(); return; }
    if (k === K.UP || k === K.DOWN || k === K.LEFT || k === K.RIGHT) { moveFocus(focusables($('hc-menu')), k === K.UP || k === K.LEFT ? -1 : 1); return; }
    if (isOkKey(e) && document.activeElement && document.activeElement.getAttribute('data-act')) menuAct(document.activeElement.getAttribute('data-act'));
  }
  function menuAct(act) {
    var note = $('hc-menu-note');
    if ((act === 'repair' || act === 'reset') && menu.confirm !== act) {
      menu.confirm = act;
      if (note) note.textContent = t('Press OK again to confirm');
      return;
    }
    menu.confirm = null;
    switch (act) {
      case 'reload': window.location.reload(); break;
      case 'sound': S.muted = false; store.set('hc_muted', '0'); S.soundOk = true; applyAudio(); closeMenu(); break;
      case 'fs': requestFullscreen(); closeMenu(); break;
      case 'lang': {
        var order = ['en', 'gu', 'hi'];
        LANG = order[(order.indexOf(LANG) + 1) % order.length];
        if (!DICT[LANG]) LANG = 'en';
        store.set('hc_lang', LANG);
        closeMenu();
        openMenu();
        var lb = $('hc-menu').querySelector('[data-act="lang"]');
        if (lb) lb.focus();
        break;
      }
      case 'repair':
      case 'reset':
        stopLoops();
        each(['hc_token', 'hc_content', 'hc_hash', 'hc_room', 'hc_pin', 'hc_done'], function (k2) { store.del(k2); });
        if (act === 'reset') each(['hc_device_id', 'hc_volume', 'hc_muted', 'hc_lang', 'hc_server', 'hc_cec'], function (k3) { store.del(k3); });
        window.location.reload();
        break;
      default: closeMenu();
    }
  }

  // =================================================================== boot
  // Test / support hook (read-only state; never the token).
  window.HCPlayer = {
    version: VERSION,
    state: function () {
      return { mode: S.mode, deviceId: S.deviceId, registered: !!S.token, hash: S.hash, contentMode: S.content ? S.content.mode : null,
        itemId: S.currentItemId, online: S.online, black: isBlack(), volume: S.volume, muted: S.muted, soundOk: S.soundOk };
    },
    settings: function () { openSettings(); }
  };

  function boot() {
    measure();
    if (S.token) startPlayer(); else showSetup(null);
  }
  if (document.readyState === 'loading') on(document, 'DOMContentLoaded', boot); else boot();
})(window, document);
