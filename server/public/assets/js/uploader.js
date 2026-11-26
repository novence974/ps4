/* ULTIMATE PS LIBRARY — resumable chunked uploader
   Talks to /api/v1/admin/uploads/{init,chunk,complete} (same endpoints the Android manager app uses).
   - parallel chunks (3) with per-chunk SHA-256 (X-Chunk-Sha256) verified by the server
   - whole-file SHA-256 computed incrementally (hash-wasm) and verified after assembly
   - pause / resume / cancel, automatic retry with back-off, waits for the network to come back
   - resume after reload: choosing the same file again continues from the received chunks */
(function () {
  'use strict';
  const BASE = document.body.dataset.base || '';
  const U = () => window.UPSL;
  const CONCURRENCY = 3;
  const iso = (t) => '\u2066' + t + '\u2069'; // keep numbers + units in LTR order inside Arabic sentences
  const fmt = (b) => { if (!isFinite(b) || b < 0) return '—'; const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; };
  const fmtTime = (s) => { if (!isFinite(s) || s <= 0) return '—'; s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return h ? `${h}س ${m}د` : m ? `${m}د ${s % 60}ث` : `${s % 60}ث`; };
  const hex = (buf) => Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
  let hasherLib = null;
  const loadHasher = () => hasherLib || (hasherLib = new Promise((resolve) => {
    if (window.hashwasm) return resolve(window.hashwasm);
    const s = document.createElement('script');
    s.src = BASE + '/assets/vendor/hash-wasm-sha256.min.js';
    s.onload = () => resolve(window.hashwasm || null); s.onerror = () => resolve(null);
    document.head.appendChild(s);
  }));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const waitOnline = () => (navigator.onLine ? Promise.resolve() : new Promise((r) => window.addEventListener('online', r, { once: true })));

  async function apiCall(path, opts = {}) {
    const headers = Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': U().csrf() }, opts.headers || {});
    const res = await fetch(BASE + '/api/v1/admin/uploads/' + path, { method: opts.method || 'GET', headers, body: opts.body, credentials: 'same-origin', signal: opts.signal });
    let data = null; try { data = await res.json(); } catch (e) {}
    if (!res.ok || !data || data.ok === false) {
      const err = new Error((data && data.error && (data.error.fields ? Object.values(data.error.fields)[0] : data.error.message)) || ('HTTP ' + res.status));
      err.status = res.status; err.code = data && data.error && data.error.code; throw err;
    }
    return { status: res.status, data: data.data };
  }

  class Upload {
    constructor(file, root) {
      this.file = file; this.root = root; this.purpose = root.dataset.purpose || 'game_file';
      this.paused = false; this.cancelled = false; this.inflight = new Map(); this.samples = []; this.sent = 0;
      this.el = document.createElement('div');
      this.el.className = 'up-item';
      this.el.innerHTML = `<div class="top">${U().iconSvg('file')}<div class="nm"></div><div class="ctl">
          <button class="btn btn-xs" type="button" data-p>إيقاف مؤقت</button><button class="btn btn-xs btn-ghost" type="button" data-c>إلغاء</button></div></div>
        <div class="bar"><i></i></div><div class="st"><span data-s>بانتظار البدء…</span><span data-n dir="ltr"></span></div>`;
      this.el.querySelector('.nm').textContent = `${file.name} — ${fmt(file.size)}`;
      this.barEl = this.el.querySelector('.bar'); this.stEl = this.el.querySelector('[data-s]'); this.nEl = this.el.querySelector('[data-n]');
      this.pBtn = this.el.querySelector('[data-p]'); this.cBtn = this.el.querySelector('[data-c]');
      this.pBtn.addEventListener('click', () => (this.paused ? this.resume() : this.pause()));
      this.cBtn.addEventListener('click', () => this.cancel());
      root.querySelector('[data-queue]').prepend(this.el);
    }
    meta() {
      const m = { purpose: this.purpose, filename: this.file.name, size: this.file.size, mime: this.file.type || '' };
      if (this.root.dataset.gameId) m.game_id = this.root.dataset.gameId;
      if (this.root.dataset.platformId) m.platform_id = this.root.dataset.platformId;
      this.root.querySelectorAll('[data-meta]').forEach((el) => { if (el.value !== '') m[el.dataset.meta] = el.value; });
      return m;
    }
    state(text, cls) { this.stEl.textContent = text; this.barEl.className = 'bar' + (cls ? ' ' + cls : ''); }
    render() {
      const done = this.receivedBytes();
      const pct = this.file.size ? (done / this.file.size) * 100 : 100;
      this.barEl.querySelector('i').style.width = pct.toFixed(1) + '%';
      const remain = this.file.size - done;
      this.nEl.textContent = `${pct.toFixed(1)}% — ${fmt(done)} / ${fmt(this.file.size)}`;
      if (!this.paused && this.phase === 'upload') this.stEl.textContent = `${iso(fmt(this.speed || 0) + '/s')} — المتبقي ${iso(fmt(remain))} — ${fmtTime(this.speed ? remain / this.speed : NaN)}`;
    }
    receivedBytes() {
      let b = 0;
      for (const i of this.received) b += this.chunkLen(i);
      for (const v of this.inflight.values()) b += v.loaded || 0;
      return Math.min(b, this.file.size);
    }
    chunkLen(i) { return Math.min(this.chunkSize, this.file.size - i * this.chunkSize); }
    tick(bytes) {
      const now = performance.now();
      this.samples.push([now, bytes]);
      while (this.samples.length && now - this.samples[0][0] > 5000) this.samples.shift();
      const span = this.samples.length > 1 ? (now - this.samples[0][0]) / 1000 : 1;
      this.speed = this.samples.reduce((s, x) => s + x[1], 0) / Math.max(span, 0.5);
      if (!this.lastR || now - this.lastR > 250) { this.lastR = now; this.render(); }
    }
    async start() {
      try {
        this.phase = 'init'; this.state('جارٍ تهيئة عملية الرفع…');
        const r = await apiCall('init', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(this.meta()) });
        const d = r.data;
        this.id = d.upload_id; this.chunkSize = d.chunk_size; this.total = d.total_chunks;
        this.received = new Set(d.received || []);
        if (d.status !== 'uploading') { this.finishUi(d); return; }
        if (d.resumed && this.received.size) U().toast('info', `استكمال رفع ${this.file.name} من ${this.received.size}/${this.total} جزء`, 4000);
        const lib = await loadHasher();
        this.hasher = lib ? await lib.createSHA256() : null;
        this.hasher?.init();
        this.nextHash = 0; this.hashChain = Promise.resolve();
        this.render();
        await this.pump();
      } catch (err) { this.fail(err.message); }
    }
    async readChunk(i) {
      const blob = this.file.slice(i * this.chunkSize, i * this.chunkSize + this.chunkLen(i));
      return new Uint8Array(await blob.arrayBuffer());
    }
    // Feed the whole-file hasher strictly in index order. Jobs run one at a time on a promise chain;
    // gaps (chunks already on the server, or not yet read) are read locally so nothing is hashed twice.
    async hashTo(i, current) {
      if (!this.hasher) return;
      while (this.nextHash < i) { this.hasher.update(await this.readChunk(this.nextHash)); this.nextHash++; }
      if (this.nextHash === i && current) { this.hasher.update(current); this.nextHash++; }
    }
    enqueueHash(i, current) {
      this.hashChain = (this.hashChain || Promise.resolve()).then(() => this.hashTo(i, current)).catch(() => { this.hasher = null; });
      return this.hashChain;
    }
    async pump() {
      this.phase = 'upload';
      this.pBtn.disabled = false;
      const queue = [];
      for (let i = 0; i < this.total; i++) if (!this.received.has(i)) queue.push(i);
      const workers = [];
      let cursor = 0;
      const nextIndex = () => (cursor < queue.length ? queue[cursor++] : null);
      const worker = async () => {
        let i;
        while (!this.paused && !this.cancelled && (i = nextIndex()) !== null) {
          await this.sendChunk(i);
        }
      };
      for (let w = 0; w < Math.min(CONCURRENCY, queue.length || 1); w++) workers.push(worker());
      await Promise.all(workers);
      if (this.paused || this.cancelled) return;
      if (this.received.size < this.total) return this.pump();
      await this.complete();
    }
    async sendChunk(i) {
      const data = await this.readChunk(i);
      this.enqueueHash(i, data);
      const digest = hex(await crypto.subtle.digest('SHA-256', data));
      for (let attempt = 1; !this.paused && !this.cancelled; attempt++) {
        const ctrl = new AbortController();
        const slot = { loaded: 0, ctrl };
        this.inflight.set(i, slot);
        try {
          await this.xhr(i, data, digest, slot);
          this.inflight.delete(i);
          this.received.add(i);
          this.render();
          return;
        } catch (err) {
          this.inflight.delete(i);
          if (this.paused || this.cancelled) return;
          if (err.status && err.status < 500 && err.status !== 408 && err.status !== 429 && err.code !== 'chunk_checksum_mismatch' && err.code !== 'chunk_size_mismatch') {
            this.fail(err.message); this.cancelled = true; return;
          }
          if (!navigator.onLine) { this.state('انقطع الاتصال — سيُستكمل الرفع تلقائياً عند عودته', 'paused'); await waitOnline(); }
          else { this.state(`مشكلة في الاتصال، إعادة المحاولة (${attempt})…`, 'paused'); await sleep(Math.min(30000, 1000 * 2 ** Math.min(attempt, 5))); }
          this.state('جارٍ الاستكمال…');
        }
      }
    }
    xhr(i, data, digest, slot) {
      return new Promise((resolve, reject) => {
        const x = new XMLHttpRequest();
        slot.xhr = x;
        x.open('POST', BASE + '/api/v1/admin/uploads/chunk');
        x.setRequestHeader('Content-Type', 'application/octet-stream');
        x.setRequestHeader('X-Upload-Id', this.id);
        x.setRequestHeader('X-Chunk-Index', String(i));
        x.setRequestHeader('X-Chunk-Sha256', digest);
        x.setRequestHeader('X-CSRF-Token', U().csrf());
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.setRequestHeader('Accept', 'application/json');
        x.withCredentials = true;
        let last = 0;
        slot.seen = performance.now();
        x.upload.onprogress = (e) => { slot.loaded = e.loaded; slot.seen = performance.now(); this.tick(e.loaded - last); last = e.loaded; };
        x.onload = () => {
          let body = null; try { body = JSON.parse(x.responseText); } catch (e) {}
          if (x.status >= 200 && x.status < 300 && body && body.ok) resolve(body.data);
          else { const err = new Error((body && body.error && body.error.message) || ('HTTP ' + x.status)); err.status = x.status; err.code = body && body.error && body.error.code; reject(err); }
        };
        x.onerror = () => reject(new Error('network'));
        x.ontimeout = () => reject(new Error('timeout'));
        x.onabort = () => reject(new Error('aborted'));
        x.timeout = 120000;
        x.send(data);
      });
    }
    async complete() {
      this.phase = 'complete';
      this.pBtn.disabled = true;
      this.state('اكتمل الإرسال — جارٍ تجميع الملف والتحقق من SHA-256…');
      await this.enqueueHash(this.total, null);
      const sha = this.hasher ? this.hasher.digest('hex') : '';
      try {
        let r = await apiCall('complete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ upload_id: this.id, sha256: sha }) });
        while (r.data.status === 'processing') {
          this.state('قيد المعالجة في الخلفية (ملف كبير)… تأكد أن bin/worker.php أو cron يعمل');
          await sleep(3000);
          r = await apiCall(this.id);
        }
        this.finishUi(r.data, sha);
      } catch (err) { this.fail(err.message); }
    }
    finishUi(d, sha) {
      if (d.status === 'completed') {
        this.el.classList.add('ok');
        this.barEl.querySelector('i').style.width = '100%';
        this.state('اكتمل الرفع والتحقق ✓', 'done');
        this.nEl.innerHTML = `<span class="sha">SHA-256: ${U().esc(d.sha256 || sha || '')}</span>`;
        this.pBtn.remove(); this.cBtn.textContent = 'إخفاء'; this.cBtn.onclick = () => this.el.remove(); this.done = true;
        U().toast('success', 'تم رفع ' + this.file.name, 4000);
        U().sfx('confirm');
        this.root.dispatchEvent(new CustomEvent('upload:done', { detail: d }));
      } else this.fail(d.error || ('الحالة: ' + d.status));
    }
    // Network went away (browser "offline" event) or a request stopped moving: drop the in-flight chunks now,
    // sendChunk() then waits for the connection and resends only those chunks.
    interrupt(stalledMs = 0) {
      if (this.phase !== 'upload' || this.paused || this.done || this.cancelled) return;
      const now = performance.now();
      for (const v of this.inflight.values()) if (!stalledMs || now - (v.seen || now) > stalledMs) v.xhr?.abort();
    }
    pause() {
      this.paused = true;
      for (const v of this.inflight.values()) v.xhr?.abort();
      this.inflight.clear(); this.speed = 0;
      this.state('متوقف مؤقتاً — اضغط استكمال للمتابعة من آخر جزء', 'paused');
      this.pBtn.textContent = 'استكمال'; this.render();
    }
    async resume() {
      if (this.failed) { this.failed = false; this.cancelled = false; this.paused = false; this.pBtn.textContent = 'إيقاف مؤقت'; this.el.classList.remove('bad'); return this.start(); }
      this.paused = false; this.pBtn.textContent = 'إيقاف مؤقت'; this.state('جارٍ الاستكمال…');
      try { const r = await apiCall(this.id); this.received = new Set(r.data.received || []); } catch (e) {}
      this.pump();
    }
    async cancel() {
      if (this.done) { this.el.remove(); return; }
      if (!(await U().confirm('إلغاء رفع ' + this.file.name + '؟'))) return;
      this.cancelled = true; this.paused = true;
      for (const v of this.inflight.values()) v.xhr?.abort();
      if (this.id) { try { await apiCall(this.id, { method: 'DELETE' }); } catch (e) {} }
      this.el.remove();
    }
    fail(msg) {
      this.failed = true; this.paused = true; this.el.classList.add('bad');
      this.state('فشل: ' + msg, 'err');
      this.pBtn.disabled = false; this.pBtn.textContent = 'إعادة المحاولة';
      U().toast('error', this.file.name + ': ' + msg, 7000);
    }
  }

  document.querySelectorAll('[data-uploader]').forEach((root) => {
    const input = root.querySelector('[data-input]');
    const drop = root.querySelector('.drop');
    const uploads = [];
    const accept = (root.dataset.accept || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
    function add(files) {
      for (const f of files) {
        const ext = '.' + f.name.split('.').pop().toLowerCase();
        if (accept.length && !accept.includes(ext)) { U().toast('error', `الامتداد ${ext} غير مسموح هنا`); continue; }
        const gameSel = root.querySelector('[data-meta="game_id"]');
        if (gameSel && !gameSel.value) { U().toast('error', 'اختر اللعبة أولاً'); gameSel.focus(); return; }
        const up = new Upload(f, root);
        uploads.push(up);
        up.start();
        if (root.dataset.multiple !== '1') break;
      }
    }
    input?.addEventListener('change', () => { add(Array.from(input.files)); input.value = ''; });
    if (drop) {
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
      drop.addEventListener('drop', (e) => add(Array.from(e.dataTransfer.files)));
    }
    if (root.dataset.reload === '1') {
      root.addEventListener('upload:done', () => {
        if (uploads.every((u) => u.done || u.failed || u.cancelled)) setTimeout(() => location.reload(), 1200);
      });
    }
    window.addEventListener('beforeunload', (e) => { if (uploads.some((u) => !u.done && !u.failed && !u.cancelled)) { e.preventDefault(); e.returnValue = ''; } });
    window.addEventListener('offline', () => uploads.forEach((u) => u.interrupt()));
    setInterval(() => uploads.forEach((u) => u.interrupt(45000)), 5000); // no progress for 45 s → retry the chunk
  });
})();
