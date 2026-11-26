/* ULTIMATE PS LIBRARY — background music + interface sound effects.
   Rules: nothing plays before the visitor interacts with the page; choices are remembered. */
(function () {
  'use strict';
  const body = document.body;
  const btn = document.querySelector('[data-music-btn]');
  const panel = document.querySelector('[data-music-panel]');
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
  };
  const siteSfx = body.dataset.sfx !== '0';
  let interacted = false;

  /* ------------------------------------------------------------ SFX (Web Audio, synthesised: no files) */
  let ctx = null;
  function audioCtx() {
    if (!ctx) { const C = window.AudioContext || window.webkitAudioContext; if (!C) return null; ctx = new C(); }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function tone(freq, to, dur, type, gain, delay = 0) {
    const c = audioCtx(); if (!c) return;
    const t0 = c.currentTime + delay;
    const o = c.createOscillator(); const g = c.createGain(); const f = c.createBiquadFilter();
    o.type = type; o.frequency.setValueAtTime(freq, t0);
    if (to) o.frequency.exponentialRampToValueAtTime(to, t0 + dur);
    f.type = 'lowpass'; f.frequency.value = 3200;
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.exponentialRampToValueAtTime(gain, t0 + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    o.connect(f); f.connect(g); g.connect(c.destination);
    o.start(t0); o.stop(t0 + dur + 0.02);
  }
  const sounds = {
    click: () => tone(740, 520, 0.07, 'sine', 0.05),
    move: () => tone(1250, 1100, 0.035, 'triangle', 0.018),
    confirm: () => { tone(660, 0, 0.09, 'sine', 0.045); tone(990, 0, 0.14, 'sine', 0.04, 0.07); },
    error: () => tone(220, 160, 0.18, 'triangle', 0.05),
  };
  const sfxOn = () => siteSfx && store.get('upsl-sfx', 'on') === 'on';
  let lastSfx = 0;
  function play(type) {
    if (!sfxOn() || !interacted) return;
    const now = performance.now();
    if (now - lastSfx < 40) return;
    lastSfx = now;
    try { (sounds[type] || sounds.click)(); } catch (e) {}
  }
  document.addEventListener('upsl:sfx', (e) => play(e.detail));
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.btn, .icon-btn, .chip, .shelf-tile, .slab, .cat, .nav a, .tabbar a, .acc-tabs a, .subnav a, .gcard .stretch, .dock-btn, .pager a');
    if (t && !t.closest('[data-fav-form]')) play('click');
  }, true);

  /* ------------------------------------------------------------ music */
  if (!btn) { ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, () => { interacted = true; }, { once: true, capture: true })); return; }
  let audio = null;
  const slider = document.querySelector('[data-volume]');
  const toggle = document.querySelector('[data-music-toggle]');
  const sfxToggle = document.querySelector('[data-sfx-toggle]');
  let volume = Number(store.get('upsl-volume', body.dataset.volume || '35'));
  if (slider) slider.value = String(volume);
  if (sfxToggle) sfxToggle.checked = store.get('upsl-sfx', 'on') === 'on';
  const wantsMusic = () => store.get('upsl-music', 'off') === 'on';

  function ensureAudio() {
    if (audio) return audio;
    audio = new Audio(btn.dataset.src);
    audio.loop = true; audio.preload = 'auto'; audio.volume = 0;
    const pos = Number(sessionStorage.getItem('upsl-music-pos') || 0);
    audio.addEventListener('loadedmetadata', () => { if (pos && pos < audio.duration) audio.currentTime = pos; });
    setInterval(() => { if (audio && !audio.paused) sessionStorage.setItem('upsl-music-pos', String(audio.currentTime)); }, 1000);
    audio.addEventListener('error', () => { setPlaying(false); window.UPSL?.toast('error', 'تعذر تحميل ملف الموسيقى'); });
    return audio;
  }
  function fadeTo(target, ms, done) {
    const a = ensureAudio(); const start = a.volume; const t0 = performance.now();
    function step(now) {
      const k = Math.min(1, (now - t0) / ms);
      a.volume = Math.max(0, Math.min(1, start + (target - start) * k));
      if (k < 1) requestAnimationFrame(step); else if (done) done();
    }
    requestAnimationFrame(step);
  }
  function setPlaying(on) {
    btn.classList.toggle('playing', on);
    btn.setAttribute('aria-label', on ? 'الموسيقى تعمل — اضغط للتحكم' : 'موسيقى الخلفية');
    if (toggle) {
      toggle.innerHTML = window.UPSL ? window.UPSL.iconSvg(on ? 'pause' : 'play') : (on ? '❚❚' : '▶');
      toggle.setAttribute('aria-label', on ? 'إيقاف الموسيقى' : 'تشغيل الموسيقى');
    }
  }
  function start() {
    const a = ensureAudio();
    const p = a.play();
    if (p && p.then) {
      p.then(() => { setPlaying(true); fadeTo(volume / 100, 1200); }).catch(() => setPlaying(false));
    } else { setPlaying(true); fadeTo(volume / 100, 1200); }
  }
  function stop() {
    if (!audio) { setPlaying(false); return; }
    fadeTo(0, 400, () => audio.pause());
    setPlaying(false);
  }
  function setWanted(on) {
    store.set('upsl-music', on ? 'on' : 'off');
    if (body.dataset.auth === '1' && window.UPSL) window.UPSL.api(window.UPSL.base + '/account/preferences', { method: 'POST', body: { music: on } }).catch(() => {});
  }

  btn.addEventListener('click', () => {
    const open = panel.hidden;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
  });
  toggle?.addEventListener('click', () => {
    const playing = audio && !audio.paused;
    if (playing) { stop(); setWanted(false); } else { start(); setWanted(true); }
  });
  slider?.addEventListener('input', () => {
    volume = Number(slider.value);
    store.set('upsl-volume', String(volume));
    if (audio && !audio.paused) audio.volume = volume / 100;
  });
  slider?.addEventListener('change', () => {
    if (body.dataset.auth === '1' && window.UPSL) window.UPSL.api(window.UPSL.base + '/account/preferences', { method: 'POST', body: { volume } }).catch(() => {});
  });
  sfxToggle?.addEventListener('change', () => {
    store.set('upsl-sfx', sfxToggle.checked ? 'on' : 'off');
    if (sfxToggle.checked) play('confirm');
  });
  document.addEventListener('click', (e) => {
    if (!panel.hidden && !e.target.closest('[data-dock]')) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  });
  document.addEventListener('upsl:prefs', () => { if (sfxToggle) sfxToggle.checked = store.get('upsl-sfx', 'on') === 'on'; if (!wantsMusic()) stop(); });
  // Duck the music while a trailer plays.
  document.addEventListener('upsl:media-play', () => { if (audio && !audio.paused) stop(); });

  // Never autoplay: wait for the first real interaction, then resume only if the visitor turned music on before.
  const firstGesture = () => {
    if (interacted) return;
    interacted = true;
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.removeEventListener(ev, firstGesture, true));
    if (wantsMusic()) start();
  };
  ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, firstGesture, true));
  setPlaying(false);
})();
