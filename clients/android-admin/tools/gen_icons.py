#!/usr/bin/env python3
"""Converts the site's Lucide icon sprite (icons.svg) into Android VectorDrawables (res/drawable/ic_*.xml).

VectorDrawable only knows <path>, so circles, rects, lines and polylines are rewritten as path data.
Path data is re-tokenised and written with explicit separators (older Android versions mis-parse
compact arc flags such as "a1 1 0 011 1").
"""
import math
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

NUM = re.compile(r"[-+]?(?:\d+\.\d*|\.\d+|\d+)(?:[eE][-+]?\d+)?")
CMD_ARGS = {"m": 2, "l": 2, "h": 1, "v": 1, "c": 6, "s": 4, "q": 4, "t": 2, "a": 7, "z": 0}


def fmt(v):
    s = ("%.3f" % v).rstrip("0").rstrip(".")
    return "0" if s in ("-0", "") else s


def tokenize_path(d):
    """Yields (command, [numbers...]) with arc flags parsed correctly."""
    i, n = 0, len(d)
    out = []
    cmd = None
    while i < n:
        ch = d[i]
        if ch.isspace() or ch == ",":
            i += 1
            continue
        if ch.isalpha():
            cmd = ch
            i += 1
            if cmd in "zZ":
                out.append((cmd, []))
            continue
        if cmd is None:
            raise ValueError("path data starts with a number: " + d)
        count = CMD_ARGS[cmd.lower()]
        args = []
        while len(args) < count:
            while i < n and (d[i].isspace() or d[i] == ","):
                i += 1
            if cmd.lower() == "a" and len(args) in (3, 4):
                if d[i] not in "01":
                    raise ValueError("bad arc flag in " + d)
                args.append(float(d[i]))
                i += 1
                continue
            m = NUM.match(d, i)
            if not m:
                raise ValueError("cannot parse number at %d in %s" % (i, d))
            args.append(float(m.group(0)))
            i = m.end()
        out.append((cmd, args))
        # implicit repetition: after M the following pairs are L
        if cmd == "m":
            cmd = "l"
        elif cmd == "M":
            cmd = "L"
    return out


def clean_path(d):
    parts = []
    for cmd, args in tokenize_path(d):
        if cmd.lower() == "a":
            a = args
            parts.append("%s%s %s %s %d %d %s %s" % (cmd, fmt(a[0]), fmt(a[1]), fmt(a[2]), int(a[3]), int(a[4]), fmt(a[5]), fmt(a[6])))
        else:
            parts.append(cmd + " ".join(fmt(x) for x in args))
    return " ".join(parts)


def f(el, name, default=0.0):
    v = el.get(name)
    return float(v) if v not in (None, "") else default


def circle(cx, cy, r):
    return "M%s %s A%s %s 0 1 0 %s %s A%s %s 0 1 0 %s %s Z" % (
        fmt(cx - r), fmt(cy), fmt(r), fmt(r), fmt(cx + r), fmt(cy), fmt(r), fmt(r), fmt(cx - r), fmt(cy))


def ellipse(cx, cy, rx, ry):
    return "M%s %s A%s %s 0 1 0 %s %s A%s %s 0 1 0 %s %s Z" % (
        fmt(cx - rx), fmt(cy), fmt(rx), fmt(ry), fmt(cx + rx), fmt(cy), fmt(rx), fmt(ry), fmt(cx - rx), fmt(cy))


def rect(x, y, w, h, rx, ry):
    if rx <= 0 and ry <= 0:
        return "M%s %sH%sV%sH%sZ" % (fmt(x), fmt(y), fmt(x + w), fmt(y + h), fmt(x))
    if rx <= 0:
        rx = ry
    if ry <= 0:
        ry = rx
    rx, ry = min(rx, w / 2), min(ry, h / 2)
    return ("M%s %s H%s A%s %s 0 0 1 %s %s V%s A%s %s 0 0 1 %s %s H%s A%s %s 0 0 1 %s %s V%s A%s %s 0 0 1 %s %s Z" % (
        fmt(x + rx), fmt(y), fmt(x + w - rx), fmt(rx), fmt(ry), fmt(x + w), fmt(y + ry),
        fmt(y + h - ry), fmt(rx), fmt(ry), fmt(x + w - rx), fmt(y + h),
        fmt(x + rx), fmt(rx), fmt(ry), fmt(x), fmt(y + h - ry),
        fmt(y + ry), fmt(rx), fmt(ry), fmt(x + rx), fmt(y)))


def points(s):
    nums = [float(v) for v in NUM.findall(s or "")]
    return list(zip(nums[0::2], nums[1::2]))


def element_path(el):
    tag = el.tag.split("}")[-1]
    if tag == "path":
        return clean_path(el.get("d"))
    if tag == "circle":
        return circle(f(el, "cx"), f(el, "cy"), f(el, "r"))
    if tag == "ellipse":
        return ellipse(f(el, "cx"), f(el, "cy"), f(el, "rx"), f(el, "ry"))
    if tag == "rect":
        return rect(f(el, "x"), f(el, "y"), f(el, "width"), f(el, "height"), f(el, "rx", -1), f(el, "ry", -1))
    if tag == "line":
        return "M%s %s L%s %s" % (fmt(f(el, "x1")), fmt(f(el, "y1")), fmt(f(el, "x2")), fmt(f(el, "y2")))
    if tag in ("polyline", "polygon"):
        pts = points(el.get("points"))
        d = "M" + " L".join("%s %s" % (fmt(x), fmt(y)) for x, y in pts)
        return d + (" Z" if tag == "polygon" else "")
    raise ValueError("unsupported element " + tag)


def convert(sprite, outdir):
    root = ET.parse(sprite).getroot()
    count = 0
    names = []
    for sym in root.iter():
        if sym.tag.split("}")[-1] != "symbol":
            continue
        sid = sym.get("id")
        sw = sym.get("stroke-width", "2")
        paths = []
        for el in sym.iter():
            tag = el.tag.split("}")[-1]
            if tag in ("symbol", "g", "title", "defs"):
                continue
            d = element_path(el)
            filled = el.get("fill") == "currentColor"
            stroke = el.get("stroke", sym.get("stroke", "currentColor")) != "none"
            attrs = ['android:pathData="%s"' % d]
            attrs.append('android:fillColor="%s"' % ("#FFFFFFFF" if filled else "#00000000"))
            if stroke:
                attrs += ['android:strokeColor="#FFFFFFFF"', 'android:strokeWidth="%s"' % el.get("stroke-width", sw),
                          'android:strokeLineCap="round"', 'android:strokeLineJoin="round"']
            paths.append("    <path\n        " + "\n        ".join(attrs) + " />")
        name = "ic_" + re.sub(r"[^a-z0-9_]", "_", sid.lower())
        xml = ('<?xml version="1.0" encoding="utf-8"?>\n'
               '<!-- Lucide icon "%s" (ISC License, https://lucide.dev) converted for Android -->\n'
               '<vector xmlns:android="http://schemas.android.com/apk/res/android"\n'
               '    android:width="24dp" android:height="24dp"\n'
               '    android:viewportWidth="24" android:viewportHeight="24"\n'
               '    android:autoMirrored="false">\n%s\n</vector>\n') % (sid, "\n".join(paths))
        (outdir / (name + ".xml")).write_text(xml, encoding="utf-8")
        names.append(name)
        count += 1
    return names


if __name__ == "__main__":
    sprite = Path(sys.argv[1])
    out = Path(sys.argv[2])
    out.mkdir(parents=True, exist_ok=True)
    names = convert(sprite, out)
    print("%d icons written to %s" % (len(names), out))
