#!/usr/bin/env python3
"""
"Night Shelf" — original ambient background loop for ULTIMATE PS LIBRARY.
Fully synthesised here (no samples, no third-party material), released with the project.

Layers: warm detuned pad (filtered, slow LFO), bell arpeggio with ping-pong echo, soft sub bass,
filtered "air" noise, all through a long synthetic reverb. The piece is rendered twice and the
second cycle is kept, so reverb tails wrap around and the loop is seamless.

Usage: python3 tools/generate_music.py <out_dir>   (needs numpy, scipy and ffmpeg)
"""
import subprocess, sys, pathlib, wave
import numpy as np
from scipy.signal import butter, sosfilt, fftconvolve

SR = 44100
BPM = 76
BEAT = 60 / BPM
BAR = 4 * BEAT
BARS = 16
LOOP = BARS * BAR
rng = np.random.default_rng(7)

def midi(n):
    return 440.0 * 2 ** ((n - 69) / 12)

# A-minor dreamscape: Am9 | Fmaj7(#11) | Cmaj9 | G6/9 — two bars each, twice
PROG = [
    [57, 60, 64, 67, 71],   # Am9
    [53, 57, 60, 64, 71],   # Fmaj7#11
    [48, 55, 59, 62, 64],   # Cmaj9
    [55, 59, 62, 64, 69],   # G6/9
]
BASS = [45, 41, 48, 43]

def env_adsr(n, a, r):
    e = np.ones(n)
    ai = min(n, max(1, int(a * SR))); ri = min(n, max(1, int(r * SR)))
    e[:ai] = np.linspace(0, 1, ai) ** 2
    e[-ri:] *= np.linspace(1, 0, ri) ** 2
    return e

def lowpass(x, cutoff, order=2):
    sos = butter(order, cutoff / (SR / 2), btype='low', output='sos')
    return sosfilt(sos, x)

def highpass(x, cutoff, order=2):
    sos = butter(order, cutoff / (SR / 2), btype='high', output='sos')
    return sosfilt(sos, x)

def render(total):
    n = int(total * SR)
    t = np.arange(n) / SR
    left = np.zeros(n); right = np.zeros(n)

    # ---------------- pad
    pad = np.zeros(n)
    for cycle in range(int(np.ceil(total / LOOP))):
        for ci, chord in enumerate(PROG * 2):
            start = cycle * LOOP + ci * 2 * BAR
            if start >= total:
                break
            dur = 2 * BAR + 1.2
            i0 = int(start * SR); i1 = min(n, int((start + dur) * SR))
            tt = np.arange(i1 - i0) / SR
            seg = np.zeros(i1 - i0)
            for note in chord:
                f = midi(note)
                for det in (-0.07, 0.0, 0.07):
                    ph = rng.uniform(0, 2 * np.pi)
                    ff = f * 2 ** (det / 12)
                    # soft "saw" from a few harmonics
                    for h, amp in ((1, 1.0), (2, 0.35), (3, 0.16), (4, 0.07)):
                        seg += amp * np.sin(2 * np.pi * ff * h * tt + ph * h)
            seg *= env_adsr(len(seg), 1.6, 1.4) / (len(chord) * 3 * 1.6)
            pad[i0:i1] += seg
    lfo = 0.5 + 0.5 * np.sin(2 * np.pi * t / (BAR * 4))
    pad = lowpass(pad, 1400) * (0.75 + 0.25 * lfo)
    left += pad * 0.55; right += pad * 0.55

    # ---------------- bell arpeggio (8th notes, chord tones up an octave)
    step = BEAT / 2
    arp_l = np.zeros(n); arp_r = np.zeros(n)
    pattern = [0, 2, 4, 3, 1, 4, 2, 3]
    k = 0
    for cycle in range(int(np.ceil(total / LOOP))):
        for ci, chord in enumerate(PROG * 2):
            for s in range(int(2 * BAR / step)):
                start = cycle * LOOP + ci * 2 * BAR + s * step
                if start >= total:
                    break
                if (s % 16) in (13, 15):   # breathe
                    continue
                note = chord[pattern[s % len(pattern)]] + 12
                f = midi(note)
                dur = 1.6
                i0 = int(start * SR); i1 = min(n, i0 + int(dur * SR))
                tt = np.arange(i1 - i0) / SR
                vel = 0.55 + 0.25 * np.sin(k * 0.7) + rng.uniform(-0.05, 0.05)
                tone = (np.sin(2 * np.pi * f * tt) + 0.28 * np.sin(2 * np.pi * f * 2.0 * tt) + 0.08 * np.sin(2 * np.pi * f * 3.01 * tt))
                tone *= np.exp(-tt * 3.2) * (1 - np.exp(-tt * 300)) * vel * 0.12
                pan = 0.5 + 0.35 * np.sin(k * 0.9)
                arp_l[i0:i1] += tone * (1 - pan); arp_r[i0:i1] += tone * pan
                k += 1
    # ping-pong echo (dotted eighth)
    d = int(BEAT * 0.75 * SR)
    for _ in range(4):
        echo_l = np.zeros(n); echo_r = np.zeros(n)
        echo_l[d:] = arp_r[:-d] * 0.42; echo_r[d:] = arp_l[:-d] * 0.42
        arp_l = arp_l + lowpass(echo_l, 3500) * 0.9; arp_r = arp_r + lowpass(echo_r, 3500) * 0.9
    left += arp_l; right += arp_r

    # ---------------- sub bass
    bass = np.zeros(n)
    for cycle in range(int(np.ceil(total / LOOP))):
        for ci, root in enumerate(BASS * 2):
            for b in (0, 1):
                start = cycle * LOOP + ci * 2 * BAR + b * BAR
                if start >= total:
                    break
                i0 = int(start * SR); i1 = min(n, i0 + int(BAR * 0.95 * SR))
                tt = np.arange(i1 - i0) / SR
                f = midi(root - 12)
                bass[i0:i1] += np.sin(2 * np.pi * f * tt) * env_adsr(i1 - i0, 0.08, 0.6) * 0.22
    left += bass; right += bass

    # ---------------- air (filtered noise, slow swell)
    noise = rng.standard_normal(n)
    air = highpass(lowpass(noise, 5200), 1800) * 0.012 * (0.6 + 0.4 * np.sin(2 * np.pi * t / (BAR * 2)))
    left += air; right += np.roll(air, 331)

    # ---------------- reverb (synthetic stereo IR)
    ir_len = int(3.2 * SR)
    it = np.arange(ir_len) / SR
    decay = np.exp(-it * 2.1)
    ir_l = lowpass(rng.standard_normal(ir_len) * decay, 6000); ir_r = lowpass(rng.standard_normal(ir_len) * decay, 6000)
    ir_l /= np.sqrt(np.sum(ir_l ** 2)); ir_r /= np.sqrt(np.sum(ir_r ** 2))
    wet_l = fftconvolve(left, ir_l)[:n]; wet_r = fftconvolve(right, ir_r)[:n]
    out_l = left * 0.72 + wet_l * 0.38
    out_r = right * 0.72 + wet_r * 0.38
    return np.stack([out_l, out_r], axis=1)

def main():
    out_dir = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '.')
    out_dir.mkdir(parents=True, exist_ok=True)
    full = render(LOOP * 2 + 0.01)
    a = int(LOOP * SR)
    loop = full[a:2 * a]
    loop = highpass(loop.T, 28).T
    peak = np.max(np.abs(loop))
    loop = loop / peak * 0.7          # about -3 dBFS
    fade = int(0.012 * SR)            # tiny edge fades: no click when the browser restarts the loop
    ramp = np.linspace(0, 1, fade)[:, None]
    loop[:fade] *= ramp; loop[-fade:] *= ramp[::-1]
    pcm = (np.clip(loop, -1, 1) * 32767).astype('<i2')
    wav_path = out_dir / 'night-shelf.wav'
    with wave.open(str(wav_path), 'wb') as w:
        w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(pcm.tobytes())
    meta = ['-metadata', 'title=Night Shelf', '-metadata', 'artist=ULTIMATE PS LIBRARY (original)', '-metadata', 'comment=Original synthesised track, licensed with the project']
    subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-i', str(wav_path), '-c:a', 'libmp3lame', '-b:a', '128k', *meta, str(out_dir / 'night-shelf.mp3')], check=True)
    subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-i', str(wav_path), '-c:a', 'libvorbis', '-q:a', '4', *meta, str(out_dir / 'night-shelf.ogg')], check=True)
    wav_path.unlink()
    print(f'loop {LOOP:.2f}s -> {out_dir}/night-shelf.mp3 / .ogg')

if __name__ == '__main__':
    main()
