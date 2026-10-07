/* PDF → slides (#12): render each page with pdf.js in the browser and upload it to
 * ajax.php?action=designer_pdfpage (idempotent per batch + page). Config: <script id="pdfConfig">. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const cfgEl = document.getElementById('pdfConfig');
    if (!cfgEl) return;
    const cfg = JSON.parse(cfgEl.textContent);
    const L = cfg.i18n;
    const HC = window.HC;
    const $ = (s) => document.querySelector(s);
    const fmt = (s, v) => s.replace(/\{(\w+)\}/g, (m, k) => (v[k] !== undefined ? v[k] : m));
    const form = $('#pdfForm');
    const fileEl = $('#pdfFile');
    const statusEl = $('#pdfStatus');
    const barWrap = $('#pdfBarWrap');
    const bar = $('#pdfBar');
    const pagesEl = $('#pdfPages');
    const resultEl = $('#pdfResult');
    const startBtn = $('#pdfStart');
    const retryBtn = $('#pdfRetry');
    const plEl = $('#pdfPlaylist');
    let running = false;
    let job = null; // {batch, file, doc, failed:Set, ids:{}}

    const status = (msg, cls) => { statusEl.className = 'mb-2 ' + (cls || 'text-muted'); statusEl.textContent = msg; };
    const progress = (frac) => {
      barWrap.hidden = frac === null;
      if (frac === null) return;
      const pct = Math.round(frac * 100);
      bar.style.width = pct + '%';
      bar.textContent = pct + '%';
    };
    const isPpt = (f) => /\.(pptx?|pps[xm]?|odp|key)$/i.test(f.name);
    const isPdf = (f) => /\.pdf$/i.test(f.name) || f.type === 'application/pdf';

    if (plEl) plEl.addEventListener('change', () => { $('#pdfPlaylistOpts').hidden = !plEl.checked; });
    fileEl.addEventListener('change', () => {
      const f = fileEl.files[0];
      resultEl.innerHTML = '';
      if (f && isPpt(f)) { status(L.pptx, 'text-danger fw-semibold'); $('#pptHint').classList.replace('alert-info', 'alert-warning'); }
      else if (f && !isPdf(f)) status(L.not_pdf, 'text-danger');
      else status('');
    });

    function postForm(action, fd) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', HC.ajaxUrl + '?action=' + encodeURIComponent(action));
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', HC.csrf);
        xhr.onload = () => {
          let j = null;
          try { j = JSON.parse(xhr.responseText); } catch (e) { /* not json */ }
          if (j && j.ok) resolve(j.data);
          else {
            const err = new Error((j && j.error && j.error.message) || (HC.t.error + ' (' + xhr.status + ')'));
            err.status = xhr.status;
            reject(err);
          }
        };
        xhr.onerror = () => { const err = new Error(HC.t.error + ' (network)'); err.status = 0; reject(err); };
        xhr.send(fd);
      });
    }
    async function withRetry(fn, tries) {
      let last;
      for (let i = 0; i < tries; i++) {
        try { return await fn(); } catch (e) {
          last = e;
          if (e.status && e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429) break; // validation: no retry
          await new Promise((r) => setTimeout(r, 800 * (i + 1)));
        }
      }
      throw last;
    }

    let pdfjs = null;
    async function lib() {
      if (pdfjs) return pdfjs;
      pdfjs = await import(cfg.pdfjs);
      pdfjs.GlobalWorkerOptions.workerSrc = cfg.worker;
      return pdfjs;
    }

    /** Render one page onto a canvas: 1920 px wide, letterboxed to 16:9 or original shape. */
    async function renderPage(doc, n, fit, bg) {
      const page = await doc.getPage(n);
      const base = page.getViewport({ scale: 1 });
      let cw;
      let ch;
      let scale;
      if (fit === 'letterbox') {
        cw = cfg.width; ch = cfg.height;
        scale = Math.min(cw / base.width, ch / base.height);
      } else {
        scale = cfg.width / base.width;
        if (base.height * scale > 4096) scale = 4096 / base.height;
        cw = Math.round(base.width * scale); ch = Math.round(base.height * scale);
      }
      const vp = page.getViewport({ scale });
      const out = document.createElement('canvas');
      out.width = cw;
      out.height = ch;
      const ctx = out.getContext('2d');
      ctx.fillStyle = bg;
      ctx.fillRect(0, 0, cw, ch);
      const ox = Math.round((cw - vp.width) / 2);
      const oy = Math.round((ch - vp.height) / 2);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(ox, oy, Math.round(vp.width), Math.round(vp.height));
      await page.render({ canvasContext: ctx, viewport: vp, transform: [1, 0, 0, 1, ox, oy] }).promise;
      page.cleanup();
      let blob = await new Promise((r) => out.toBlob(r, 'image/jpeg', 0.92));
      if (blob && blob.size > cfg.maxImage) blob = await new Promise((r) => out.toBlob(r, 'image/jpeg', 0.75));
      return { blob, thumb: out.toDataURL('image/jpeg', 0.5) };
    }

    function tile(n) {
      let fig = document.getElementById('pg' + n);
      if (!fig) {
        fig = document.createElement('figure');
        fig.id = 'pg' + n;
        fig.innerHTML = '<img alt=""><figcaption><span></span><span class="small"></span></figcaption>';
        fig.querySelector('figcaption span').textContent = '#' + n;
        pagesEl.appendChild(fig);
      }
      return fig;
    }
    function mark(n, text, cls) {
      const s = tile(n).querySelector('figcaption span:last-child');
      s.className = 'small ' + cls;
      s.textContent = text;
    }

    async function run(pages) {
      running = true;
      startBtn.disabled = true;
      retryBtn.hidden = true;
      resultEl.innerHTML = '';
      const fit = (form.querySelector('input[name="fit"]:checked') || {}).value || 'letterbox';
      const bg = $('#pdfBg').value || '#ffffff';
      const duration = Math.max(1, parseInt($('#pdfDuration').value, 10) || 10);
      let done = 0;
      progress(0);
      for (const n of pages) {
        status(fmt(L.page_of, { n, t: job.doc.numPages }));
        try {
          const { blob, thumb } = await renderPage(job.doc, n, fit, bg);
          tile(n).querySelector('img').src = thumb;
          const r = await withRetry(() => {
            const fd = new FormData();
            fd.append('batch', job.batch);
            fd.append('file_name', job.file.name);
            fd.append('page', String(n));
            fd.append('duration', String(duration));
            fd.append('image', blob, 'page-' + n + '.jpg');
            return postForm('designer_pdfpage', fd);
          }, 3);
          job.ids[n] = r.id;
          job.failed.delete(n);
          mark(n, r.created ? L.uploaded : L.already, 'text-success');
        } catch (e) {
          job.failed.add(n);
          mark(n, L.error, 'text-danger');
          tile(n).title = e.message;
        }
        done++;
        progress(done / pages.length);
      }
      const ok = Object.keys(job.ids).length;
      if (job.failed.size) {
        status(fmt(L.failed, { n: job.failed.size }), 'text-danger fw-semibold');
        retryBtn.hidden = false;
      } else {
        status(fmt(L.done, { n: ok }), 'text-success fw-semibold');
        const links = [['<a class="btn btn-light border" href="" data-lib><i class="bi bi-images"></i> </a>', cfg.contentUrl, L.open_library]];
        if (plEl && plEl.checked && ok) {
          status(L.playlist);
          try {
            const fd = new FormData();
            fd.append('batch', job.batch);
            fd.append('name', ($('#pdfPlaylistName').value || '').trim() || job.file.name.replace(/\.pdf$/i, ''));
            fd.append('duration', String(duration));
            fd.append('transition', $('#pdfTransition').value);
            const p = await withRetry(() => postForm('designer_pdfplaylist', fd), 3);
            status(fmt(L.done, { n: ok }) + ' ' + L.playlist_done, 'text-success fw-semibold');
            links.unshift(['<a class="btn btn-primary" href=""><i class="bi bi-collection-play"></i> </a>', p.edit_url, L.open_playlist]);
          } catch (e) {
            status(e.message, 'text-danger');
          }
        }
        resultEl.innerHTML = '';
        links.forEach(([html, href, label]) => {
          const w = document.createElement('span');
          w.innerHTML = html;
          const a = w.firstChild;
          a.href = href;
          a.appendChild(document.createTextNode(label));
          a.classList.add('me-2');
          resultEl.appendChild(a);
        });
      }
      running = false;
      startBtn.disabled = false;
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (running) return;
      const f = fileEl.files[0];
      if (!f) return;
      if (isPpt(f)) { status(L.pptx, 'text-danger fw-semibold'); return; }
      if (!isPdf(f)) { status(L.not_pdf, 'text-danger'); return; }
      pagesEl.innerHTML = '';
      status(L.reading);
      let doc;
      try {
        const lib_ = await lib();
        doc = await lib_.getDocument({ data: new Uint8Array(await f.arrayBuffer()), isEvalSupported: false, enableXfa: false }).promise;
      } catch (err) {
        status(err && /import|module|Unexpected/i.test(String(err.message)) && !pdfjs ? L.no_browser : L.bad_pdf, 'text-danger');
        return;
      }
      if (doc.numPages > cfg.maxPages) {
        status(fmt(L.too_many, { n: doc.numPages }), 'text-danger fw-semibold');
        doc.destroy();
        return;
      }
      const rnd = new Uint8Array(16);
      window.crypto.getRandomValues(rnd);
      if (job && job.doc) job.doc.destroy();
      job = { batch: Array.from(rnd, (b) => b.toString(16).padStart(2, '0')).join(''), file: f, doc, failed: new Set(), ids: {} };
      const all = [];
      for (let i = 1; i <= doc.numPages; i++) all.push(i);
      await run(all);
    });
    retryBtn.addEventListener('click', () => { if (job && !running) run(Array.from(job.failed).sort((a, b) => a - b)); });
    window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = L.leave; } });
  });
})();
