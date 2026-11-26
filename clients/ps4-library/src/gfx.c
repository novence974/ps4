#include "gfx.h"

#include <math.h>

static int cx0, cy0, cx1 = 1 << 30, cy1 = 1 << 30;

void gfx_clip(Canvas *c, int x, int y, int w, int h) {
    if (w <= 0 || h <= 0) {
        cx0 = 0; cy0 = 0; cx1 = c->w; cy1 = c->h;
        return;
    }
    cx0 = x < 0 ? 0 : x;
    cy0 = y < 0 ? 0 : y;
    cx1 = x + w > c->w ? c->w : x + w;
    cy1 = y + h > c->h ? c->h : y + h;
}

uint32_t gfx_mix(uint32_t a, uint32_t b, int t) {
    int it = 255 - t;
    uint32_t A = ((a >> 24) * it + (b >> 24) * t) / 255;
    uint32_t R = (((a >> 16) & 255) * it + ((b >> 16) & 255) * t) / 255;
    uint32_t G = (((a >> 8) & 255) * it + ((b >> 8) & 255) * t) / 255;
    uint32_t B = ((a & 255) * it + (b & 255) * t) / 255;
    return (A << 24) | (R << 16) | (G << 8) | B;
}

static inline void put(Canvas *c, int x, int y, uint32_t argb, int cov /* 0..255 */) {
    if (x < cx0 || y < cy0 || x >= cx1 || y >= cy1 || x >= c->w || y >= c->h) return;
    uint32_t a = ((argb >> 24) * (uint32_t)cov) / 255;
    if (a == 0) return;
    uint32_t *d = &c->px[y * c->stride + x];
    if (a >= 255) { *d = 0xFF000000u | (argb & 0xFFFFFF); return; }
    uint32_t dv = *d, ia = 255 - a;
    uint32_t r = (((argb >> 16) & 255) * a + ((dv >> 16) & 255) * ia) / 255;
    uint32_t g = (((argb >> 8) & 255) * a + ((dv >> 8) & 255) * ia) / 255;
    uint32_t b = ((argb & 255) * a + (dv & 255) * ia) / 255;
    *d = 0xFF000000u | (r << 16) | (g << 8) | b;
}

void gfx_blend(Canvas *c, int x, int y, uint32_t argb) { put(c, x, y, argb, 255); }

void gfx_fill(Canvas *c, int x, int y, int w, int h, uint32_t argb) {
    int x0 = x < cx0 ? cx0 : x, y0 = y < cy0 ? cy0 : y;
    int x1 = x + w > cx1 ? cx1 : x + w, y1 = y + h > cy1 ? cy1 : y + h;
    if (x1 > c->w) x1 = c->w;
    if (y1 > c->h) y1 = c->h;
    if ((argb >> 24) == 255) {
        for (int j = y0; j < y1; j++) {
            uint32_t *row = &c->px[j * c->stride];
            for (int i = x0; i < x1; i++) row[i] = argb;
        }
        return;
    }
    for (int j = y0; j < y1; j++)
        for (int i = x0; i < x1; i++) put(c, i, j, argb, 255);
}

/* coverage (0..255) of pixel (px,py) inside a rounded rectangle, anti-aliased on the corners */
static int coverage(int px, int py, int x, int y, int w, int h, int r) {
    float fx = px + 0.5f, fy = py + 0.5f;
    float qx = fx < x + r ? x + r - fx : (fx > x + w - r ? fx - (x + w - r) : 0);
    float qy = fy < y + r ? y + r - fy : (fy > y + h - r ? fy - (y + h - r) : 0);
    if (qx <= 0 || qy <= 0) return 255;
    float d = sqrtf(qx * qx + qy * qy) - r;
    if (d <= -0.5f) return 255;
    if (d >= 0.5f) return 0;
    return (int)((0.5f - d) * 255);
}

void gfx_round(Canvas *c, int x, int y, int w, int h, int r, uint32_t argb) {
    if (r * 2 > w) r = w / 2;
    if (r * 2 > h) r = h / 2;
    for (int j = y; j < y + h; j++) {
        int corner = j < y + r || j >= y + h - r;
        if (!corner) {
            gfx_fill(c, x, j, w, 1, argb);
            continue;
        }
        for (int i = x; i < x + w; i++) {
            int cov = (i < x + r || i >= x + w - r) ? coverage(i, j, x, y, w, h, r) : 255;
            if (cov) put(c, i, j, argb, cov);
        }
    }
}

void gfx_round_stroke(Canvas *c, int x, int y, int w, int h, int r, int t, uint32_t argb) {
    for (int j = y; j < y + h; j++) {
        for (int i = x; i < x + w; i++) {
            int inner = (i >= x + t && i < x + w - t && j >= y + t && j < y + h - t);
            if (inner && j >= y + r && j < y + h - r && i >= x + r + t && i < x + w - r - t) {
                i = x + w - r - t - 1;
                continue;
            }
            int outer = coverage(i, j, x, y, w, h, r);
            int in = inner ? coverage(i, j, x + t, y + t, w - 2 * t, h - 2 * t, r > t ? r - t : 0) : 0;
            int cov = outer - in;
            if (cov > 0) put(c, i, j, argb, cov);
        }
    }
}

void gfx_round_gradient(Canvas *c, int x, int y, int w, int h, int r, uint32_t from, uint32_t mid, uint32_t to) {
    for (int i = 0; i < w; i++) {
        int t = w > 1 ? i * 510 / (w - 1) : 0;
        uint32_t col = t <= 255 ? gfx_mix(from, mid, t) : gfx_mix(mid, to, t - 255);
        for (int j = y; j < y + h; j++) {
            int cov = coverage(x + i, j, x, y, w, h, r);
            if (cov) put(c, x + i, j, col, cov);
        }
    }
}

void gfx_vgradient(Canvas *c, int x, int y, int w, int h, uint32_t top, uint32_t bottom) {
    for (int j = 0; j < h; j++) gfx_fill(c, x, y + j, w, 1, gfx_mix(top, bottom, h > 1 ? j * 255 / (h - 1) : 0));
}

void gfx_glow(Canvas *c, int cx, int cy, int radius, uint32_t argb) {
    for (int j = cy - radius; j < cy + radius; j++) {
        for (int i = cx - radius; i < cx + radius; i++) {
            float d = sqrtf((float)(i - cx) * (i - cx) + (float)(j - cy) * (j - cy)) / radius;
            if (d < 1.0f) put(c, i, j, argb, (int)((1.0f - d) * (1.0f - d) * 255));
        }
    }
}

void gfx_image(Canvas *c, const Image *img, int x, int y, int w, int h, int r, int opacity, int cover) {
    if (!img || !img->px || img->w <= 0 || img->h <= 0) return;
    /* source rectangle: crop to the box aspect when cover, otherwise the whole image */
    float sx0 = 0, sy0 = 0, sw = (float)img->w, sh = (float)img->h;
    if (cover) {
        float box = (float)w / h, src = (float)img->w / img->h;
        if (src > box) { sw = img->h * box; sx0 = (img->w - sw) / 2; }
        else { sh = img->w / box; sy0 = (img->h - sh) / 2; }
    }
    for (int j = 0; j < h; j++) {
        int ty = y + j;
        if (ty < cy0 || ty >= cy1) continue;
        float fy = sy0 + (j + 0.5f) * sh / h - 0.5f;
        int y0 = (int)fy;
        float wy = fy - y0;
        if (y0 < 0) { y0 = 0; wy = 0; }
        int y1 = y0 + 1 < img->h ? y0 + 1 : y0;
        for (int i = 0; i < w; i++) {
            int tx = x + i;
            if (tx < cx0 || tx >= cx1) continue;
            int cov = r > 0 ? coverage(tx, ty, x, y, w, h, r) : 255;
            if (!cov) continue;
            float fx = sx0 + (i + 0.5f) * sw / w - 0.5f;
            int x0 = (int)fx;
            float wx = fx - x0;
            if (x0 < 0) { x0 = 0; wx = 0; }
            int x1 = x0 + 1 < img->w ? x0 + 1 : x0;
            uint32_t p00 = img->px[y0 * img->w + x0], p10 = img->px[y0 * img->w + x1];
            uint32_t p01 = img->px[y1 * img->w + x0], p11 = img->px[y1 * img->w + x1];
            uint32_t top = gfx_mix(p00, p10, (int)(wx * 255)), bot = gfx_mix(p01, p11, (int)(wx * 255));
            uint32_t px = gfx_mix(top, bot, (int)(wy * 255));
            put(c, tx, ty, px, cov * opacity / 255);
        }
    }
}
