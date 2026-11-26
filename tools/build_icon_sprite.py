#!/usr/bin/env python3
"""Builds public/assets/img/icons.svg from Lucide (ISC licence) icons.
Usage: python3 tools/build_icon_sprite.py <path-to-lucide-static/icons> <output.svg>"""
import re, sys, pathlib

MAP = {
 'home':'house','grid':'layout-grid','search':'search','heart':'heart','download':'download','upload':'upload','user':'user',
 'settings':'settings','bell':'bell','moon':'moon','sun':'sun','music':'music','volume':'volume-2','mute':'volume-x','play':'play',
 'pause':'pause','x':'x','check':'check','chev-left':'chevron-left','chev-right':'chevron-right','chev-down':'chevron-down','star':'star',
 'gamepad':'gamepad-2','disc':'disc-3','clock':'clock','sliders':'sliders-horizontal','sort':'arrow-up-down','trash':'trash-2','edit':'pencil',
 'plus':'plus','eye':'eye','eye-off':'eye-off','shield':'shield-check','database':'database','server':'server','drive':'hard-drive',
 'activity':'activity','refresh':'refresh-cw','link':'link','external':'external-link','image':'image','film':'film','file':'file',
 'folder':'folder','logout':'log-out','login':'log-in','menu':'menu','tag':'tag','globe':'globe','calendar':'calendar','lock':'lock',
 'key':'key-round','mail':'mail','info':'info','alert':'triangle-alert','copy':'copy','sparkles':'sparkles','history':'history','list':'list',
 'chart':'chart-column','users':'users','user-cog':'user-cog','cloud-upload':'cloud-upload','archive':'archive','file-json':'file-json',
 'file-sheet':'file-spreadsheet','layers':'layers','wifi-off':'wifi-off','tv':'tv','phone':'smartphone','monitor':'monitor','gauge':'gauge',
 'flame':'flame','rocket':'rocket','badge':'badge-check','languages':'languages','pin':'map-pin','building':'building-2','ok':'circle-check',
 'err':'circle-x','warn':'circle-alert','spinner':'loader-circle','undo':'rotate-ccw','save':'save','send':'send','megaphone':'megaphone',
 'package':'package','boxes':'boxes','scan':'scan-search','fingerprint':'fingerprint','hash':'hash','shield-alert':'shield-alert',
 'puzzle':'puzzle','library':'library','cpu':'cpu','hourglass':'hourglass','user-plus':'user-plus','ban':'ban','bell-ring':'bell-ring',
 'terminal':'terminal','help':'circle-help','headphones':'headphones','list-checks':'list-checks','compare':'git-compare-arrows',
 'files':'files','calendar-clock':'calendar-clock','mail-check':'mail-check','joystick':'joystick','wand':'wand-sparkles','cog':'cog',
 # category icons (categories.icon -> "cat-<icon>")
 'cat-bolt':'zap','cat-compass':'compass','cat-globe':'globe','cat-ghost':'ghost','cat-flag':'flag','cat-ball':'goal','cat-trophy':'trophy',
 'cat-fist':'swords','cat-home':'house','cat-star':'toy-brick','cat-sword':'sword','cat-chess':'castle',
}
src, out = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
parts = ['<svg xmlns="http://www.w3.org/2000/svg" style="display:none">',
         '<!-- Icons: Lucide (https://lucide.dev), ISC License — see assets/vendor/LICENSES.txt -->']
for sid, name in MAP.items():
    svg = (src / f'{name}.svg').read_text()
    inner = re.search(r'<svg[^>]*>(.*)</svg>', svg, re.S).group(1)
    inner = re.sub(r'<!--.*?-->', '', inner, flags=re.S)
    inner = re.sub(r'\s+', ' ', inner).replace('> <', '><').strip()
    parts.append(f'<symbol id="{sid}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{inner}</symbol>')
parts.append('</svg>')
out.write_text('\n'.join(parts) + '\n')
print(f'{len(MAP)} icons -> {out} ({out.stat().st_size} bytes)')
