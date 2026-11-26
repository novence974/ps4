/* ULTIMATE PS LIBRARY — download manager
   Ranged (resumable) downloads with progress, speed, remaining size, pause/resume and SHA-256 verification.
   Writes straight to disk with the File System Access API when available (large files), otherwise to a Blob.
   Falls back to the browser's own download manager for very large files when the API is not available. */
(function () {
  'use strict';
  const panel = document.getElementById('dl-panel');
  if (!panel || !window.fetch || !window.ReadableStream) return;
  const list = panel.querySelector('[data-dl-list]');
  const CHUNK = 8 * 1024 * 1024;
  const BLOB_LIMIT = 1.5 * 1024 ** 3;
  const U = () => window.UPSL;
  const fmt = (b) => {
    if (!isFinite(b) || b < 0) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(1) : b) + ' ' + u[i];
  };
  const iso = (t) => '\u2066' + t + '\u2069'; // keep numbers + units in LTR order inside Arabic sentences
  const fmtTime = (s) => {
    if (!isFinite(s) || s <= 0) return '—';
    s = Math.round(s);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h ? `${h}س ${m}د` : m ? `${m}د ${sec}ث` : `${sec}ث`;
  };
  let hashLib = null;
  function loadHasher() {
    if (hashLib) return hashLib;
    hashLib = new Promise((resolve) => {
      if (window.hashwasm) return resolve(window.hashwasm);
      const s = document.createElement('script');
      s.src = (U()?.base || '') + '/assets/vendor/hash-wasm-sha256.min.js';
      s.onload = () => resolve(window.hashwasm || null);
      s.onerror = () => resolve(null);
      document.head.appendChild(s);
    });
    return hashLib;
  }

  class Task {
    constructor(a) {
      this.requestUrl = a.href;
      this.name = a.dataset.name || 'file';
      this.size = Number(a.dataset.size) || 0;
      this.sha = (a.dataset.sha || '').toLowerCase();
      this.offset = 0; this.paused = false; this.done = false; this.parts = []; this.writable = null; this.handle = null;
      this.samples = []; this.speed = 0; this.retries = 0;
      this.el = document.createElement('div');
      this.el.className = 'dl-item';
      this.el.innerHTML = `<div class="name"></div><div class="bar"><i></i></div><div class="info"><span data-state>جارٍ التحضير…</span><span data-num dir="ltr"></span></div>
        <div class="ctl"><button class="btn btn-xs" type="button" data-pause>إيقاف مؤقت</button><button class="btn btn-xs btn-ghost" type="button" data-cancel>إلغاء</button></div>`;
      this.el.querySelector('.name').textContent = this.name;
      this.bar = this.el.querySelector('.bar');
      this.state = this.el.querySelector('[data-state]');
      this.num = this.el.querySelector('[data-num]');
      this.pauseBtn = this.el.querySelector('[data-pause]');
      this.pauseBtn.addEventListener('click', () => (this.paused ? this.resume() : this.pause()));
      this.el.querySelector('[data-cancel]').addEventListener('click', () => this.cancel());
      list.prepend(this.el);
      panel.hidden = false;
    }
    async prepare(useDisk) {
      if (useDisk) {
        this.handle = await window.showSaveFilePicker({ suggestedName: this.name });
        this.writable = await this.handle.createWritable();
      }
      const lib = this.sha ? await loadHasher() : null;
      this.hasher = lib ? await lib.createSHA256() : null;
      if (this.hasher) this.hasher.init();
      await this.refreshLink();
    }
    async refreshLink() {
      const r = await U().api(this.requestUrl);
      this.url = r.data.url;
      if (!this.size) this.size = r.data.size;
    }
    render() {
      const pct = this.size ? Math.min(100, (this.offset / this.size) * 100) : 0;
      this.bar.querySelector('i').style.width = pct.toFixed(1) + '%';
      const remain = Math.max(0, this.size - this.offset);
      this.num.textContent = `${pct.toFixed(1)}% — ${fmt(this.offset)} / ${fmt(this.size)}`;
      if (!this.paused && !this.done && !this.failed) {
        this.state.textContent = `${iso(fmt(this.speed) + '/s')} — المتبقي ${iso(fmt(remain))} — ${fmtTime(this.speed ? remain / this.speed : NaN)}`;
      }
    }
    tick(bytes) {
      const now = performance.now();
      this.samples.push([now, bytes]);
      while (this.samples.length && now - this.samples[0][0] > 4000) this.samples.shift();
      const total = this.samples.reduce((s, x) => s + x[1], 0);
      const span = this.samples.length > 1 ? (now - this.samples[0][0]) / 1000 : 1;
      this.speed = total / Math.max(span, 0.5);
      if (!this.lastRender || now - this.lastRender > 250) { this.lastRender = now; this.render(); }
    }
    async run() {
      this.paused = false;
      this.bar.classList.remove('paused', 'err');
      while (!this.paused && this.offset < this.size) {
        const end = Math.min(this.offset + CHUNK, this.size) - 1;
        this.ctrl = new AbortController();
        const received = [];
        let got = 0;
        try {
          const res = await fetch(this.url, { headers: { Range: `bytes=${this.offset}-${end}` }, signal: this.ctrl.signal, credentials: 'same-origin' });
          if (res.status === 403) { await this.refreshLink(); continue; }
          if (res.status !== 206 && !(res.status === 200 && this.offset === 0)) throw new Error('HTTP ' + res.status);
          const reader = res.body.getReader();
          this.seen = performance.now();
          for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            this.seen = performance.now();
            received.push(value); got += value.length; this.tick(value.length);
            if (res.status === 200 && this.offset + got > end + 1) { this.ctrl.abort(); break; }
          }
          if (got === 0) throw new Error('empty');
          let data = new Uint8Array(got); let p = 0;
          for (const r of received) { data.set(r, p); p += r.length; }
          if (data.length > end - this.offset + 1) data = data.subarray(0, end - this.offset + 1);
          if (this.writable) await this.writable.write({ type: 'write', position: this.offset, data });
          else this.parts.push(new Blob([data]));
          if (this.hasher) this.hasher.update(data);
          this.offset += data.length;
          this.retries = 0;
          this.render();
        } catch (err) {
          this.speed = 0;
          if (this.paused || this.cancelled) return;
          this.bar.classList.add('paused');
          if (!navigator.onLine) {
            // offline time does not count as a failed attempt
            this.state.textContent = 'انقطع الاتصال — سيستكمل التنزيل تلقائياً عند عودته';
            await new Promise((r) => window.addEventListener('online', r, { once: true }));
          } else {
            this.retries++;
            if (this.retries > 6) return this.fail('تعذر إكمال التنزيل بعد عدة محاولات');
            this.state.textContent = `خطأ في الاتصال، إعادة المحاولة (${this.retries})…`;
            await new Promise((r) => setTimeout(r, 1000 * 2 ** Math.min(this.retries, 5)));
          }
          this.bar.classList.remove('paused');
        }
      }
      if (!this.paused && this.offset >= this.size) await this.finish();
    }
    // Network lost (browser "offline" event) or the transfer stalled: abort the current range request;
    // run() keeps what was already written and retries from the same offset once the connection is back.
    interrupt(stalledMs = 0) {
      if (this.paused || this.done || this.cancelled || !this.ctrl) return;
      if (!stalledMs || performance.now() - (this.seen || performance.now()) > stalledMs) this.ctrl.abort();
    }
    pause() {
      this.paused = true; this.ctrl?.abort(); this.speed = 0;
      this.bar.classList.add('paused');
      this.state.textContent = 'متوقف مؤقتاً — يمكن الاستكمال من نفس النقطة';
      this.pauseBtn.textContent = 'استكمال';
      U()?.sfx('click');
    }
    resume() {
      this.failed = false; this.retries = 0;
      this.pauseBtn.textContent = 'إيقاف مؤقت';
      this.state.textContent = 'جارٍ الاستكمال…';
      this.run();
    }
    async cancel() {
      this.cancelled = true; this.paused = true; this.ctrl?.abort();
      try { await this.writable?.abort(); } catch (e) {}
      this.el.remove();
      if (!list.children.length) panel.hidden = true;
    }
    fail(msg) {
      this.failed = true;
      this.bar.classList.add('err');
      this.state.textContent = msg;
      this.pauseBtn.textContent = 'إعادة المحاولة';
      this.paused = true;
      U()?.toast('error', msg + ': ' + this.name);
    }
    async finish() {
      this.done = true;
      this.pauseBtn.remove();
      this.bar.classList.add('done');
      let verdict = '';
      if (this.hasher && this.sha) {
        const digest = this.hasher.digest('hex');
        verdict = digest === this.sha ? ' — تم التحقق من سلامة الملف (SHA-256 ✓)' : ' — تحذير: بصمة SHA-256 غير مطابقة!';
        if (digest !== this.sha) this.bar.classList.add('err');
      }
      if (this.writable) { await this.writable.close(); }
      else {
        const url = URL.createObjectURL(new Blob(this.parts, { type: 'application/octet-stream' }));
        const a = document.createElement('a'); a.href = url; a.download = this.name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
        this.parts = [];
      }
      this.state.textContent = 'اكتمل التنزيل' + verdict;
      this.num.textContent = fmt(this.size);
      U()?.toast(verdict.includes('تحذير') ? 'error' : 'success', 'اكتمل تنزيل ' + this.name + verdict, 7000);
      U()?.sfx('confirm');
    }
  }

  const tasks = new Set();
  window.addEventListener('offline', () => tasks.forEach((t) => t.interrupt()));
  setInterval(() => tasks.forEach((t) => t.interrupt(45000)), 5000);

  document.addEventListener('click', async (e) => {
    const a = e.target.closest('a[data-download]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    const size = Number(a.dataset.size) || 0;
    const canDisk = typeof window.showSaveFilePicker === 'function' && window.isSecureContext;
    if (!canDisk && size > BLOB_LIMIT) {
      U()?.toast('info', 'الملف كبير، سيتولى متصفحك التنزيل مباشرة مع دعم الاستكمال', 6000);
      return; // native download via the signed URL (Range supported)
    }
    e.preventDefault();
    const task = new Task(a);
    tasks.add(task);
    try {
      await task.prepare(canDisk && size > 256 * 1024 * 1024);
      task.render();
      task.run();
    } catch (err) {
      if (err && err.name === 'AbortError') { task.el.remove(); if (!list.children.length) panel.hidden = true; return; }
      task.fail(err.message || 'تعذر بدء التنزيل');
      if (err.status === 401) location.href = (U()?.base || '') + '/login';
    }
  });
  panel.querySelector('[data-dl-hide]')?.addEventListener('click', () => { panel.hidden = true; });
  window.addEventListener('beforeunload', (e) => {
    if ($active()) { e.preventDefault(); e.returnValue = ''; }
  });
  function $active() { return Array.from(list.children).some((el) => !el.querySelector('.bar.done') && !el.querySelector('.bar.err')); }
})();
