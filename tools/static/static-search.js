/* Static (GitHub Pages / Firebase Hosting) copy only: search, filters and sort
   run in the browser on the cards already in the page, because there is no PHP
   server behind it. Added by tools/export_static.sh. */
(function () {
  'use strict';
  var cards = Array.prototype.slice.call(document.querySelectorAll('.gcard[data-title]'));
  var grid = cards.length ? cards[0].parentNode : null;
  if (!grid) return;
  var q = new URLSearchParams(location.search);
  var want = {
    q: (q.get('q') || '').trim().toLowerCase(),
    platform: q.get('platform') || '',
    category: q.get('category') || '',
    year: q.get('year') || '',
    sort: q.get('sort') || ''
  };
  var shown = 0;
  cards.forEach(function (c) {
    var d = c.dataset, ok = true;
    if (want.q && d.title.indexOf(want.q) < 0) ok = false;
    if (want.platform && d.platform !== want.platform) ok = false;
    if (want.category && (' ' + d.cats + ' ').indexOf(' ' + want.category + ' ') < 0) ok = false;
    if (want.year && d.year !== want.year) ok = false;
    c.hidden = !ok;
    if (ok) shown++;
  });
  var by = {
    title: function (a, b) { return a.dataset.title.localeCompare(b.dataset.title); },
    rating: function (a, b) { return (+b.dataset.rating) - (+a.dataset.rating); },
    year: function (a, b) { return (+b.dataset.year) - (+a.dataset.year); }
  }[want.sort];
  if (by) cards.slice().sort(by).forEach(function (c) { grid.appendChild(c); });

  var count = document.querySelector('.results-bar .count b');
  if (count) count.textContent = shown;
  document.querySelectorAll('.pagination, nav[aria-label*="صفحات"]').forEach(function (p) { p.hidden = true; });
  ['q', 'platform', 'category', 'year', 'sort'].forEach(function (k) {
    document.querySelectorAll('[name="' + k + '"]').forEach(function (el) { if (want[k]) el.value = want[k]; });
  });
  if (!shown) {
    var p = document.createElement('p');
    p.className = 'muted'; p.style.padding = '40px 0'; p.style.textAlign = 'center';
    p.textContent = 'لا توجد ألعاب تطابق البحث. جرّب كلمة أخرى أو أزل أحد الفلاتر.';
    grid.parentNode.insertBefore(p, grid.nextSibling);
  }
})();
