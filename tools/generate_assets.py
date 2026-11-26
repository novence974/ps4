#!/usr/bin/env python3
"""
Generates every original visual asset of ULTIMATE PS LIBRARY:
  - brand mark, favicon, default avatar, cover placeholder, PWA icons
  - platform logos (typographic, no official marks) and platform hero images
  - covers + in-game style screenshots for the fictional demo catalogue
  - two short demo trailers (ffmpeg) and server/database/demo/games.json
All art is procedural and invented here; it can be used freely with the project.

Usage: python3 tools/generate_assets.py   (needs rsvg-convert, ffmpeg and the Readex Pro font installed)
"""
import json, math, pathlib, random, shutil, subprocess, sys
from xml.sax.saxutils import escape

sys.path.insert(0, str(pathlib.Path(__file__).parent))
from demo_games import GAMES, PLATFORM_COLORS, PLATFORM_GEN  # noqa: E402

ROOT = pathlib.Path(__file__).resolve().parent.parent
SERVER = ROOT / 'server'
BRAND = SERVER / 'public/assets/img/brand'
PLAT = SERVER / 'database/assets/platforms'
DEMO = SERVER / 'database/demo'
FONT = "Readex Pro"

def rsvg(svg: str, out: pathlib.Path, w: int, h: int):
    out.parent.mkdir(parents=True, exist_ok=True)
    tmp = out.with_suffix('.svg.tmp')
    tmp.write_text(svg)
    subprocess.run(['rsvg-convert', '-w', str(w), '-h', str(h), '-o', str(out), str(tmp)], check=True)
    tmp.unlink()

def hx(c):
    c = c.lstrip('#')
    if len(c) == 3:
        c = ''.join(ch * 2 for ch in c)
    return tuple(int(c[i:i + 2], 16) for i in (0, 2, 4))

def mix(a, b, t):
    A, B = hx(a), hx(b)
    return '#%02x%02x%02x' % tuple(round(A[i] + (B[i] - A[i]) * t) for i in range(3))

# ---------------------------------------------------------------- brand
LOGO = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
<defs>
<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8b7cff"/><stop offset=".55" stop-color="#5aa9ff"/><stop offset="1" stop-color="#5ee6f0"/></linearGradient>
<linearGradient id="b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1c2257"/><stop offset="1" stop-color="#0a0e28"/></linearGradient>
</defs>
<rect x="2" y="2" width="60" height="60" rx="17" fill="url(#b)"/>
<rect x="2.75" y="2.75" width="58.5" height="58.5" rx="16.25" fill="none" stroke="url(#g)" stroke-width="1.5" opacity=".9"/>
<rect x="14" y="18" width="8.5" height="29" rx="2.6" fill="url(#g)" opacity=".5"/>
<rect x="25.5" y="13" width="8.5" height="34" rx="2.6" fill="url(#g)" opacity=".78"/>
<rect x="37" y="17" width="8.5" height="30" rx="2.6" fill="url(#g)" transform="rotate(15 41.25 47)"/>
<rect x="12" y="49.5" width="40" height="3.2" rx="1.6" fill="url(#g)"/>
</svg>'''

AVATAR = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6d5df5"/><stop offset="1" stop-color="#2f7ef5"/></linearGradient></defs>
<rect width="128" height="128" fill="url(#g)"/>
<circle cx="64" cy="50" r="22" fill="#fff" fill-opacity=".85"/>
<path d="M22 118c4-24 21-38 42-38s38 14 42 38" fill="#fff" fill-opacity=".85"/>
</svg>'''

PLACEHOLDER = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 900">
<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1a2050"/><stop offset="1" stop-color="#090c24"/></linearGradient>
<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8b7cff"/><stop offset=".55" stop-color="#5aa9ff"/><stop offset="1" stop-color="#5ee6f0"/></linearGradient></defs>
<rect width="600" height="900" fill="url(#bg)"/>
<g transform="translate(220 330) scale(2.5)" opacity=".55">
<rect x="14" y="18" width="8.5" height="29" rx="2.6" fill="url(#g)" opacity=".5"/><rect x="25.5" y="13" width="8.5" height="34" rx="2.6" fill="url(#g)" opacity=".78"/>
<rect x="37" y="17" width="8.5" height="30" rx="2.6" fill="url(#g)" transform="rotate(15 41.25 47)"/><rect x="12" y="49.5" width="40" height="3.2" rx="1.6" fill="url(#g)"/></g>
</svg>'''

def brand():
    BRAND.mkdir(parents=True, exist_ok=True)
    (BRAND / 'logo.svg').write_text(LOGO)
    (BRAND / 'favicon.svg').write_text(LOGO)
    (BRAND / 'avatar.svg').write_text(AVATAR)
    (BRAND / 'cover-placeholder.svg').write_text(PLACEHOLDER)
    for s in (192, 512):
        rsvg(LOGO, BRAND / f'icon-{s}.png', s, s)

# ---------------------------------------------------------------- platforms
def platform_logo(slug, color):
    label = slug.upper()
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
<defs><linearGradient id="t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="{mix(color, '#ffffff', .25)}"/></linearGradient>
<filter id="glow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="14"/></filter></defs>
<text x="256" y="300" text-anchor="middle" font-family="{FONT}" font-weight="700" font-size="196" letter-spacing="-8" fill="{color}" opacity=".55" filter="url(#glow)">{label}</text>
<text x="256" y="300" text-anchor="middle" font-family="{FONT}" font-weight="700" font-size="196" letter-spacing="-8" fill="url(#t)">{label}</text>
<rect x="150" y="338" width="212" height="10" rx="5" fill="{color}"/>
</svg>'''

def platform_image(slug, color):
    gen = PLATFORM_GEN[slug]
    r = random.Random(slug)
    streaks = ''.join(
        f'<rect x="{r.randint(-200, 1500)}" y="{r.randint(-100, 900)}" width="{r.randint(400, 900)}" height="{r.choice([2, 3, 4])}" fill="{color}" opacity="{r.uniform(.08, .3):.2f}" transform="rotate(-24 800 450)"/>'
        for _ in range(26))
    grid = ''.join(f'<line x1="{x}" y1="560" x2="{800 + (x - 800) * 3}" y2="900" stroke="{color}" stroke-opacity=".22" stroke-width="2"/>' for x in range(-400, 2001, 120))
    grid += ''.join(f'<line x1="0" y1="{560 + i * i * 9}" x2="1600" y2="{560 + i * i * 9}" stroke="{color}" stroke-opacity="{.08 + i * .03:.2f}" stroke-width="2"/>' for i in range(1, 12))
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900">
<defs><radialGradient id="a" cx=".72" cy=".35" r=".6"><stop offset="0" stop-color="{color}" stop-opacity=".55"/><stop offset="1" stop-color="{color}" stop-opacity="0"/></radialGradient>
<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0b0f2c"/><stop offset="1" stop-color="#05071a"/></linearGradient>
<filter id="b"><feGaussianBlur stdDeviation="40"/></filter></defs>
<rect width="1600" height="900" fill="url(#bg)"/>
<rect width="1600" height="900" fill="url(#a)"/>
<circle cx="1180" cy="300" r="230" fill="{color}" opacity=".35" filter="url(#b)"/>
{streaks}{grid}
<text x="1180" y="640" text-anchor="middle" font-family="{FONT}" font-weight="700" font-size="620" fill="none" stroke="{color}" stroke-opacity=".55" stroke-width="5">{gen}</text>
<text x="1180" y="640" text-anchor="middle" font-family="{FONT}" font-weight="700" font-size="620" fill="{color}" fill-opacity=".08">{gen}</text>
</svg>'''

def platforms():
    for slug, color in PLATFORM_COLORS.items():
        rsvg(platform_logo(slug, color), PLAT / f'{slug}-logo.png', 512, 512)
        rsvg(platform_image(slug, color), PLAT / f'{slug}.png', 1600, 900)

# ---------------------------------------------------------------- scene motifs (shared by covers and screenshots)
def stars(r, W, H, n, top=1.0):
    return ''.join(f'<circle cx="{r.uniform(0, W):.1f}" cy="{r.uniform(0, H * top):.1f}" r="{r.uniform(.6, 2.2):.1f}" fill="#fff" opacity="{r.uniform(.25, .9):.2f}"/>' for _ in range(n))

def mountains(r, W, base, height, color, opacity, jag=9):
    pts = [(0, base)]
    for i in range(jag + 1):
        pts.append((W * i / jag, base - r.uniform(.35, 1.0) * height))
    pts += [(W, base), (W, base + 2000), (0, base + 2000)]
    return f'<polygon points="{" ".join(f"{x:.0f},{y:.0f}" for x, y in pts)}" fill="{color}" opacity="{opacity}"/>'

def scene(style, c0, c1, c2, W, H, r):
    hz = H * (0.58 if H > W else 0.62)
    p = [f'<rect width="{W}" height="{H}" fill="url(#bg)"/>']
    if style in ('sun', 'road'):
        p.append(f'<rect width="{W}" height="{hz:.0f}" fill="url(#sky)"/>')
        p.append(stars(r, W, H, 60, .45))
        sr = W * (0.3 if H > W else 0.16)
        cx, cy = W / 2, hz - sr * .25
        stripes = ''.join(f'<rect x="{cx - sr}" y="{cy + sr * (0.1 + k * 0.16):.0f}" width="{2 * sr:.0f}" height="{sr * (0.03 + k * 0.022):.0f}" fill="{c0}"/>' for k in range(6))
        p.append(f'<g><circle cx="{cx}" cy="{cy:.0f}" r="{sr:.0f}" fill="url(#sun)"/>{stripes}</g>')
        p.append(mountains(r, W, hz, H * .16, mix(c0, '#000000', .2), .95, 11))
        if style == 'sun':
            for x in range(-W, 2 * W + 1, int(W / 12)):
                p.append(f'<line x1="{W / 2 + (x - W / 2) * .08:.0f}" y1="{hz:.0f}" x2="{x}" y2="{H}" stroke="{c2}" stroke-opacity=".55" stroke-width="2"/>')
            for k in range(1, 14):
                y = hz + (H - hz) * (k / 13) ** 2
                p.append(f'<line x1="0" y1="{y:.0f}" x2="{W}" y2="{y:.0f}" stroke="{c1}" stroke-opacity="{.15 + k * .04:.2f}" stroke-width="2"/>')
        else:
            p.append(f'<polygon points="{W * .46:.0f},{hz:.0f} {W * .54:.0f},{hz:.0f} {W * 1.15:.0f},{H} {-W * .15:.0f},{H}" fill="#0b0b14" opacity=".9"/>')
            for k in range(9):
                t0, t1 = (k / 9) ** 2, ((k + .5) / 9) ** 2
                y0, y1 = hz + (H - hz) * t0, hz + (H - hz) * t1
                w0, w1 = 2 + 10 * t0, 2 + 10 * t1
                p.append(f'<polygon points="{W / 2 - w0:.0f},{y0:.0f} {W / 2 + w0:.0f},{y0:.0f} {W / 2 + w1:.0f},{y1:.0f} {W / 2 - w1:.0f},{y1:.0f}" fill="{c2}" opacity=".9"/>')
            for _ in range(16):
                y = r.uniform(hz, H); x = r.uniform(0, W)
                p.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{r.uniform(60, 220):.0f}" height="3" fill="{c1}" opacity="{r.uniform(.2, .6):.2f}" filter="url(#soft)"/>')
    elif style == 'moon':
        p.append(f'<rect width="{W}" height="{H}" fill="url(#sky)"/>')
        p.append(stars(r, W, H, 140, .7))
        mr = W * (0.2 if H > W else 0.1)
        mx, my = W * .68, H * .26
        p.append(f'<circle cx="{mx:.0f}" cy="{my:.0f}" r="{mr * 1.9:.0f}" fill="{c1}" opacity=".18" filter="url(#glow)"/>')
        p.append(f'<mask id="cres"><rect width="{W}" height="{H}" fill="#fff"/><circle cx="{mx + mr * .45:.0f}" cy="{my - mr * .2:.0f}" r="{mr * .92:.0f}" fill="#000"/></mask>')
        p.append(f'<circle cx="{mx:.0f}" cy="{my:.0f}" r="{mr:.0f}" fill="{c1}" mask="url(#cres)"/>')
        for k, (col, op) in enumerate([(mix(c1, c0, .55), .9), (mix(c1, c0, .75), .95), (mix(c0, '#000', .3), 1)]):
            base = hz + H * (0.05 + k * .1)
            a = r.uniform(.2, .8)
            p.append(f'<path d="M0 {base:.0f} C {W * .25:.0f} {base - H * .12 * a:.0f}, {W * .55:.0f} {base + H * .08:.0f}, {W} {base - H * .06:.0f} L {W} {H} L 0 {H} Z" fill="{col}" opacity="{op}"/>')
        tx = W * (.18 if H > W else .22); th = H * .32
        p.append(f'<g fill="{mix(c0, "#000", .5)}"><rect x="{tx - 14:.0f}" y="{hz - th:.0f}" width="28" height="{th:.0f}"/><rect x="{tx - 22:.0f}" y="{hz - th * .78:.0f}" width="44" height="10"/>'
                 f'<circle cx="{tx:.0f}" cy="{hz - th:.0f}" r="18"/><rect x="{tx - 2:.0f}" y="{hz - th - 46:.0f}" width="4" height="30"/></g>')
    elif style == 'forest':
        p.append(f'<rect width="{W}" height="{H}" fill="url(#sky)"/>')
        lx, ly = W * .5, H * .62
        p.append(f'<circle cx="{lx:.0f}" cy="{ly:.0f}" r="{W * .28:.0f}" fill="{c2}" opacity=".22" filter="url(#glow)"/>')
        for layer in range(4):
            col = mix(c0, c1, .15 + layer * .12) if layer < 3 else mix(c0, '#000', .4)
            base = H * (.62 + layer * .1)
            for _ in range(14 - layer * 2):
                x = r.uniform(-60, W + 60); th = r.uniform(H * .25, H * .55) * (1 - layer * .12); tw = th * .32
                p.append(f'<polygon points="{x:.0f},{base - th:.0f} {x + tw / 2:.0f},{base:.0f} {x - tw / 2:.0f},{base:.0f}" fill="{col}"/>')
            p.append(f'<rect x="0" y="{base:.0f}" width="{W}" height="{H}" fill="{col}"/>')
            p.append(f'<rect x="0" y="{base - H * .06:.0f}" width="{W}" height="{H * .08:.0f}" fill="{c2}" opacity=".06" filter="url(#soft)"/>')
        p.append(f'<g transform="translate({lx:.0f} {ly + 30:.0f})"><rect x="-16" y="-34" width="32" height="44" rx="6" fill="{c2}" opacity=".95"/><rect x="-20" y="-40" width="40" height="8" rx="3" fill="#1a1a1a"/><circle r="70" fill="{c2}" opacity=".25" filter="url(#glow)"/></g>')
    elif style == 'crystal':
        p.append(f'<rect width="{W}" height="{H}" fill="url(#sky)"/>')
        p.append(stars(r, W, H, 90))
        cx, cy, s = W / 2, H * (.45 if H > W else .5), min(W, H) * .34
        facets = [((0, -1), (.55, -.35), (0, -.2)), ((0, -1), (0, -.2), (-.55, -.35)), ((-.55, -.35), (0, -.2), (-.4, .55)), ((.55, -.35), (.4, .55), (0, -.2)),
                  ((0, -.2), (.4, .55), (0, 1)), ((0, -.2), (0, 1), (-.4, .55))]
        p.append(f'<circle cx="{cx:.0f}" cy="{cy:.0f}" r="{s * 1.3:.0f}" fill="{c1}" opacity=".25" filter="url(#glow)"/>')
        for k, f in enumerate(facets):
            col = mix(c1, c2, k / 6) if k % 2 == 0 else mix(c1, '#ffffff', .25 + k * .06)
            pts = ' '.join(f'{cx + x * s:.0f},{cy + y * s:.0f}' for x, y in f)
            p.append(f'<polygon points="{pts}" fill="{col}" opacity="{.75 + (k % 3) * .08:.2f}" stroke="#fff" stroke-opacity=".35" stroke-width="2"/>')
        for _ in range(12):
            x, y, z = r.uniform(0, W), r.uniform(0, H), r.uniform(6, 16)
            p.append(f'<path d="M{x:.0f} {y - z:.0f} L{x + z * .25:.0f} {y:.0f} L{x:.0f} {y + z:.0f} L{x - z * .25:.0f} {y:.0f} Z M{x - z:.0f} {y:.0f} L{x:.0f} {y - z * .25:.0f} L{x + z:.0f} {y:.0f} L{x:.0f} {y + z * .25:.0f} Z" fill="#fff" opacity=".7"/>')
    elif style == 'planet':
        p.append(stars(r, W, H, 220))
        pr = min(W, H) * .32
        px, py = W * .55, H * (.42 if H > W else .5)
        p.append(f'<circle cx="{px:.0f}" cy="{py:.0f}" r="{pr * 1.25:.0f}" fill="{c1}" opacity=".25" filter="url(#glow)"/>')
        p.append(f'<circle cx="{px:.0f}" cy="{py:.0f}" r="{pr:.0f}" fill="url(#planet)"/>')
        for k in range(5):
            y = py - pr * .6 + k * pr * .3
            p.append(f'<ellipse cx="{px:.0f}" cy="{y:.0f}" rx="{pr * (.9 - abs(k - 2) * .15):.0f}" ry="{pr * .05:.0f}" fill="{c0}" opacity=".18"/>')
        p.append(f'<ellipse cx="{px:.0f}" cy="{py:.0f}" rx="{pr * 1.8:.0f}" ry="{pr * .42:.0f}" fill="none" stroke="{c2}" stroke-width="{pr * .07:.0f}" opacity=".8" transform="rotate(-18 {px:.0f} {py:.0f})"/>')
        p.append(f'<circle cx="{W * .2:.0f}" cy="{H * .2:.0f}" r="{pr * .16:.0f}" fill="{mix(c2, "#fff", .3)}"/>')
    elif style == 'pitch':
        p.append(f'<rect width="{W}" height="{H}" fill="{mix(c0, "#000", .1)}"/>')
        for k in range(12):
            y0 = hz + (H - hz) * (k / 12) ** 1.6; y1 = hz + (H - hz) * ((k + 1) / 12) ** 1.6
            p.append(f'<rect x="0" y="{y0:.0f}" width="{W}" height="{y1 - y0 + 1:.0f}" fill="{mix(c0, c1, .18 if k % 2 else .32)}"/>')
        p.append(f'<rect width="{W}" height="{hz:.0f}" fill="url(#sky)"/>')
        for k in range(7):
            x = W * (.08 + k * .14)
            p.append(f'<rect x="{x:.0f}" y="{hz * .1:.0f}" width="6" height="{hz * .5:.0f}" fill="#ddd" opacity=".5"/><circle cx="{x + 3:.0f}" cy="{hz * .1:.0f}" r="{W * .04:.0f}" fill="{c2}" opacity=".35" filter="url(#glow)"/>')
        cy = hz + (H - hz) * .45
        p.append(f'<ellipse cx="{W / 2:.0f}" cy="{cy:.0f}" rx="{W * .3:.0f}" ry="{(H - hz) * .16:.0f}" fill="none" stroke="#fff" stroke-opacity=".75" stroke-width="5"/>')
        p.append(f'<line x1="0" y1="{cy:.0f}" x2="{W}" y2="{cy:.0f}" stroke="#fff" stroke-opacity=".75" stroke-width="5"/>')
        br = min(W, H) * .085; bx, by = W * .62, cy + (H - cy) * .45
        p.append(f'<ellipse cx="{bx:.0f}" cy="{by + br * .95:.0f}" rx="{br:.0f}" ry="{br * .25:.0f}" fill="#000" opacity=".35"/>')
        p.append(f'<circle cx="{bx:.0f}" cy="{by:.0f}" r="{br:.0f}" fill="#fff"/><polygon points="{bx:.0f},{by - br * .35:.0f} {bx + br * .33:.0f},{by - br * .1:.0f} {bx + br * .2:.0f},{by + br * .3:.0f} {bx - br * .2:.0f},{by + br * .3:.0f} {bx - br * .33:.0f},{by - br * .1:.0f}" fill="#1a1a1a"/>')
        p.append(f'<circle cx="{bx:.0f}" cy="{by:.0f}" r="{br * 1.6:.0f}" fill="{c2}" opacity=".25" filter="url(#glow)"/>')
    elif style == 'arena':
        cx, cy = W / 2, H * .5
        for k in range(9, 0, -1):
            p.append(f'<circle cx="{cx:.0f}" cy="{cy:.0f}" r="{min(W, H) * k * .07:.0f}" fill="none" stroke="{c1 if k % 2 else c2}" stroke-opacity="{.1 + (9 - k) * .05:.2f}" stroke-width="{3 + k}"/>')
        for _ in range(7):
            a = r.uniform(-60, -20); x = r.uniform(-W * .2, W * .9); y = r.uniform(0, H)
            p.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{W * r.uniform(.5, 1.1):.0f}" height="{r.uniform(8, 26):.0f}" fill="{r.choice([c1, c2])}" opacity="{r.uniform(.35, .75):.2f}" transform="rotate({a:.0f} {x:.0f} {y:.0f})"/>')
        for _ in range(40):
            x, y = r.uniform(0, W), r.uniform(0, H)
            p.append(f'<circle cx="{x:.0f}" cy="{y:.0f}" r="{r.uniform(1.5, 4):.1f}" fill="{c2}" opacity="{r.uniform(.4, 1):.2f}"/>')
        p.append(f'<circle cx="{cx:.0f}" cy="{cy:.0f}" r="{min(W, H) * .18:.0f}" fill="{c1}" opacity=".35" filter="url(#glow)"/>')
    elif style == 'blocks':
        p.append(f'<rect width="{W}" height="{H}" fill="url(#sky)"/>')
        for _ in range(6):
            x, y, s = r.uniform(0, W), r.uniform(0, H * .4), r.uniform(40, 90)
            p.append(f'<g fill="#fff" opacity=".18"><circle cx="{x:.0f}" cy="{y:.0f}" r="{s:.0f}"/><circle cx="{x + s:.0f}" cy="{y + 10:.0f}" r="{s * .8:.0f}"/><circle cx="{x - s:.0f}" cy="{y + 14:.0f}" r="{s * .7:.0f}"/></g>')
        size = min(W, H) * .11
        ox, oy = W / 2, H * (.48 if H > W else .5)
        cols = [c1, c2, mix(c1, c2, .5), mix(c1, '#ffffff', .3), mix(c2, '#000000', .2)]
        cells = [(i, j, r.randint(0, 2)) for i in range(-3, 4) for j in range(-3, 4) if abs(i) + abs(j) <= 4]
        cells.sort(key=lambda c: (c[0] + c[1], c[2]))
        for i, j, hgt in cells:
            for z in range(hgt + 1):
                x = ox + (i - j) * size * .87; y = oy + (i + j) * size * .5 - z * size
                col = r.choice(cols)
                top = f'{x:.0f},{y - size * .5:.0f} {x + size * .87:.0f},{y:.0f} {x:.0f},{y + size * .5:.0f} {x - size * .87:.0f},{y:.0f}'
                left = f'{x - size * .87:.0f},{y:.0f} {x:.0f},{y + size * .5:.0f} {x:.0f},{y + size * 1.5:.0f} {x - size * .87:.0f},{y + size:.0f}'
                right = f'{x + size * .87:.0f},{y:.0f} {x:.0f},{y + size * .5:.0f} {x:.0f},{y + size * 1.5:.0f} {x + size * .87:.0f},{y + size:.0f}'
                p.append(f'<polygon points="{left}" fill="{mix(col, "#000", .3)}"/><polygon points="{right}" fill="{mix(col, "#000", .15)}"/><polygon points="{top}" fill="{col}"/>')
    elif style == 'city':
        p.append(f'<rect width="{W}" height="{H}" fill="url(#sky)"/>')
        p.append(f'<circle cx="{W * .75:.0f}" cy="{H * .2:.0f}" r="{min(W, H) * .09:.0f}" fill="{c2}" opacity=".8"/><circle cx="{W * .75:.0f}" cy="{H * .2:.0f}" r="{min(W, H) * .2:.0f}" fill="{c2}" opacity=".2" filter="url(#glow)"/>')
        for layer in range(3):
            col = mix(c0, c1, .3 - layer * .1) if layer < 2 else mix(c0, '#000', .5)
            x = -20
            while x < W:
                bw = r.uniform(W * .06, W * .14); bh = r.uniform(H * .15, H * (.45 - layer * .08))
                base = H * (.78 + layer * .08)
                p.append(f'<rect x="{x:.0f}" y="{base - bh:.0f}" width="{bw:.0f}" height="{bh + H:.0f}" fill="{col}"/>')
                if layer == 1:
                    for wy in range(int(base - bh + 14), int(base), 22):
                        for wx in range(int(x + 8), int(x + bw - 8), 16):
                            if r.random() < .28:
                                p.append(f'<rect x="{wx}" y="{wy}" width="8" height="10" fill="{c2}" opacity="{r.uniform(.4, .9):.2f}"/>')
                x += bw + r.uniform(2, 14)
        p.append(f'<rect x="0" y="{H * .55:.0f}" width="{W}" height="{H * .25:.0f}" fill="{c1}" opacity=".12" filter="url(#soft)"/>')
    return ''.join(p)

def defs(c0, c1, c2, W, H):
    return f'''<defs>
<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{c0}"/><stop offset="1" stop-color="{mix(c0, '#000000', .55)}"/></linearGradient>
<linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{mix(c0, '#000000', .3)}"/><stop offset=".75" stop-color="{c0}"/><stop offset="1" stop-color="{mix(c0, c1, .45)}"/></linearGradient>
<linearGradient id="sun" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{c2}"/><stop offset="1" stop-color="{c1}"/></linearGradient>
<radialGradient id="planet" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="{mix(c1, '#ffffff', .35)}"/><stop offset=".6" stop-color="{c1}"/><stop offset="1" stop-color="{mix(c0, '#000', .3)}"/></radialGradient>
<linearGradient id="fade" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".85"/></linearGradient>
<filter id="glow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="{max(W, H) * .03:.0f}"/></filter>
<filter id="soft" x="-20%" y="-50%" width="140%" height="200%"><feGaussianBlur stdDeviation="6"/></filter>
<filter id="shadow" x="-10%" y="-10%" width="120%" height="140%"><feDropShadow dx="0" dy="6" stdDeviation="10" flood-color="#000" flood-opacity=".7"/></filter>
</defs>'''

def wrap(title, limit):
    words, lines, cur = title.split(), [], ''
    for w in words:
        if cur and len(cur) + 1 + len(w) > limit:
            lines.append(cur); cur = w
        else:
            cur = (cur + ' ' + w).strip()
    lines.append(cur)
    return lines

def cover_svg(g):
    W, H = 600, 900
    c0, c1, c2 = g['colors']
    r = random.Random(g['id'] * 101)
    lines = wrap(g['title'].upper(), 12)
    fs = 74 if max(len(x) for x in lines) <= 9 else (62 if max(len(x) for x in lines) <= 12 else 52)
    y0 = H - 150 - (len(lines) - 1) * fs * .95
    text = ''.join(f'<text x="44" y="{y0 + i * fs * .95:.0f}" font-family="{FONT}" font-weight="700" font-size="{fs}" letter-spacing="-1.5" fill="#ffffff" filter="url(#shadow)">{escape(line)}</text>' for i, line in enumerate(lines))
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}">{defs(c0, c1, c2, W, H)}
{scene(g['style'], c0, c1, c2, W, H, r)}
<rect y="{H * .55:.0f}" width="{W}" height="{H * .45:.0f}" fill="url(#fade)"/>
{text}
<rect x="44" y="{H - 96}" width="64" height="5" rx="2.5" fill="{c2}"/>
<text x="44" y="{H - 56}" font-family="{FONT}" font-weight="500" font-size="24" letter-spacing="3" fill="#ffffff" fill-opacity=".85">{escape(g['developer'].upper())}</text>
<text x="{W - 44}" y="{H - 56}" text-anchor="end" font-family="{FONT}" font-weight="600" font-size="17" letter-spacing="2" fill="#fff" fill-opacity=".55">DEMO</text>
</svg>'''

def screen_svg(g, k):
    W, H = 1600, 900
    c0, c1, c2 = g['colors']
    r = random.Random(g['id'] * 977 + k)
    hud = f'''<g font-family="{FONT}" fill="#fff">
<rect x="48" y="44" width="420" height="22" rx="11" fill="#000" fill-opacity=".45" stroke="#fff" stroke-opacity=".3"/>
<rect x="52" y="48" width="{r.randint(180, 400)}" height="14" rx="7" fill="{c2}"/>
<rect x="48" y="76" width="300" height="14" rx="7" fill="#000" fill-opacity=".45"/><rect x="51" y="79" width="{r.randint(80, 290)}" height="8" rx="4" fill="{c1}"/>
<circle cx="{W - 130}" cy="{H - 130}" r="92" fill="#000" fill-opacity=".45" stroke="#fff" stroke-opacity=".35" stroke-width="3"/>
<circle cx="{W - 130}" cy="{H - 130}" r="6" fill="{c2}"/>
<path d="M{W - 130} {H - 205} l10 22 h-20z" fill="#fff" fill-opacity=".8"/>
<rect x="{W - 470}" y="44" width="420" height="74" rx="14" fill="#000" fill-opacity=".4"/>
<text x="{W - 450}" y="76" font-size="22" font-weight="600">{escape(['Reach the beacon', 'Find the hidden key', 'Win the final round', 'Escort the convoy', 'Collect 3 crystals'][(g['id'] + k) % 5])}</text>
<text x="{W - 450}" y="104" font-size="17" fill-opacity=".7">Demo screenshot — {escape(g['title'])}</text>
</g>'''
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}">{defs(c0, c1, c2, W, H)}
{scene(g['style'], c0, c1, c2, W, H, r)}
{hud}
</svg>'''

def to_jpeg(png: pathlib.Path):
    from PIL import Image  # pillow
    Image.open(png).convert('RGB').save(png.with_suffix('.jpg'), quality=86, optimize=True, progressive=True)
    png.unlink()

def demo():
    (DEMO / 'covers').mkdir(parents=True, exist_ok=True)
    (DEMO / 'screens').mkdir(parents=True, exist_ok=True)
    data = []
    for g in GAMES:
        src = next((x for x in GAMES if x['id'] == g.get('cover_of')), g)
        if g.get('cover_of') is None:
            rsvg(cover_svg(g), DEMO / 'covers' / f"{g['id']}.png", 600, 900)
            to_jpeg(DEMO / 'covers' / f"{g['id']}.png")
            for k in (1, 2, 3):
                rsvg(screen_svg(g, k), DEMO / 'screens' / f"{g['id']}-{k}.png", 1600, 900)
                to_jpeg(DEMO / 'screens' / f"{g['id']}-{k}.png")
        item = {k: v for k, v in g.items() if k not in ('style', 'colors', 'cover_of')}
        item['cover'] = f"covers/{src['id']}.jpg"
        item['screens'] = [f"screens/{src['id']}-{k}.jpg" for k in (1, 2, 3)]
        data.append(item)
    (DEMO / 'games.json').write_text(json.dumps({'games': data}, ensure_ascii=False, indent=1))

def trailers():
    out = DEMO / 'trailers'
    out.mkdir(parents=True, exist_ok=True)
    music = SERVER / 'public/assets/audio/night-shelf.mp3'
    for gid in (3, 10):
        shots = [DEMO / 'screens' / f'{gid}-{k}.jpg' for k in (1, 2, 3)]
        inputs = []
        for s in shots:
            inputs += ['-loop', '1', '-t', '4', '-i', str(s)]
        flt = ';'.join(f'[{i}:v]scale=1920:1080,zoompan=z=\'min(zoom+0.0009,1.12)\':d=100:s=1280x720:fps=25,setsar=1[v{i}]' for i in range(3))
        flt += ';[v0][v1]xfade=transition=fade:duration=0.8:offset=3.2[x1];[x1][v2]xfade=transition=fade:duration=0.8:offset=6.4,format=yuv420p[v]'
        subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', *inputs, '-i', str(music), '-filter_complex', flt, '-map', '[v]', '-map', '3:a',
                        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-c:a', 'aac', '-b:a', '96k', '-af', 'afade=t=in:d=0.6,afade=t=out:st=9.3:d=1.1',
                        '-t', '10.4', '-movflags', '+faststart', str(out / f'{gid}.mp4')], check=True)

if __name__ == '__main__':
    for tool in ('rsvg-convert', 'ffmpeg'):
        if not shutil.which(tool):
            sys.exit(f'{tool} is required')
    brand(); print('brand ✓')
    platforms(); print('platforms ✓')
    demo(); print('demo covers/screens ✓')
    trailers(); print('trailers ✓')
