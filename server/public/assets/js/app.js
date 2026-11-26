/* ULTIMATE PS LIBRARY — core interactions (no dependencies) */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const body = document.body;
  const BASE = body.dataset.base || '';
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const sfx = (type) => document.dispatchEvent(new CustomEvent('upsl:sfx', { detail: type }));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const iconSvg = (name) => {
    const sprite = (document.querySelector('use[href*="icons.svg"]') || {}).getAttribute?.('href')?.split('#')[0] || (BASE + '/assets/img/icons.svg');
    return `<svg class="icon" aria-hidden="true"><use href="${sprite}#${name}"></use></svg>`;
  };

  async function api(url, opts = {}) {
    const headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'X-CSRF-Token': csrf() }, opts.headers || {});
    let bodyData = opts.body;
    if (bodyData && !(bodyData instanceof FormData) && typeof bodyData === 'object') {
      headers['Content-Type'] = 'application/json';
      bodyData = JSON.stringify(bodyData);
    }
    const res = await fetch(url, { method: opts.method || 'GET', headers, body: bodyData, credentials: 'same-origin', signal: opts.signal });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok || (data && data.ok === false)) {
      const msg = (data && data.error && (data.error.fields ? Object.values(data.error.fields)[0] : data.error.message)) || ('خطأ ' + res.status);
      const err = new Error(msg);
      err.status = res.status; err.data = data;
      throw err;
    }
    return data;
  }

  /* ------------------------------------------------------------ toasts */
  const toasts = $('#toasts');
  function toast(type, message, ms = 5000) {
    if (!toasts) return;
    const el = document.createElement('div');
    el.className = 'toast glass-strong ' + type;
    el.innerHTML = iconSvg(type === 'error' ? 'err' : type === 'success' ? 'ok' : 'info') + `<span>${esc(message)}</span><button type="button" aria-label="إغلاق">${iconSvg('x')}</button>`;
    toasts.appendChild(el);
    const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 230); };
    el.querySelector('button').addEventListener('click', close);
    setTimeout(close, ms);
    if (type === 'error') sfx('error');
  }
  $$('.toast[data-auto]').forEach((el) => {
    const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 230); };
    el.querySelector('[data-close]')?.addEventListener('click', close);
    setTimeout(close, 6000);
  });

  /* ------------------------------------------------------------ confirm dialog */
  function confirmDialog(message, okLabel = 'تأكيد') {
    return new Promise((resolve) => {
      const wrap = document.createElement('div');
      wrap.className = 'lightbox';
      wrap.setAttribute('role', 'dialog');
      wrap.setAttribute('aria-modal', 'true');
      wrap.innerHTML = `<div class="glass panel" style="max-width:440px;width:calc(100% - 32px)"><p style="font-size:1.05rem">${esc(message)}</p>
        <div class="form-actions"><button class="btn btn-danger" type="button" data-ok>${esc(okLabel)}</button><button class="btn" type="button" data-cancel>إلغاء</button></div></div>`;
      document.body.appendChild(wrap);
      const done = (v) => { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
      const onKey = (e) => { if (e.key === 'Escape') done(false); };
      document.addEventListener('keydown', onKey);
      wrap.addEventListener('click', (e) => { if (e.target === wrap) done(false); });
      wrap.querySelector('[data-ok]').addEventListener('click', () => done(true));
      wrap.querySelector('[data-cancel]').addEventListener('click', () => done(false));
      wrap.querySelector('[data-cancel]').focus();
    });
  }
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed) return;
    e.preventDefault();
    if (await confirmDialog(form.dataset.confirm)) { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit(e.submitter) : form.submit(); }
  }, true);

  /* ------------------------------------------------------------ theme */
  function applyTheme(pref) {
    let t = pref;
    if (pref === 'auto') t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = t === 'light' ? '#eef0fb' : '#070a1c';
  }
  function savePrefs(prefs) {
    if (body.dataset.auth !== '1') return Promise.resolve();
    return api(BASE + '/account/preferences', { method: 'POST', body: prefs }).catch(() => {});
  }
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    try { localStorage.setItem('upsl-theme', next); } catch (e) {}
    applyTheme(next);
    savePrefs({ theme: next });
  }));

  /* ------------------------------------------------------------ dropdown menus */
  function closeMenus(except) {
    $$('[data-menu]').forEach((m) => {
      if (m === except) return;
      const dd = m.querySelector('.dropdown');
      if (dd && !dd.hidden) { dd.hidden = true; m.querySelector('[data-menu-btn]')?.setAttribute('aria-expanded', 'false'); }
    });
  }
  $$('[data-menu]').forEach((m) => {
    const btn = m.querySelector('[data-menu-btn]');
    const dd = m.querySelector('.dropdown');
    btn?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = dd.hidden;
      closeMenus(m);
      dd.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (open && btn.hasAttribute('data-notif-btn')) loadNotifications(m);
      if (open) dd.querySelector('a,button')?.focus({ preventScroll: true });
    });
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('[data-menu]')) closeMenus(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenus(); });

  async function loadNotifications(menu) {
    const target = menu.querySelector('[data-notif-body]');
    try {
      const r = await api(BASE + '/account/notifications?partial=1');
      target.innerHTML = r.data.html;
      setUnread(r.data.unread);
    } catch (err) { target.innerHTML = `<p class="muted" style="padding:10px">${esc(err.message)}</p>`; }
  }
  function setUnread(n) {
    $$('[data-notif-btn]').forEach((b) => {
      let dot = b.querySelector('[data-unread]');
      if (n > 0) {
        if (!dot) { dot = document.createElement('span'); dot.className = 'dot'; dot.setAttribute('data-unread', ''); b.appendChild(dot); }
        dot.textContent = n > 99 ? '99+' : n;
      } else if (dot) dot.remove();
    });
  }

  /* ------------------------------------------------------------ ajax forms */
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
    if (form.dataset.confirm && !form.dataset.confirmed) return;
    e.preventDefault();
    const btn = form.querySelector('[type="submit"]');
    btn?.classList.add('is-busy');
    try {
      const r = await api(form.action, { method: 'POST', body: new FormData(form) });
      toast('success', (r.data && r.data.message) || 'تم');
      if (form.dataset.ajax === 'read-all') { setUnread(0); $$('.notif.unread').forEach((n) => n.classList.remove('unread')); }
      sfx('confirm');
    } catch (err) { toast('error', err.message); } finally { btn?.classList.remove('is-busy'); delete form.dataset.confirmed; }
  });

  /* ------------------------------------------------------------ favourites */
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-fav-form')) return;
    e.preventDefault();
    const btn = form.querySelector('button');
    try {
      const r = await api(form.action, { method: 'POST', body: new FormData(form) });
      const on = !!r.data.favorite;
      $$(`form[data-fav-form][action="${form.getAttribute('action')}"] button`).forEach((b) => {
        b.classList.toggle('on', on);
        b.setAttribute('aria-pressed', String(on));
        const label = b.querySelector('[data-fav-label]');
        if (label) label.textContent = on ? 'في المفضلة' : 'أضف للمفضلة';
        const count = b.querySelector('[data-fav-count]');
        if (count) count.textContent = r.data.count;
        if (b.classList.contains('fav')) b.setAttribute('aria-label', on ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة');
      });
      toast('success', on ? 'أضيفت إلى المفضلة' : 'أزيلت من المفضلة', 2500);
      sfx(on ? 'confirm' : 'click');
    } catch (err) { toast('error', err.message); }
    btn?.blur();
  });

  /* ------------------------------------------------------------ search suggestions */
  $$('form[data-suggest]').forEach((form) => {
    const input = form.querySelector('input[name="q"]');
    const box = form.querySelector('.suggest');
    let timer = null; let ctrl = null; let idx = -1;
    const close = () => { box.hidden = true; idx = -1; };
    const items = () => $$('a', box);
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(async () => {
        ctrl?.abort(); ctrl = new AbortController();
        try {
          const r = await api(BASE + '/api/v1/search?suggest=1&q=' + encodeURIComponent(q), { signal: ctrl.signal });
          const list = r.data || [];
          box.innerHTML = list.length
            ? list.map((g) => `<a href="${esc(g.url)}" role="option"><img src="${esc(g.cover)}" alt="" loading="lazy"><span><b>${esc(g.title)}</b><small>${esc(g.platform)}${g.year ? ' — ' + g.year : ''}</small></span></a>`).join('')
              + `<a class="all" href="${BASE}/games?q=${encodeURIComponent(q)}">عرض كل النتائج (${r.meta.total})</a>`
            : `<div class="none">لا توجد نتائج لـ «${esc(q)}»</div>`;
          box.hidden = false; idx = -1;
        } catch (err) { if (err.name !== 'AbortError') close(); }
      }, 180);
    });
    input.addEventListener('keydown', (e) => {
      const list = items();
      if (box.hidden || !list.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); e.stopPropagation();
        idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length;
        list.forEach((a, i) => a.classList.toggle('on', i === idx));
      } else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); location.href = list[idx].href; }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', (e) => { if (!form.contains(e.target)) close(); });
  });

  /* ------------------------------------------------------------ browse: sort, filters sheet, load more */
  $$('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));
  const filters = $('#filters');
  let scrim = null;
  function toggleFilters(open) {
    if (!filters) return;
    filters.classList.toggle('open', open);
    if (open) {
      scrim = document.createElement('div'); scrim.className = 'scrim';
      scrim.addEventListener('click', () => toggleFilters(false));
      document.body.appendChild(scrim);
      filters.querySelector('input,select')?.focus();
    } else { scrim?.remove(); scrim = null; }
  }
  $$('[data-filters-open]').forEach((b) => b.addEventListener('click', () => toggleFilters(true)));
  $$('[data-filters-close]').forEach((b) => b.addEventListener('click', () => toggleFilters(false)));

  $$('[data-load-more]').forEach((btn) => {
    let auto = false;
    const grid = $('[data-grid]');
    async function load() {
      if (btn.classList.contains('is-busy')) return;
      btn.classList.add('is-busy');
      const url = new URL(location.href);
      url.searchParams.set('page', btn.dataset.next);
      url.searchParams.set('partial', '1');
      try {
        const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' }).then((x) => x.json());
        grid.insertAdjacentHTML('beforeend', r.html);
        const next = Number(btn.dataset.next) + 1;
        if (next > Number(btn.dataset.pages)) { btn.parentElement.remove(); $('.pager')?.remove(); }
        else btn.dataset.next = String(next);
        auto = true;
      } catch (e) { toast('error', 'تعذر تحميل المزيد، تحقق من الاتصال'); }
      btn.classList.remove('is-busy');
    }
    btn.addEventListener('click', load);
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((entries) => { if (auto && entries[0].isIntersecting) load(); }, { rootMargin: '400px' }).observe(btn);
    }
  });

  /* ------------------------------------------------------------ rails */
  $$('[data-rail]').forEach((b) => b.addEventListener('click', () => {
    const track = b.closest('.section')?.querySelector('[data-rail-track]');
    if (!track) return;
    const dir = document.dir === 'rtl' ? -1 : 1;
    const amount = track.clientWidth * 0.8 * (b.dataset.rail === 'next' ? dir : -dir);
    track.scrollBy({ left: amount, behavior: reduceMotion ? 'auto' : 'smooth' });
  }));

  /* ------------------------------------------------------------ living backdrop (home stage) */
  const stage = $('[data-stage]');
  if (stage) {
    const tiles = $$('[data-shelf]', stage);
    const bgs = $$('[data-bg]', stage);
    const copy = $('[data-stage-copy]', stage);
    let current = 0; let timer = null; let touched = false;
    const field = (n) => $$(`[data-f="${n}"]`, stage);
    function activate(i, fromUser) {
      if (i === current || !tiles[i]) return;
      current = i;
      const g = JSON.parse(tiles[i].dataset.game);
      tiles.forEach((t, k) => { t.classList.toggle('on', k === i); t.setAttribute('aria-selected', String(k === i)); });
      bgs.forEach((im, k) => im.classList.toggle('on', k === i));
      copy.classList.add('out');
      setTimeout(() => {
        field('title').forEach((el) => { el.textContent = g.title; });
        field('meta').forEach((el) => { el.textContent = g.meta; });
        field('platform').forEach((el) => { el.textContent = g.platform; el.style.setProperty('--pc', g.color); });
        field('year').forEach((el) => { el.querySelector('span').textContent = g.year; });
        field('genre').forEach((el) => { el.querySelector('span').textContent = g.genre; });
        field('rating').forEach((el) => { el.innerHTML = g.rating; });
        field('link').forEach((el) => { el.href = g.url; });
        field('cover-link').forEach((el) => { el.href = g.url; });
        field('cover').forEach((el) => { el.src = g.cover; });
        copy.classList.remove('out');
      }, reduceMotion ? 0 : 200);
      if (fromUser) { touched = true; sfx('move'); }
    }
    tiles.forEach((t, i) => {
      t.addEventListener('mouseenter', () => activate(i, true));
      t.addEventListener('focus', () => activate(i, true));
      t.addEventListener('click', () => {
        if (current === i && t.dataset.armed) location.href = JSON.parse(t.dataset.game).url;
        activate(i, true); t.dataset.armed = '1';
      });
      t.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); location.href = JSON.parse(t.dataset.game).url; } });
    });
    if (!reduceMotion && tiles.length > 1) {
      timer = setInterval(() => { if (!touched && !document.hidden) activate((current + 1) % tiles.length, false); }, 8000);
    }
  }

  /* ------------------------------------------------------------ lightbox */
  $$('[data-gallery]').forEach((gal) => {
    const shots = $$('button[data-full]', gal);
    shots.forEach((b, i) => b.addEventListener('click', () => open(i)));
    function open(i) {
      let idx = i;
      const lb = document.createElement('div');
      lb.className = 'lightbox'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true'); lb.setAttribute('aria-label', 'عارض الصور');
      lb.innerHTML = `<img alt=""><button class="icon-btn lb-close" type="button" aria-label="إغلاق">${iconSvg('x')}</button>`
        + (shots.length > 1 ? `<button class="icon-btn lb-prev" type="button" aria-label="السابقة">${iconSvg('chev-right')}</button><button class="icon-btn lb-next" type="button" aria-label="التالية">${iconSvg('chev-left')}</button>` : '');
      const img = lb.querySelector('img');
      const show = () => { img.src = shots[idx].dataset.full; img.alt = shots[idx].querySelector('img').alt; };
      const step = (d) => { idx = (idx + d + shots.length) % shots.length; show(); sfx('move'); };
      const close = () => { lb.remove(); document.removeEventListener('keydown', key); shots[idx].focus(); };
      const key = (e) => {
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') step(document.dir === 'rtl' ? 1 : -1);
        if (e.key === 'ArrowRight') step(document.dir === 'rtl' ? -1 : 1);
      };
      lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
      lb.querySelector('.lb-close').addEventListener('click', close);
      lb.querySelector('.lb-prev')?.addEventListener('click', () => step(-1));
      lb.querySelector('.lb-next')?.addEventListener('click', () => step(1));
      document.addEventListener('keydown', key);
      show(); document.body.appendChild(lb); lb.querySelector('.lb-close').focus();
    }
  });

  /* ------------------------------------------------------------ trailer facade */
  $$('[data-embed]').forEach((b) => b.addEventListener('click', () => {
    const f = document.createElement('iframe');
    f.src = b.dataset.embed;
    f.title = 'الفيديو التعريفي';
    f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    f.allowFullscreen = true;
    f.referrerPolicy = 'strict-origin-when-cross-origin';
    b.replaceWith(f);
    document.dispatchEvent(new CustomEvent('upsl:media-play'));
  }));
  $$('[data-media-video]').forEach((v) => v.addEventListener('play', () => document.dispatchEvent(new CustomEvent('upsl:media-play'))));

  /* ------------------------------------------------------------ copy / password helpers */
  $$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); toast('success', 'تم نسخ الرابط', 2200); }
    catch (e) { prompt('انسخ الرابط:', b.dataset.copy); }
  }));
  $$('[data-pw-toggle]').forEach((b) => b.addEventListener('click', () => {
    const input = b.parentElement.querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
    b.setAttribute('aria-label', input.type === 'password' ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور');
  }));
  $$('[data-pw-meter]').forEach((input) => {
    const bar = input.closest('.field')?.querySelector('.pw-meter i');
    if (!bar) return;
    input.addEventListener('input', () => {
      const v = input.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++;
      if (/\d/.test(v) && /\p{L}/u.test(v)) s++; if (/[^\p{L}\d]/u.test(v)) s++;
      bar.style.width = (s * 25) + '%';
      bar.style.background = ['var(--bad)', 'var(--bad)', 'var(--warn)', 'var(--azure)', 'var(--ok)'][s];
    });
  });

  /* ------------------------------------------------------------ preferences form */
  $$('form[data-prefs]').forEach((form) => {
    form.addEventListener('change', async (e) => {
      const el = e.target;
      const key = el.dataset.pref;
      if (!key) return;
      const value = el.type === 'checkbox' ? el.checked : el.value;
      try {
        if (key === 'theme') { localStorage.setItem('upsl-theme', value); applyTheme(value); }
        if (key === 'music') localStorage.setItem('upsl-music', value ? 'on' : 'off');
        if (key === 'sfx') localStorage.setItem('upsl-sfx', value ? 'on' : 'off');
      } catch (x) {}
      try { await savePrefs({ [key]: value }); toast('success', 'تم حفظ التفضيل', 1800); } catch (x) { toast('error', 'تعذر الحفظ'); }
      document.dispatchEvent(new CustomEvent('upsl:prefs'));
    });
  });

  /* ------------------------------------------------------------ section sub-navigation */
  const subnav = $('[data-subnav]');
  if (subnav && 'IntersectionObserver' in window) {
    const links = $$('a', subnav);
    const obs = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) links.forEach((a) => a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id));
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    links.forEach((a) => { const t = document.querySelector(a.getAttribute('href')); if (t) obs.observe(t); });
  }

  /* ------------------------------------------------------------ TV / keyboard spatial navigation */
  const tvUA = /SmartTV|SMART-TV|Tizen|Web0S|WebOS|NetCast|BRAVIA|AFT[A-Z]|CrKey|PlayStation|Xbox|HbbTV|Android TV|GoogleTV/i;
  if (tvUA.test(navigator.userAgent)) body.classList.add('tv-mode');
  document.addEventListener('mousedown', () => body.classList.remove('kbd-nav'));
  const focusables = () => $$('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])')
    .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !el.closest('[hidden]') && getComputedStyle(el).visibility !== 'hidden'; });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Tab') body.classList.add('kbd-nav');
    const dirs = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };
    if (!dirs[e.key] || e.altKey || e.ctrlKey || e.metaKey || e.defaultPrevented) return;
    const a = document.activeElement;
    const tv = body.classList.contains('tv-mode');
    if (a && (a.matches('video, textarea, [contenteditable="true"]') || a.closest('.suggest'))) return;
    // Fields keep their own arrow keys, except on TVs where Up/Down must be able to leave a field (no mouse there).
    if (a && a.matches('input, select') && !(tv && (e.key === 'ArrowUp' || e.key === 'ArrowDown'))) return;
    if (!tv && !body.classList.contains('kbd-nav')) return;
    if (!a || a === document.body || a.id === 'main') {
      if (!tv) return;
      // first press on a remote: start on the first visible game (or the first control of the page content)
      const vis = focusables().filter((el) => el.closest('main') && el.getBoundingClientRect().bottom > 0 && el.getBoundingClientRect().top < innerHeight);
      const start = vis.find((el) => el.matches('[data-tv-start], .gcard .stretch, .shelf-tile, .slab')) || vis[0];
      if (start) { e.preventDefault(); body.classList.add('kbd-nav'); start.focus({ preventScroll: true }); sfx('move'); }
      return;
    }
    const from = a.getBoundingClientRect();
    const [dx, dy] = dirs[e.key];
    const cx = from.left + from.width / 2; const cy = from.top + from.height / 2;
    let best = null; let bestScore = Infinity;
    for (const el of focusables()) {
      if (el === a) continue;
      const r = el.getBoundingClientRect();
      const ex = r.left + r.width / 2; const ey = r.top + r.height / 2;
      const vx = ex - cx; const vy = ey - cy;
      const along = vx * dx + vy * dy;
      if (along <= 4) continue;
      const across = Math.abs(dx ? vy : vx);
      const score = along + across * 2.2;
      if (score < bestScore) { bestScore = score; best = el; }
    }
    if (best) {
      e.preventDefault();
      body.classList.add('kbd-nav');
      best.focus({ preventScroll: true });
      best.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
      sfx('move');
    }
  });

  window.UPSL = { api, toast, confirm: confirmDialog, sfx, esc, iconSvg, base: BASE, csrf };
})();
