/*
 * Menu board — lays the dishes out in pages that fit the screen (columns are filled top to bottom,
 * a category heading never stays alone at the bottom of a column) and rotates the pages.
 * Re-renders only when the data changed (sold-out switch, new dish …). ES5 only (old TV WebViews).
 */
(function (w, d) {
  'use strict';
  var last = '', data = null, resizeTimer = null;

  /** Stop the rotation timers of the previous render (HC.rotate keeps them on the container). */
  function stopTimers(board) {
    var olds = board.querySelectorAll('.hc-slides'), k;
    for (k = 0; k < olds.length; k++) {
      if (olds[k]._hcRotate) { clearInterval(olds[k]._hcRotate); olds[k]._hcRotate = null; }
    }
  }

  /** Value for style="background-image:url('…')": quotes, brackets and spaces percent-encoded. */
  function cssUrl(u) {
    return HC.esc(String(u).replace(/[\\'"()\s<>]/g, function (c) { return '%' + c.charCodeAt(0).toString(16).toUpperCase(); }));
  }

  function food(f) {
    return f ? '<i class="mb-food mb-' + HC.esc(f) + '"></i>' : '';
  }

  /** Same markup as MenuBoardApp::row(). */
  function row(it, desc, photo, t) {
    var h = '<div class="mb-item' + (it.sold ? ' is-sold' : '') + (it.special ? ' is-special' : '') + '">';
    if (photo && it.photo) { h += '<img class="mb-thumb" alt="" src="' + HC.esc(it.photo) + '">'; }
    h += '<div class="mb-main"><div class="mb-line">' + food(it.food) + '<span class="mb-name">' + HC.esc(it.name) + '</span>';
    if (it.badge) { h += '<span class="mb-badge">' + HC.esc(it.badge) + '</span>'; }
    h += '<span class="mb-dots"></span>';
    if (it.old) { h += '<s class="mb-old">' + HC.esc(it.old) + '</s>'; }
    h += '<span class="mb-price">' + HC.esc(it.price) + '</span></div>';
    if (it.sold) {
      h += '<div class="mb-sold">' + HC.esc(t.sold) + '</div>';
    } else if (desc && it.desc) {
      h += '<div class="mb-desc">' + HC.esc(it.desc) + '</div>';
    }
    return h + '</div></div>';
  }

  function card(it, photo, t) {
    var h = '<div class="mb-card hc-card' + (it.sold ? ' is-sold' : '') + '">';
    if (photo && it.photo) {
      h += '<div class="mb-card-img" style="background-image:url(\'' + cssUrl(it.photo) + '\')"></div>';
    } else {
      h += '<div class="mb-card-img mb-noimg">' + food(it.food) + '</div>';
    }
    h += '<div class="mb-card-body"><div class="mb-card-cat">' + HC.esc(it.cat) + (it.badge ? ' · <b>' + HC.esc(it.badge) + '</b>' : '') + '</div>' +
      '<div class="mb-card-name">' + food(it.food) + HC.esc(it.name) + '</div><div class="mb-card-foot">' +
      (it.sold ? '<span class="mb-sold">' + HC.esc(t.sold) + '</span>' : (it.old ? '<s class="mb-old">' + HC.esc(it.old) + '</s>' : '')) +
      '<span class="mb-price">' + HC.esc(it.price) + '</span></div></div></div>';
    return h;
  }

  function el(html) {
    var box = d.createElement('div');
    box.innerHTML = html;
    return box.firstChild;
  }

  function hasClass(e, c) {
    return e && e.className && (' ' + e.className + ' ').indexOf(' ' + c + ' ') >= 0;
  }

  function overflows(box) {
    return box.scrollHeight > box.clientHeight + 1;
  }

  /** Put blocks (array of {html, cat}) into pages of `cols` columns inside host; rotate them. */
  function paginate(host, blocks, cols, sec, grid) {
    host.innerHTML = '';
    var pages = d.createElement('div');
    pages.className = 'mb-pages hc-slides';
    host.appendChild(pages);
    var page = null, col = null, ci = 0, i, k;
    function newPage() {
      page = d.createElement('div');
      page.className = 'mb-page hc-slide is-active' + (grid ? ' mb-grid' : '');
      for (k = 0; k < cols; k++) {
        var c = d.createElement('div');
        c.className = 'mb-col';
        page.appendChild(c);
      }
      if (pages.lastChild) { pages.lastChild.className = pages.lastChild.className.replace(/\s*is-active/g, ''); }
      pages.appendChild(page);
      ci = 0;
      col = page.firstChild;
    }
    function nextCol() {
      ci++;
      if (ci >= cols) { newPage(); } else { col = page.childNodes[ci]; }
    }
    newPage();
    var lastCat = '';
    for (i = 0; i < blocks.length; i++) {
      var b = el(blocks[i].html);
      if (blocks[i].head) { lastCat = blocks[i].html; }
      col.appendChild(b);
      if (overflows(col) && col.childNodes.length > 1) {
        col.removeChild(b);
        var carry = null;
        if (hasClass(col.lastChild, 'mb-cat') && col.childNodes.length > 1) { carry = col.lastChild; col.removeChild(carry); }
        nextCol();
        if (carry) {
          col.appendChild(carry);
        } else if (!blocks[i].head && lastCat && !grid) {
          col.appendChild(el(lastCat.replace('mb-cat"', 'mb-cat mb-cont"'))); // category continues
        }
        col.appendChild(b);
      }
    }
    // Drop empty trailing columns' pages (cannot happen) and start the rotation.
    var all = pages.childNodes;
    for (i = 0; i < all.length; i++) { all[i].className = all[i].className.replace(/\s*is-active/g, ''); }
    HC.rotate(pages, sec);
  }

  function heroHtml(s, t) {
    return '<div class="mb-hero-slide hc-slide">' + (s.photo ? '<div class="mb-hero-img" style="background-image:url(\'' + cssUrl(s.photo) + '\')"></div>' : '') +
      '<div class="mb-hero-text"><div class="mb-hero-tag">' + HC.esc(t.special) + '</div><div class="mb-hero-name">' + food(s.food) + HC.esc(s.name) + '</div>' +
      (s.desc ? '<div class="mb-hero-desc">' + HC.esc(s.desc) + '</div>' : '') +
      '<div class="mb-hero-price">' + (s.old ? '<s class="mb-old">' + HC.esc(s.old) + '</s> ' : '') + HC.esc(s.price) + '</div></div></div>';
  }

  function render() {
    var board = d.getElementById('mbBoard');
    if (!board || !data) { return; }
    var t = data.t || {}, cats = data.categories || [], i, j, blocks = [];
    var layout = data.layout || 'list', cols = Math.max(1, Math.min(3, data.columns || 2)), sec = data.page_sec || 12;
    board.className = board.className.replace(/\s*mb-layout-\w+/g, '').replace(/\s*mb-cols-\d/g, '') + ' mb-layout-' + layout + ' mb-cols-' + cols;
    if (!cats.length) {
      stopTimers(board);
      board.innerHTML = '<div class="hc-empty">' + HC.esc(t.empty) + '</div>';
      return;
    }
    for (i = 0; i < cats.length; i++) {
      if (layout !== 'grid') { blocks.push({ html: '<div class="mb-cat">' + HC.esc(cats[i].name) + '</div>', head: true }); }
      for (j = 0; j < cats[i].items.length; j++) {
        var it = cats[i].items[j];
        blocks.push({ html: layout === 'grid' ? card(it, data.show_photos, t) : row(it, data.show_desc, data.show_photos, t), head: false });
      }
    }
    stopTimers(board);
    var specials = data.specials || [];
    if (layout === 'special' && specials.length) {
      var hero = '';
      for (i = 0; i < specials.length; i++) { hero += heroHtml(specials[i], t); }
      board.innerHTML = '<div class="mb-hero hc-card hc-slides" id="mbHero">' + hero + '</div><div class="mb-list" id="mbList"></div>';
      HC.rotate(d.getElementById('mbHero'), sec);
      paginate(d.getElementById('mbList'), blocks, cols > 2 ? 2 : 1, sec, false);
    } else {
      board.innerHTML = '<div class="mb-list" id="mbList"></div>';
      paginate(d.getElementById('mbList'), blocks, layout === 'grid' ? 1 : cols, sec, layout === 'grid');
    }
  }

  w.HCApp = {
    update: function (newData) {
      var s;
      try { s = JSON.stringify(newData); } catch (e) { s = String(Math.random()); }
      if (s === last) { return; } // unchanged: keep the page rotation running
      last = s;
      data = newData;
      render();
    }
  };

  w.addEventListener('resize', function () {
    if (resizeTimer) { clearTimeout(resizeTimer); }
    resizeTimer = setTimeout(render, 400);
  });
})(window, document);
