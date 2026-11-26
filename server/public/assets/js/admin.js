/* ULTIMATE PS LIBRARY — admin panel interactions */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const U = () => window.UPSL;
  const BASE = document.body.dataset.base || '';

  // sidebar (mobile)
  const side = $('#side');
  $('[data-side-toggle]')?.addEventListener('click', (e) => { e.stopPropagation(); side.classList.toggle('open'); });
  document.addEventListener('click', (e) => { if (side?.classList.contains('open') && !e.target.closest('#side')) side.classList.remove('open'); });

  // generic toggles
  $$('[data-toggle]').forEach((b) => b.addEventListener('click', () => {
    const t = document.querySelector(b.dataset.toggle);
    if (t) { t.hidden = !t.hidden; if (!t.hidden) t.querySelector('input,select,textarea')?.focus(); }
  }));

  // bulk selection
  const bulk = $('[data-bulk]');
  if (bulk) {
    const bar = $('[data-bulkbar]', bulk);
    const update = () => {
      const n = $$('[data-check]:checked', bulk).length;
      bar.hidden = n === 0;
      $('[data-bulk-count]', bulk).textContent = n;
    };
    $('[data-check-all]', bulk)?.addEventListener('change', (e) => { $$('[data-check]', bulk).forEach((c) => { c.checked = e.target.checked; }); update(); });
    bulk.addEventListener('change', (e) => { if (e.target.matches('[data-check]')) update(); });
  }
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-confirm-click]');
    if (!b || b.dataset.confirmed) return;
    e.preventDefault();
    if (await U().confirm(b.dataset.confirmClick)) { b.dataset.confirmed = '1'; b.form.requestSubmit(b); }
  });

  // tabs
  const tabs = $('[data-tabs]');
  if (tabs) {
    tabs.addEventListener('click', (e) => {
      const a = e.target.closest('[data-tab]');
      if (!a) return;
      e.preventDefault();
      const name = a.dataset.tab;
      $$('[data-tab]', tabs).forEach((x) => x.classList.toggle('on', x === a));
      $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name; });
      const url = new URL(location.href); url.searchParams.set('tab', name); history.replaceState(null, '', url);
    });
  }

  // live duplicate detection on the game form
  const gameForm = $('[data-game-form]');
  if (gameForm) {
    const box = $('[data-dup-box]', gameForm);
    let t = null; let ctrl = null;
    const check = () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const title = gameForm.elements.title.value.trim();
        const platform = gameForm.elements.platform_id.value;
        if (title.length < 2 || !platform) { box.innerHTML = ''; return; }
        ctrl?.abort(); ctrl = new AbortController();
        const qs = new URLSearchParams({ title, platform_id: platform, product_code: gameForm.elements.product_code.value, region: gameForm.elements.region.value, exclude_id: gameForm.dataset.exclude || '0' });
        try {
          const r = await U().api(BASE + '/admin/games/check-duplicates?' + qs, { signal: ctrl.signal });
          const list = r.data || [];
          box.innerHTML = list.length ? `<div class="notice warn">${U().iconSvg('alert')}<span><b>${list.some((d) => d.match === 'exact') ? 'لعبة مطابقة موجودة' : 'ألعاب مشابهة'}:</b> ` +
            list.map((d) => `<a href="${BASE}/admin/games/${d.id}/edit">${U().esc(d.title)}${d.region ? ' (' + U().esc(d.region) + ')' : ''}${d.similarity ? ' ~' + d.similarity + '%' : ''}</a>`).join('، ') + '</span></div>' : '';
        } catch (err) { if (err.name !== 'AbortError') box.innerHTML = ''; }
      }, 350);
    };
    $$('[data-dup-src]', gameForm).forEach((el) => el.addEventListener('input', check));
    $$('select[data-dup-src]', gameForm).forEach((el) => el.addEventListener('change', check));
  }

  // drag & drop screenshot ordering
  $$('[data-sortable]').forEach((grid) => {
    let dragged = null;
    grid.addEventListener('dragstart', (e) => { dragged = e.target.closest('.thumb-item'); dragged?.classList.add('dragging'); });
    grid.addEventListener('dragend', () => { dragged?.classList.remove('dragging'); $$('.over', grid).forEach((x) => x.classList.remove('over')); });
    grid.addEventListener('dragover', (e) => { e.preventDefault(); const over = e.target.closest('.thumb-item'); $$('.over', grid).forEach((x) => x.classList.remove('over')); if (over && over !== dragged) over.classList.add('over'); });
    grid.addEventListener('drop', async (e) => {
      e.preventDefault();
      const over = e.target.closest('.thumb-item');
      if (!dragged || !over || over === dragged) return;
      const items = $$('.thumb-item', grid);
      if (items.indexOf(dragged) < items.indexOf(over)) over.after(dragged); else over.before(dragged);
      try {
        await U().api(grid.dataset.sortable, { method: 'POST', body: { ids: $$('.thumb-item', grid).map((x) => Number(x.dataset.id)) } });
        U().toast('success', 'تم حفظ ترتيب الصور', 2000);
      } catch (err) { U().toast('error', err.message); }
    });
  });

  // filter a long <select> with a search box
  $$('[data-filter-select]').forEach((input) => {
    const sel = $(input.dataset.filterSelect);
    if (!sel) return;
    const all = Array.from(sel.options).map((o) => [o.value, o.text]);
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      const cur = sel.value;
      sel.innerHTML = '';
      all.filter(([v, t]) => !v || !q || t.toLowerCase().includes(q)).forEach(([v, t]) => sel.add(new Option(t, v, false, v === cur)));
      if (q && sel.options.length === 2) sel.selectedIndex = 1;
      sel.dispatchEvent(new Event('change'));
    });
  });

  // notifications audience
  const aud = $('[data-audience]');
  if (aud) { const f = $('[data-user-field]'); const sync = () => { f.hidden = aud.value !== 'user'; }; aud.addEventListener('change', sync); sync(); }

  // volume label
  $$('[data-vol-input]').forEach((r) => r.addEventListener('input', () => { const l = $('[data-vol-label]'); if (l) l.textContent = r.value; }));

  // API console
  $$('[data-api-console]').forEach((form) => form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const path = form.elements.path.value.trim();
    const out = $('[data-api-out]', form); const meta = $('[data-api-meta]', form);
    if (!path.startsWith('/api/')) { out.textContent = 'المسار يجب أن يبدأ بـ /api/'; return; }
    const t0 = performance.now();
    try {
      const res = await fetch(BASE + path, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      const text = await res.text();
      meta.textContent = `HTTP ${res.status} — ${Math.round(performance.now() - t0)} ms — ${text.length} bytes`;
      try { out.textContent = JSON.stringify(JSON.parse(text), null, 2); } catch (x) { out.textContent = text; }
    } catch (err) { out.textContent = err.message; }
  }));

  // cancel an upload session from the upload manager
  $$('[data-cancel-upload]').forEach((b) => b.addEventListener('click', async () => {
    if (!(await U().confirm('إلغاء عملية الرفع وحذف أجزائها؟'))) return;
    try { await U().api(BASE + '/api/v1/admin/uploads/' + b.dataset.cancelUpload, { method: 'DELETE' }); location.reload(); }
    catch (err) { U().toast('error', err.message); }
  }));
})();
