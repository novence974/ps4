#include "text.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define STB_TRUETYPE_IMPLEMENTATION
#define STBTT_STATIC
#include "stb_truetype.h"
#include "arabic_forms.h"

typedef struct {
    stbtt_fontinfo info;
    unsigned char *data;
    int ascent, descent, gap;
} Font;

static Font fonts[2];

/* ------------------------------------------------------------------ glyph cache */

typedef struct GEntry {
    int font, glyph, size10;
    int w, h, xoff, yoff;
    unsigned char *bmp;
    struct GEntry *next;
} GEntry;

#define BUCKETS 4096
static GEntry *cache[BUCKETS];
static int cache_count;

static void cache_flush(void) {
    for (int i = 0; i < BUCKETS; i++) {
        GEntry *e = cache[i];
        while (e) { GEntry *n = e->next; free(e->bmp); free(e); e = n; }
        cache[i] = NULL;
    }
    cache_count = 0;
}

static GEntry *glyph_bitmap(int font, int glyph, float px) {
    int size10 = (int)(px * 10);
    unsigned hsh = ((unsigned)glyph * 2654435761u ^ (unsigned)size10 * 40503u ^ (unsigned)font) % BUCKETS;
    for (GEntry *e = cache[hsh]; e; e = e->next)
        if (e->font == font && e->glyph == glyph && e->size10 == size10) return e;
    if (cache_count > 6000) cache_flush();
    Font *f = &fonts[font];
    float scale = stbtt_ScaleForPixelHeight(&f->info, px);
    GEntry *e = (GEntry *)calloc(1, sizeof(GEntry));
    if (!e) return NULL;
    e->font = font;
    e->glyph = glyph;
    e->size10 = size10;
    e->bmp = stbtt_GetGlyphBitmapSubpixel(&f->info, scale, scale, 0, 0, glyph, &e->w, &e->h, &e->xoff, &e->yoff);
    e->next = cache[hsh];
    cache[hsh] = e;
    cache_count++;
    return e;
}

static unsigned char *read_file(const char *path) {
    FILE *f = fopen(path, "rb");
    if (!f) return NULL;
    fseek(f, 0, SEEK_END);
    long n = ftell(f);
    fseek(f, 0, SEEK_SET);
    unsigned char *buf = (unsigned char *)malloc((size_t)n);
    if (buf && fread(buf, 1, (size_t)n, f) != (size_t)n) { free(buf); buf = NULL; }
    fclose(f);
    return buf;
}

int text_init(const char *regular_ttf, const char *bold_ttf) {
    const char *paths[2] = {regular_ttf, bold_ttf};
    for (int i = 0; i < 2; i++) {
        fonts[i].data = read_file(paths[i]);
        if (!fonts[i].data || !stbtt_InitFont(&fonts[i].info, fonts[i].data, stbtt_GetFontOffsetForIndex(fonts[i].data, 0))) return 0;
        stbtt_GetFontVMetrics(&fonts[i].info, &fonts[i].ascent, &fonts[i].descent, &fonts[i].gap);
    }
    return 1;
}

/* ------------------------------------------------------------------ UTF-8 and classification */

static int utf8_next(const char *s, int len, int *i, unsigned *cp) {
    if (*i >= len) return 0;
    unsigned char c = (unsigned char)s[*i];
    int n = c < 0x80 ? 1 : (c >> 5) == 6 ? 2 : (c >> 4) == 14 ? 3 : (c >> 3) == 30 ? 4 : 1;
    unsigned v = n == 1 ? c : n == 2 ? (c & 0x1F) : n == 3 ? (c & 0x0F) : (c & 0x07);
    for (int k = 1; k < n && *i + k < len; k++) v = (v << 6) | ((unsigned char)s[*i + k] & 0x3F);
    *i += n;
    *cp = v;
    return 1;
}

enum { D_L = 0, D_R = 1, D_N = 2, D_EN = 3 };

static int classify(unsigned cp) {
    if ((cp >= 0x0660 && cp <= 0x0669) || (cp >= 0x06F0 && cp <= 0x06F9) || (cp >= '0' && cp <= '9')) return D_EN;
    if ((cp >= 0x0590 && cp <= 0x08FF) || (cp >= 0xFB1D && cp <= 0xFDFF) || (cp >= 0xFE70 && cp <= 0xFEFF)) return D_R;
    if ((cp >= 'A' && cp <= 'Z') || (cp >= 'a' && cp <= 'z') || (cp >= 0xC0 && cp <= 0x24F) || (cp >= 0x370 && cp < 0x590)
        || (cp >= 0x1E00 && cp <= 0x1FFF) || (cp >= 0x3040 && cp <= 0xD7AF)) return D_L;
    if (cp == 0x200E) return D_L;
    if (cp == 0x200F) return D_R;
    return D_N;
}

static const ArabicForm *arabic(unsigned cp) {
    int lo = 0, hi = (int)(sizeof(ARABIC_FORMS) / sizeof(ARABIC_FORMS[0])) - 1;
    while (lo <= hi) {
        int mid = (lo + hi) / 2;
        if (ARABIC_FORMS[mid].cp == cp) return &ARABIC_FORMS[mid];
        if (ARABIC_FORMS[mid].cp < cp) lo = mid + 1; else hi = mid - 1;
    }
    return NULL;
}

static int transparent(unsigned cp) { return (cp >= 0x064B && cp <= 0x065F) || cp == 0x0670; }

static unsigned mirror(unsigned cp) {
    switch (cp) {
        case '(': return ')'; case ')': return '(';
        case '[': return ']'; case ']': return '[';
        case '{': return '}'; case '}': return '{';
        case '<': return '>'; case '>': return '<';
        case 0xAB: return 0xBB; case 0xBB: return 0xAB;
        default: return cp;
    }
}

/* ------------------------------------------------------------------ shaping + bidi */

typedef struct {
    int glyph;
    unsigned cp;
    int dir;
    float adv;
} SG;

#define MAXG 1024

/* Shapes one line into visual order; returns the glyph count. */
static int shape(int font, float px, const char *s, int len, SG *out) {
    unsigned cps[MAXG];
    int n = 0, i = 0;
    unsigned cp;
    while (n < MAXG && utf8_next(s, len, &i, &cp)) {
        if (cp == '\n' || cp == '\r') continue;
        cps[n++] = cp;
    }
    Font *f = &fonts[font];
    float scale = stbtt_ScaleForPixelHeight(&f->info, px);
    int m = 0, para = -1;
    for (int k = 0; k < n; k++) {
        int d = classify(cps[k]);
        if (para < 0 && (d == D_L || d == D_R)) para = d;
    }
    if (para < 0) para = D_R; /* the UI is Arabic: neutral-only strings take the RTL direction */

    for (int k = 0; k < n; k++) {
        unsigned c = cps[k];
        const ArabicForm *af = arabic(c);
        int glyph = 0;
        if (af) {
            int p = k - 1, q = k + 1;
            while (p >= 0 && transparent(cps[p])) p--;
            while (q < n && transparent(cps[q])) q++;
            const ArabicForm *pf = p >= 0 ? arabic(cps[p]) : NULL;
            const ArabicForm *nf = q < n ? arabic(cps[q]) : NULL;
            int prev_joins = (pf && pf->join == 2) || (p >= 0 && cps[p] == 0x0640);
            int next_joins = (nf && nf->join >= 1) || (q < n && cps[q] == 0x0640);
            if (c == 0x0644 && nf && q == k + 1) {
                const LamAlef *la = NULL;
                for (size_t t = 0; t < sizeof(LAM_ALEF) / sizeof(LAM_ALEF[0]); t++)
                    if (LAM_ALEF[t].alef == cps[q]) la = &LAM_ALEF[t];
                if (la && (prev_joins ? la->fin : la->iso)) {
                    glyph = prev_joins ? la->fin : la->iso;
                    k = q; /* the alef is part of the ligature */
                }
            }
            if (!glyph) {
                if (af->join == 2) glyph = prev_joins && next_joins ? af->medi : prev_joins ? af->fina : next_joins ? af->init : af->isol;
                else if (af->join == 1) glyph = prev_joins ? af->fina : af->isol;
                if (!glyph) glyph = af->isol;
            }
        } else {
            glyph = stbtt_FindGlyphIndex(&f->info, (int)c);
            if (!glyph && c == 0x2026) glyph = stbtt_FindGlyphIndex(&f->info, '.');
        }
        int aw, lsb;
        stbtt_GetGlyphHMetrics(&f->info, glyph, &aw, &lsb);
        out[m].glyph = glyph;
        out[m].cp = c;
        out[m].dir = classify(c);
        out[m].adv = (c == 0x200E || c == 0x200F || c == 0x2066 || c == 0x2069) ? 0 : aw * scale;
        m++;
    }
    /* resolve: numbers act as left-to-right runs; neutrals take the direction of matching neighbours */
    int lev[MAXG];
    for (int k = 0; k < m; k++) if (out[k].dir == D_EN) out[k].dir = D_L;
    for (int k = 0; k < m; k++) {
        if (out[k].dir != D_N) continue;
        int a = k - 1, b = k + 1;
        while (a >= 0 && out[a].dir == D_N) a--;
        while (b < m && out[b].dir == D_N) b++;
        int da = a >= 0 ? out[a].dir : para, db = b < m ? out[b].dir : para;
        out[k].dir = (da == db) ? (da == D_N ? para : da) : para;
        if (da == D_N && db == D_N) out[k].dir = para;
    }
    for (int k = 0; k < m; k++) lev[k] = out[k].dir == D_R ? 1 : (para == D_R ? 2 : 0);
    /* mirror brackets in right-to-left runs */
    for (int k = 0; k < m; k++) {
        if (lev[k] == 1) {
            unsigned mc = mirror(out[k].cp);
            if (mc != out[k].cp) { out[k].cp = mc; out[k].glyph = stbtt_FindGlyphIndex(&f->info, (int)mc); }
        }
    }
    /* rule L2: reverse every run at or above each level, from the highest level down */
    for (int L = 2; L >= 1; L--) {
        int k = 0;
        while (k < m) {
            if (lev[k] < L) { k++; continue; }
            int e = k;
            while (e < m && lev[e] >= L) e++;
            for (int a = k, b = e - 1; a < b; a++, b--) {
                SG t = out[a]; out[a] = out[b]; out[b] = t;
                int tl = lev[a]; lev[a] = lev[b]; lev[b] = tl;
            }
            k = e;
        }
    }
    return m;
}

int text_line_height(float px) { return (int)(px * 1.45f + 0.5f); }

int text_width(int font, float px, const char *utf8, int len) {
    static SG g[MAXG];
    if (len < 0) len = (int)strlen(utf8);
    int n = shape(font, px, utf8, len, g);
    float w = 0;
    for (int i = 0; i < n; i++) w += g[i].adv;
    return (int)(w + 0.5f);
}

void text_draw(Canvas *c, int font, float px, const char *utf8, int len, int x, int y, int align, uint32_t color) {
    static SG g[MAXG];
    if (len < 0) len = (int)strlen(utf8);
    int n = shape(font, px, utf8, len, g);
    float w = 0;
    for (int i = 0; i < n; i++) w += g[i].adv;
    float pen = align == ALIGN_LEFT ? (float)x : align == ALIGN_CENTER ? x - w / 2 : x - w;
    Font *f = &fonts[font];
    float scale = stbtt_ScaleForPixelHeight(&f->info, px);
    int lh = text_line_height(px);
    int baseline = y + (int)((lh - (f->ascent - f->descent) * scale) / 2 + f->ascent * scale);
    for (int i = 0; i < n; i++) {
        if (g[i].glyph) {
            GEntry *e = glyph_bitmap(font, g[i].glyph, px);
            if (e && e->bmp) {
                int gx = (int)(pen + 0.5f) + e->xoff, gy = baseline + e->yoff;
                for (int yy = 0; yy < e->h; yy++)
                    for (int xx = 0; xx < e->w; xx++) {
                        unsigned char a = e->bmp[yy * e->w + xx];
                        if (a) gfx_blend(c, gx + xx, gy + yy, (((color >> 24) * a / 255) << 24) | (color & 0xFFFFFF));
                    }
            }
        }
        pen += g[i].adv;
    }
}

void text_fit(Canvas *c, int font, float px, const char *utf8, int x, int y, int max_w, int align, uint32_t color) {
    int len = (int)strlen(utf8);
    if (text_width(font, px, utf8, len) <= max_w) {
        text_draw(c, font, px, utf8, len, x, y, align, color);
        return;
    }
    char buf[1024];
    while (len > 0) {
        len--;
        while (len > 0 && ((unsigned char)utf8[len] & 0xC0) == 0x80) len--;
        snprintf(buf, sizeof buf, "%.*s…", len, utf8);
        if (text_width(font, px, buf, -1) <= max_w) break;
    }
    text_draw(c, font, px, buf, -1, x, y, align, color);
}

int text_box(Canvas *c, int font, float px, const char *utf8, int x, int y, int w, int align, uint32_t color, int max_lines) {
    int lh = text_line_height(px), lines = 0, total = (int)strlen(utf8), start = 0;
    while (start < total) {
        /* take words while they fit */
        int end = start, last_fit = -1;
        while (end <= total) {
            int e = end;
            while (e < total && utf8[e] != ' ' && utf8[e] != '\n') e++;
            if (text_width(font, px, utf8 + start, e - start) > w && last_fit >= 0) break;
            last_fit = e;
            if (e >= total || utf8[e] == '\n') { end = e; break; }
            end = e + 1;
        }
        int stop = last_fit < 0 ? total : last_fit;
        if (max_lines && lines == max_lines - 1 && stop < total) {
            char buf[2048];
            snprintf(buf, sizeof buf, "%.*s", total - start > 2000 ? 2000 : total - start, utf8 + start);
            if (c) text_fit(c, font, px, buf, align == ALIGN_LEFT ? x : align == ALIGN_CENTER ? x + w / 2 : x + w, y + lines * lh, w, align, color);
            lines++;
            break;
        }
        if (c) text_draw(c, font, px, utf8 + start, stop - start, align == ALIGN_LEFT ? x : align == ALIGN_CENTER ? x + w / 2 : x + w, y + lines * lh, align, color);
        lines++;
        start = stop;
        while (start < total && (utf8[start] == ' ' || utf8[start] == '\n')) start++;
    }
    return lines * lh;
}
