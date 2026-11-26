#!/usr/bin/env bash
# Export the public pages of a running library server into a static site
# that GitHub Pages (or any static host) can serve.
#
#   tools/export_static.sh http://127.0.0.1:8099 out-dir [/base-path]
#
# Only public, read-only pages are exported. Login, accounts, admin, downloads
# and search need the PHP server and are replaced by a notice in the static copy.
set -euo pipefail
SRC="${1:?server url}"; OUT="${2:?output dir}"; BASE="${3:-}"
SRC="${SRC%/}"; BASE="${BASE%/}"
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"
PUBLIC_DIR="${PUBLIC_DIR:-$(cd "$(dirname "$0")/../server/public" && pwd)}"
HOSTPORT="${SRC#*://}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cd "$TMP"
wget --quiet --recursive --level=6 --no-parent --page-requisites \
     --adjust-extension --no-host-directories --restrict-file-names=windows \
     --reject-regex '/(login|register|logout|forgot-password|reset-password|verify-email|account|admin|download|dl|api|search)(/|\?|$)|[?&](sort|page|q)=' \
     -e robots=off "$SRC/" "$SRC/games" "$SRC/platforms" "$SRC/categories" "$SRC/updates" || true

# drop query-string copies (filters, ?v= cache busters); real assets come from public/
find . -depth -name '*@*' -exec rm -rf {} +
cp -a "$PUBLIC_DIR/assets" "$PUBLIC_DIR/media" .
cp -a "$PUBLIC_DIR/favicon.ico" "$PUBLIC_DIR/robots.txt" . 2>/dev/null || true

# wget writes /game/x as game/x.html; turn every page into dir/index.html so
# the original pretty URLs (/game/x) keep working on a static host.
find . -name '*.html' ! -name 'index.html' | while read -r f; do
  d="${f%.html}"; mkdir -p "$d"; mv "$f" "$d/index.html"
done

python3 - "$TMP" "$SRC" "$HOSTPORT" "$BASE" <<'PY'
import os, re, sys
root, src, hostport, base = sys.argv[1:]
notice = ('<div style="background:#1d2b4a;color:#fff;padding:8px 14px;text-align:center;font:14px sans-serif">'
          'نسخة عرض ثابتة على GitHub Pages — تسجيل الدخول والتنزيل ولوحة الإدارة تعمل على الخادم الكامل فقط.</div>')
for dp, _, fs in os.walk(root):
    for f in fs:
        if not f.endswith(('.html', '.css', '.js', '.webmanifest')):
            continue
        p = os.path.join(dp, f)
        t = open(p, encoding='utf-8', errors='replace').read()
        t = t.replace(src, base).replace('//' + hostport, '')
        if f.endswith('.html'):
            # root-relative links get the Pages sub-path (/repo-name)
            if base:
                t = re.sub(r'((?:href|src|action|content)=["\'])/(?!/)', r'\1' + base + '/', t)
                t = re.sub(r'url\((["\']?)/(?!/)', r'url(\1' + base + '/', t)
            t = re.sub(r'<meta name="csrf-token"[^>]*>', '', t)
            t = re.sub(r'<input type="hidden" name="_csrf"[^>]*>', '', t)
            t = re.sub(r'(<body[^>]*>)', r'\1' + notice, t, count=1)
        elif base:
            t = re.sub(r'url\((["\']?)/(?!/)', r'url(\1' + base + '/', t)
        open(p, 'w', encoding='utf-8').write(t)
PY

mkdir -p "$OUT"
cp -a "$TMP"/. "$OUT"/
touch "$OUT/.nojekyll"
[ -f "$OUT/index.html" ] && cp "$OUT/index.html" "$OUT/404.html"
n=$(find "$OUT" -name index.html | wc -l)
[ "$n" -ge 5 ] || { echo "export failed: only $n pages (is the server up?)" >&2; exit 1; }
echo "Exported $n pages to $OUT"
