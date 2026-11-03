/* SIPERSEDIAAN BMD — skrip antarmuka (vanilla JS, tanpa library) */
(function () {
  'use strict';
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  /** POST FormData / object ke endpoint, hasil JSON */
  function api(url, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('_csrf', CSRF);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, msg: 'Respons server tidak valid (' + r.status + ')' }; }); });
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.json(); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n, dec) {
    n = Number(n || 0); if (dec === undefined) dec = 2;
    return n.toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function fmtQty(n) { n = Number(n || 0); return n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); }

  /**
   * Combobox: input teks + daftar saran yang dapat dicari.
   * opts: { source(q) -> Promise<items>, render(item) -> html, onSelect(item), onClear(), minChars }
   */
  function Combo(input, opts) {
    var wrap = document.createElement('div'); wrap.className = 'combo';
    input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
    var list = document.createElement('div'); list.className = 'combo-list'; wrap.appendChild(list);
    input.setAttribute('autocomplete', 'off');
    var items = [], hl = -1, timer = null, selected = null, seq = 0;
    var minChars = opts.minChars == null ? 1 : opts.minChars;

    function close() { list.classList.remove('open'); hl = -1; }
    function draw() {
      if (!items.length) { list.innerHTML = '<div class="combo-empty">Tidak ada hasil yang cocok</div>'; list.classList.add('open'); return; }
      list.innerHTML = items.map(function (it, i) { return '<div class="combo-item' + (i === hl ? ' hl' : '') + '" data-i="' + i + '">' + opts.render(it) + '</div>'; }).join('');
      list.classList.add('open');
    }
    function search() {
      var q = input.value.trim();
      if (q.length < minChars) { close(); return; }
      var my = ++seq;
      opts.source(q).then(function (res) { if (my !== seq) return; items = res || []; hl = items.length ? 0 : -1; draw(); });
    }
    function pick(i) {
      var it = items[i]; if (!it) return;
      selected = it; close(); opts.onSelect(it);
    }
    input.addEventListener('input', function () {
      if (selected) { selected = null; if (opts.onClear) opts.onClear(); }
      clearTimeout(timer); timer = setTimeout(search, 180);
    });
    input.addEventListener('focus', function () { if (!selected && input.value.trim().length >= minChars) search(); else if (minChars === 0 && !selected) search(); });
    input.addEventListener('keydown', function (e) {
      if (!list.classList.contains('open')) { if (e.key === 'ArrowDown') search(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); hl = Math.min(items.length - 1, hl + 1); draw(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); hl = Math.max(0, hl - 1); draw(); }
      else if (e.key === 'Enter') { if (hl >= 0) { e.preventDefault(); pick(hl); } }
      else if (e.key === 'Escape') { close(); }
    });
    list.addEventListener('mousedown', function (e) {
      var el = e.target.closest('.combo-item'); if (!el) return;
      e.preventDefault(); pick(+el.getAttribute('data-i'));
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    return { clear: function () { selected = null; input.value = ''; close(); }, get: function () { return selected; } };
  }

  // Konfirmasi untuk form/link berbahaya
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-confirm-link]');
    if (a && !window.confirm(a.getAttribute('data-confirm-link'))) e.preventDefault();
  });

  // Ganti otomatis form filter ketika select berubah (data-autosubmit)
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
  });

  // Jendela rincian (pop-up): tautan bertanda data-rinci dimuat ke dalam modal
  var modalEl = null, modalSeq = 0, lastFocus = null;
  function modalClose() {
    if (!modalEl) return;
    modalEl.parentNode.removeChild(modalEl); modalEl = null; modalSeq++;
    document.body.classList.remove('modal-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function modalOpen(href) {
    if (!modalEl) {
      lastFocus = document.activeElement;
      modalEl = document.createElement('div');
      modalEl.className = 'modal-backdrop';
      modalEl.innerHTML = '<div class="modal" role="dialog" aria-modal="true"></div>';
      modalEl.addEventListener('mousedown', function (e) { if (e.target === modalEl) modalClose(); });
      document.body.appendChild(modalEl);
      document.body.classList.add('modal-open');
    }
    var box = modalEl.firstChild, my = ++modalSeq;
    box.innerHTML = '<div class="modal-head"><h3>Memuat rincian…</h3><div class="actions"><button class="icon-btn modal-x" type="button" data-modal-close aria-label="Tutup">&#10005;</button></div></div>'
      + '<div class="modal-body"><div class="empty">Memuat data…</div></div>';
    var url = href + (href.indexOf('?') < 0 ? '?' : '&') + 'partial=1';
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.text().then(function (t) { return { ct: r.headers.get('Content-Type') || '', t: t }; }); })
      .then(function (res) {
        if (!modalEl || my !== modalSeq) return;
        if (res.ct.indexOf('json') >= 0) { var j = {}; try { j = JSON.parse(res.t); } catch (e) { } throw new Error(j.msg || 'Gagal memuat rincian.'); }
        box.innerHTML = res.t;
        var x = box.querySelector('[data-modal-close]'); if (x) x.focus();
      })
      .catch(function (err) {
        if (!modalEl || my !== modalSeq) return;
        var b = box.querySelector('.modal-body');
        if (b) b.innerHTML = '<div class="alert alert-error">' + esc(err && err.message ? err.message : 'Gagal memuat rincian.') + ' <a href="' + esc(href) + '">Buka halaman rincian</a></div>';
      });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-rinci]');
    if (a) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // biarkan buka di tab baru
      e.preventDefault(); modalOpen(a.getAttribute('href')); return;
    }
    if (e.target.closest('[data-modal-close]')) modalClose();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modalEl) modalClose(); });

  window.BMD = { api: api, getJSON: getJSON, esc: esc, fmt: fmt, fmtQty: fmtQty, Combo: Combo, CSRF: CSRF };
})();
