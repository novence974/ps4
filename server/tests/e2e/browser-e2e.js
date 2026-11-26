/* ULTIMATE PS LIBRARY — browser end-to-end tests in a real Chrome (puppeteer-core).
 *
 *   cd tests/e2e && npm install
 *   node browser-e2e.js --base=http://127.0.0.1:8080 --admin=admin --pass='…' [--chrome=/usr/bin/google-chrome] [--shots=../../docs/screenshots]
 *
 * Covers what HTTP tests cannot: the admin chunked uploader (pause, resume, network drop, SHA-256 in the browser),
 * the visitor download manager (Range requests, pause, resume, network drop, SHA-256 check of the saved file),
 * and the background-music rule (nothing plays before the first interaction; the choice is remembered).
 * Creates its own game/user (prefixed zz-e2e) and deletes them at the end.
 */
'use strict';
const puppeteer = require('puppeteer-core');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const arg = (k, d) => (process.argv.find((a) => a.startsWith('--' + k + '=')) || '').split('=').slice(1).join('=') || d;
const BASE = arg('base', 'http://127.0.0.1:8080').replace(/\/$/, '');
const ADMIN = arg('admin', 'admin');
const PASS = arg('pass', '');
const CHROME = arg('chrome', process.env.CHROME_PATH || '/usr/bin/google-chrome');
const SHOTS = arg('shots', '');
const RUN = crypto.randomBytes(3).toString('hex');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sha256File = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');

async function api(method, p, { token, json } = {}) {
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = 'Bearer ' + token;
  if (json) headers['Content-Type'] = 'application/json';
  const r = await fetch(BASE + '/api/v1' + p, { method, headers, body: json ? JSON.stringify(json) : undefined });
  const body = await r.json().catch(() => null);
  if (!r.ok) throw new Error(`${method} ${p} → ${r.status} ${JSON.stringify(body?.error || body)}`);
  return body.data;
}

const results = [];
async function test(name, fn) {
  const t = Date.now();
  try {
    await fn();
    results.push({ name, passed: true, ms: Date.now() - t });
    console.log(`  \x1b[32m✔\x1b[0m ${name} \x1b[2m(${((Date.now() - t) / 1000).toFixed(1)} s)\x1b[0m`);
  } catch (e) {
    results.push({ name, passed: false, error: e.message, ms: Date.now() - t });
    console.log(`  \x1b[31m✘ ${name}\x1b[0m\n    → ${e.message}`);
  }
}
const check = (cond, msg) => { if (!cond) throw new Error(msg); };
async function waitFor(fn, timeout, what) {
  const t = Date.now();
  for (;;) {
    const v = await fn();
    if (v) return v;
    if (Date.now() - t > timeout) throw new Error('timeout waiting for ' + what);
    await sleep(150);
  }
}
async function launch(extraArgs = []) {
  return puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--autoplay-policy=document-user-activation-required', ...extraArgs],
  });
}
async function login(page, user, pass) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
  await page.type('input[name=login]', user);
  await page.type('input[name=password]', pass);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('form button[type=submit]')]);
  check(!page.url().includes('/login'), 'login failed for ' + user);
}
async function shot(page, name) {
  if (!SHOTS) return;
  fs.mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, name) });
}
const throttle = (cdp, o) => cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 15, downloadThroughput: -1, uploadThroughput: -1, ...o });

(async () => {
  console.log(`\nE2E against ${BASE} (run ${RUN})\n`);
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'upsl-e2e-'));
  const dlDir = path.join(tmp, 'downloads');
  fs.mkdirSync(dlDir);
  const fileName = `zz-e2e-${RUN}.bin`;
  const filePath = path.join(tmp, fileName);
  fs.writeFileSync(filePath, crypto.randomBytes(24 * 1024 * 1024 + 12345)); // 5 chunks of 5 MB (+ remainder)
  const fileSha = sha256File(filePath);

  const admin = await api('POST', '/auth/login', { json: { login: ADMIN, password: PASS, device_name: 'e2e' } });
  const token = admin.token;
  const ps4 = (await api('GET', '/platforms')).find((p) => p.slug === 'ps4');
  const game = await api('POST', '/admin/games', { token, json: { platform_id: ps4.id, title: `zz-e2e Upload ${RUN}`, is_published: true, categories: ['action'], description: 'لعبة اختبار آلي' } });
  const user = { username: `zz-e2e-${RUN}`, email: `zz-e2e-${RUN}@example.com`, password: 'E2e-pass-2026' };
  const reg = await api('POST', '/auth/register', { json: { ...user, password_confirmation: user.password } });
  let fileId = null;

  const browser = await launch();
  try {
    // ---------------------------------------------------------------- 1) chunked upload
    console.log('\x1b[1mرفع الملفات من لوحة التحكم\x1b[0m');
    await test('رفع ملف 24 ميغابايت على أجزاء مع إيقاف مؤقت واستكمال وانقطاع شبكة وتحقق SHA-256', async () => {
      const page = await browser.newPage();
      await page.setViewport({ width: 1440, height: 900 });
      await login(page, ADMIN, PASS);
      await page.goto(`${BASE}/admin/games/${game.id}/edit?tab=files`, { waitUntil: 'networkidle0' });
      const cdp = await page.createCDPSession();
      await cdp.send('Network.enable');
      await throttle(cdp, { uploadThroughput: 3 * 1024 * 1024 });
      const input = await page.$('[data-uploader][data-purpose="game_file"] [data-input]');
      check(input, 'uploader input not found');
      await input.uploadFile(filePath);
      const progress = () => page.$eval('[data-queue] .bar i', (i) => parseFloat(i.style.width) || 0).catch(() => 0);
      const status = () => page.$eval('[data-queue] [data-s]', (s) => s.textContent).catch(() => '');
      await waitFor(async () => (await progress()) >= 20, 60000, 'upload 20%');
      await page.click('[data-queue] [data-p]'); // pause
      await sleep(800);
      const p1 = await progress();
      await sleep(1500);
      const p2 = await progress();
      check(p2 === p1, `progress moved while paused (${p1} → ${p2})`);
      check((await status()).includes('متوقف مؤقتاً'), 'paused state not shown');
      await shot(page, 'e2e-upload-paused.png');
      await page.click('[data-queue] [data-p]'); // resume
      await waitFor(async () => (await progress()) >= 55, 60000, 'upload 55% after resume');
      await throttle(cdp, { offline: true, uploadThroughput: 3 * 1024 * 1024 }); // the network drops
      await waitFor(async () => (await status()).includes('انقطع الاتصال'), 15000, 'offline state');
      await sleep(2000);
      await throttle(cdp, { uploadThroughput: 3 * 1024 * 1024 }); // back online
      await waitFor(async () => (await status()).includes('اكتمل الرفع والتحقق'), 120000, 'upload completed');
      const shownSha = await page.$eval('[data-queue] .sha', (s) => s.textContent.replace('SHA-256:', '').trim());
      check(shownSha === fileSha, `browser SHA-256 ${shownSha} ≠ local ${fileSha}`);
      await shot(page, 'e2e-upload-done.png');
      const g = await api('GET', `/admin/games/${game.id}`, { token });
      const f = (g.files || []).find((x) => x.sha256 === fileSha);
      check(f, 'file not registered with the expected SHA-256');
      fileId = f.id;
      await page.close();
    });

    // ---------------------------------------------------------------- 2) download manager
    console.log('\x1b[1mمدير التنزيلات للزائر\x1b[0m');
    await test('تنزيل الملف بطلبات Range مع إيقاف واستكمال وانقطاع شبكة ثم مطابقة SHA-256 للملف المحفوظ', async () => {
      check(fileId, 'no uploaded file to download');
      const ub = await launch();
      try {
        const page = await ub.newPage();
        await page.setViewport({ width: 1440, height: 900 });
        const bcdp = await ub.target().createCDPSession();
        await bcdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: dlDir, eventsEnabled: true });
        await login(page, user.username, user.password);
        await page.goto(`${BASE}/game/${game.slug}`, { waitUntil: 'networkidle0' });
        const cdp = await page.createCDPSession();
        await cdp.send('Network.enable');
        await throttle(cdp, { downloadThroughput: 3 * 1024 * 1024 });
        await page.click(`a[data-download="${fileId}"]`);
        const progress = () => page.$eval('#dl-panel .dl-item .bar i', (i) => parseFloat(i.style.width) || 0).catch(() => 0);
        const state = () => page.$eval('#dl-panel .dl-item [data-state]', (s) => s.textContent).catch(() => '');
        await waitFor(async () => (await progress()) >= 20, 60000, 'download 20%');
        await page.click('#dl-panel [data-pause]');
        await sleep(800);
        const p1 = await progress();
        await sleep(1500);
        check((await progress()) === p1, 'download moved while paused');
        check((await state()).includes('متوقف مؤقتاً'), 'paused state not shown');
        await shot(page, 'e2e-download-paused.png');
        await page.click('#dl-panel [data-pause]');
        await waitFor(async () => (await progress()) >= 55, 60000, 'download 55% after resume');
        await throttle(cdp, { offline: true });
        await waitFor(async () => (await state()).includes('انقطع الاتصال'), 20000, 'offline state');
        await sleep(1500);
        await throttle(cdp, { downloadThroughput: 3 * 1024 * 1024 });
        await waitFor(async () => (await state()).includes('SHA-256 ✓'), 120000, 'download verified');
        const saved = path.join(dlDir, fileName);
        await waitFor(() => fs.existsSync(saved) && fs.statSync(saved).size === fs.statSync(filePath).size, 30000, 'saved file');
        check(sha256File(saved) === fileSha, 'saved file SHA-256 mismatch');
        const hist = await api('GET', '/user/downloads', { token: reg.token });
        check(hist.some((h) => h.file && h.file.id === fileId), 'download not in the user history');
      } finally { await ub.close(); }
    });

    // ---------------------------------------------------------------- 3) music
    console.log('\x1b[1mالموسيقى الخلفية\x1b[0m');
    await test('لا تشغيل تلقائي قبل التفاعل، والتشغيل يدوياً، وتذكّر الاختيار بعد إعادة التحميل', async () => {
      const mb = await launch();
      try {
        const page = await mb.newPage();
        await page.setViewport({ width: 1440, height: 900 });
        await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
        await sleep(2500);
        const fetched = () => page.evaluate(() => performance.getEntriesByType('resource').some((e) => /\/assets\/audio\//.test(e.name)));
        const playing = () => page.$eval('[data-music-btn]', (b) => b.classList.contains('playing'));
        check(!(await fetched()) && !(await playing()), 'music loaded or playing before any interaction');
        await page.click('[data-music-btn]');
        await page.waitForSelector('[data-music-panel]:not([hidden])');
        await page.click('[data-music-toggle]');
        await waitFor(playing, 8000, 'music playing after click');
        check(await fetched(), 'audio file not requested');
        check((await page.evaluate(() => localStorage.getItem('upsl-music'))) === 'on', 'choice not remembered');
        await shot(page, 'e2e-music-panel.png');
        await page.reload({ waitUntil: 'networkidle0' });
        await sleep(2000);
        check(!(await playing()), 'music auto-played after reload without interaction');
        await page.keyboard.press('Shift'); // first interaction
        await waitFor(playing, 8000, 'music resumed on first interaction');
        await page.click('[data-music-btn]');
        await page.waitForSelector('[data-music-panel]:not([hidden])');
        await page.click('[data-music-toggle]');
        await waitFor(async () => !(await playing()), 5000, 'music stopped');
        check((await page.evaluate(() => localStorage.getItem('upsl-music'))) === 'off', 'stop not remembered');
      } finally { await mb.close(); }
    });

    // ---------------------------------------------------------------- 4) TV / keyboard navigation
    console.log('\x1b[1mالتنقل بلوحة المفاتيح وجهاز التحكم (التلفاز)\x1b[0m');
    await test('وضع التلفاز: الأسهم تنقل التركيز بين البطاقات و Enter يفتح اللعبة (1920×1080)', async () => {
      const page = await browser.newPage();
      await page.setUserAgent('Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 WebAppManager');
      await page.setViewport({ width: 1920, height: 1080 });
      await page.goto(BASE + '/platform/ps2', { waitUntil: 'networkidle0' });
      check(await page.$eval('body', (b) => b.classList.contains('tv-mode')), 'tv-mode not detected from the TV user agent');
      const cardTitle = () => page.evaluate(() => document.activeElement?.closest('.gcard')?.querySelector('h3')?.textContent || null);
      let first = null;
      for (let i = 0; i < 12 && !first; i++) { await page.keyboard.press('ArrowDown'); await sleep(140); first = await cardTitle(); }
      check(first, 'remote Down key never reached a game card');
      await page.keyboard.press(await page.evaluate(() => document.dir === 'rtl') ? 'ArrowLeft' : 'ArrowRight');
      await sleep(250);
      const second = await cardTitle();
      check(second && second !== first, `focus did not move to the next card (${first} → ${second})`);
      await shot(page, 'e2e-tv-focus.png');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
      check(page.url().includes('/game/'), 'Enter did not open the game page');
      await page.close();
    });
  } finally {
    await browser.close();
    // cleanup
    await api('DELETE', `/admin/games/${game.id}`, { token }).catch((e) => console.log('cleanup game:', e.message));
    await api('DELETE', `/admin/users/${reg.user.id}`, { token }).catch((e) => console.log('cleanup user:', e.message));
    await api('POST', '/auth/logout', { token }).catch(() => {});
    fs.rmSync(tmp, { recursive: true, force: true });
  }
  const failed = results.filter((r) => !r.passed).length;
  console.log(`\n${failed ? '\x1b[31m' : '\x1b[32m'}${results.length - failed} passed, ${failed} failed\x1b[0m`);
  const report = arg('report', '');
  if (report) fs.writeFileSync(report, JSON.stringify({ date: new Date().toISOString(), base: BASE, results }, null, 2));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
