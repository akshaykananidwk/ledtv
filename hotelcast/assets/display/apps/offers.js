/* Shop offers (#4) — core/Apps/OffersApp.php. Pages of offer cards (HC.rotate), live "ends in"
 * countdowns; when an offer ends it is hidden at once and new data is fetched. ES5 only. */
(function (w, d) {
  'use strict';
  var text = {}, tick = null, asked = 0, last = null;

  /** Shrink the texts of a card together while its whole text block (badge, title, text, prices, countdown) is taller than the card. */
  function fitInfo(t) {
    var info = t.parentNode && t.parentNode.parentNode, root, els, base = [], f = 1, guard = 12, i;
    if (!info || (' ' + info.className + ' ').indexOf(' of-info ') < 0 || info.scrollHeight <= info.clientHeight + 1) { return; }
    root = parseFloat(w.getComputedStyle(d.documentElement).fontSize) || 16;
    els = info.querySelectorAll('.of-title, .of-desc, .of-price, .of-old, .of-ends');
    for (i = 0; i < els.length; i++) { base.push(parseFloat(w.getComputedStyle(els[i]).fontSize) / root); }
    while (guard-- > 0 && info.scrollHeight > info.clientHeight + 1) {
      f *= 0.9;
      for (i = 0; i < els.length; i++) { els[i].style.fontSize = Math.max(0.8, base[i] * f) + 'rem'; }
    }
  }

  function fitAll(box) {
    var t = box.querySelectorAll('.of-title, .of-desc, .of-price, .of-old, .of-ends'), k;
    for (k = 0; k < t.length; k++) { t[k].style.fontSize = ''; } // start again from the CSS sizes
    t = box.querySelectorAll('.of-title');
    for (k = 0; k < t.length; k++) { HC.fitText(t[k], 1.2); fitInfo(t[k]); }
  }

  function countdown(sec) {
    sec = Math.max(0, sec);
    var days = Math.floor(sec / 86400);
    var hms = HC.pad(Math.floor((sec % 86400) / 3600)) + ':' + HC.pad(Math.floor((sec % 3600) / 60)) + ':' + HC.pad(sec % 60);
    return (days > 0 ? String(text.day || ':nd').replace(':n', days) + ' ' : '') + hms;
  }

  function card(o) {
    var h = '<div class="of-card hc-card' + (o.image ? ' has-img' : '') + '">';
    if (o.image) { h += '<div class="of-img"><img alt="" src="' + HC.esc(o.image) + '"></div>'; }
    h += '<div class="of-info"><div class="of-head">';
    if (o.badge) { h += '<div class="of-bw"><span class="hc-badge of-badge">' + HC.esc(o.badge) + '</span></div>'; }
    h += '<div class="of-title">' + HC.esc(o.title) + '</div></div>';
    if (o.description) { h += '<div class="of-desc">' + HC.esc(o.description).replace(/\n/g, '<br>') + '</div>'; }
    if (o.price || o.old_price) {
      h += '<div class="of-prices">' + (o.price ? '<span class="of-price">' + HC.esc(o.price) + '</span>' : '') +
        (o.old_price ? '<s class="of-old">' + HC.esc(o.old_price) + '</s>' : '') + '</div>';
    }
    if (o.ends) {
      h += '<div class="of-ends" data-ends="' + parseInt(o.ends, 10) + '"><span>' + HC.esc(text.ends_in) + '</span> <b>' +
        HC.esc(countdown(Math.floor((o.ends - HC.now()) / 1000))) + '</b></div>';
    }
    h += '</div>';
    if (o.discount) { h += '<div class="of-disc"><b>' + parseInt(o.discount, 10) + '%</b><span>' + HC.esc(text.off) + '</span></div>'; }
    return h + '</div>';
  }

  function ticker() {
    var els = d.querySelectorAll('[data-ends]'), now = HC.now(), ended = false, i;
    for (i = 0; i < els.length; i++) {
      var left = Math.floor((parseFloat(els[i].getAttribute('data-ends')) - now) / 1000);
      var b = els[i].getElementsByTagName('b')[0];
      if (b) { b.textContent = countdown(left); }
      if (left <= 0) {
        ended = true;
        var c = els[i].parentNode && els[i].parentNode.parentNode;
        if (c && c.className.indexOf('of-gone') < 0) { c.className += ' of-gone'; }
      }
    }
    // An offer just ended: ask the server for the new list (at most every 10 s).
    if (ended && now - asked > 10000) { asked = now; HC.refresh(); }
  }

  w.HCApp = {
    update: function (data, initial) {
      var list = d.getElementById('ofList');
      if (!list || !data) { return; }
      text = data.text || {};
      // Same offers as before: keep the running rotation (the page polls every 15 s).
      var sig = JSON.stringify([data.offers, data.layout, data.per_page, data.rotate_sec]);
      if (!initial && sig === last) { return; }
      last = sig;
      if (!initial) {
        var offers = data.offers || [], per = data.per_page || 1, html = '', i;
        for (i = 0; i < offers.length; i++) {
          if (i % per === 0) { html += (i ? '</div>' : '') + '<div class="hc-slide of-page">'; }
          html += card(offers[i]);
        }
        if (html) { html += '</div>'; }
        list.innerHTML = html || '<div class="hc-empty">' + HC.esc(text.empty) + '</div>';
      }
      HC.rotate(list, data.rotate_sec || 8, fitAll);
      if (d.readyState !== 'complete') { w.addEventListener('load', function () { fitAll(list); }); } // web fonts change the text height
      if (!tick) { tick = setInterval(ticker, 1000); }
      ticker();
    }
  };
})(window, document);
