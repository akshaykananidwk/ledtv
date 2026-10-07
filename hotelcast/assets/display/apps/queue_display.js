/*
 * Token display — counters with their current token, last calls, waiting counts. A new call (or a
 * recall) blinks for 5 s, shows a big "A-025 → Counter 3" banner, plays the chime and, when the
 * WebView has speechSynthesis, speaks "Token A 25, Counter 3" in the page language.
 * Polls every 2 s (the runtime's minimum is 3 s, so this app asks for the next refresh itself).
 * ES5 only (old Android TV WebViews).
 */
(function (w, d) {
  'use strict';
  var keys = {}, blinkUntil = {}, flashTimer = null, pollTimer = null, data = null;
  var BLINK_MS = 5000;

  function counterHtml(c, now) {
    var blink = blinkUntil[c.id] && blinkUntil[c.id] > now;
    var sub = c.service + (c.room ? ' · ' + c.room : '');
    sub = sub.replace(/^ · | · $/g, '');
    return '<div class="qd-counter hc-card' + (c.token ? ' has-token' : '') + (blink ? ' qd-blink' : '') + '" data-counter="' + c.id + '">' +
      '<div class="qd-cname">' + HC.esc(c.name) + '</div>' +
      '<div class="qd-token">' + (c.token ? HC.esc(c.token) : '—') + '</div>' +
      '<div class="qd-csub">' + HC.esc(sub) + '</div></div>';
  }

  function render() {
    if (!data) { return; }
    var now = new Date().getTime(), h = '', i, el;
    var counters = data.counters || [];
    el = d.getElementById('qdCounters');
    if (el && counters.length) {
      for (i = 0; i < counters.length; i++) { h += counterHtml(counters[i], now); }
      el.innerHTML = h;
      el.className = 'qd-counters qd-n' + Math.min(counters.length, 12);
    }
    el = d.getElementById('qdRecent');
    if (el) {
      h = '';
      var recent = data.recent || [];
      for (i = 0; i < recent.length; i++) { h += '<div class="qd-row"><b>' + HC.esc(recent[i].token) + '</b><span>' + HC.esc(recent[i].counter) + '</span></div>'; }
      el.innerHTML = h;
    }
    el = d.getElementById('qdWaiting');
    if (el) {
      h = '';
      var services = data.services || [];
      for (i = 0; i < services.length; i++) { h += '<div class="qd-row"><span>' + HC.esc(services[i].name) + '</span><b>' + (services[i].waiting | 0) + '</b></div>'; }
      el.innerHTML = h;
    }
  }

  function chime() {
    var a = d.getElementById('qdChime');
    if (!a || !a.play) { return; }
    try {
      a.currentTime = 0;
      var p = a.play();
      if (p && typeof p['catch'] === 'function') { p['catch'](function () {}); }
    } catch (e) { /* autoplay blocked / no audio: ignore */ }
  }

  function speak(texts) {
    var s = w.speechSynthesis, U = w.SpeechSynthesisUtterance;
    if (!s || !U || !texts.length) { return; }
    try {
      var lang = data.speech_lang || 'en-IN', voices = s.getVoices ? s.getVoices() : [], voice = null, i;
      for (i = 0; i < voices.length; i++) {
        if (voices[i].lang && voices[i].lang.replace('_', '-').toLowerCase().indexOf(lang.substr(0, 2).toLowerCase()) === 0) { voice = voices[i]; break; }
      }
      for (i = 0; i < texts.length; i++) {
        var u = new U(texts[i]);
        u.lang = lang;
        u.rate = 0.9;
        if (voice) { u.voice = voice; }
        s.speak(u);
      }
    } catch (e) { /* speech not available: ignore */ }
  }

  function flash(c) {
    var box = d.getElementById('qdFlash');
    if (!box) { return; }
    d.getElementById('qdFlashToken').textContent = c.token;
    d.getElementById('qdFlashCounter').textContent = c.name;
    box.style.display = '';
    if (flashTimer) { clearTimeout(flashTimer); }
    flashTimer = setTimeout(function () { box.style.display = 'none'; render(); }, BLINK_MS);
  }

  function announce(list) {
    var now = new Date().getTime(), say = [], i;
    for (i = 0; i < list.length; i++) {
      blinkUntil[list[i].id] = now + BLINK_MS;
      if (list[i].say) { say.push(list[i].say); }
    }
    render();
    flash(list[list.length - 1]);
    if (data.chime) { chime(); }
    if (data.speak && say.length) { setTimeout(function () { speak(say); }, data.chime ? 1300 : 0); }
  }

  function schedulePoll() {
    var C = HC.config || {};
    if (!C.data_url || !(C.refresh_sec > 0 && C.refresh_sec < 3)) { return; }
    if (pollTimer) { clearTimeout(pollTimer); }
    pollTimer = setTimeout(function () { pollTimer = null; HC.refresh(); }, C.refresh_sec * 1000);
  }

  w.HCApp = {
    update: function (newData, initial) {
      data = newData;
      var counters = data.counters || [], changed = [], i;
      for (i = 0; i < counters.length; i++) {
        var c = counters[i], k = c.key || '';
        if (!initial && k && keys[c.id] !== k) { changed.push(c); }
        keys[c.id] = k;
      }
      if (changed.length) { announce(changed); } else { render(); }
      schedulePoll();
    }
  };
})(window, document);
