/* Software drawing into a 32-bit ARGB framebuffer (the PS4 VideoOut buffer or the desktop test buffer). */
#ifndef UPSL_GFX_H
#define UPSL_GFX_H

#include <stdint.h>

typedef struct {
    uint32_t *px;
    int w, h, stride; /* stride in pixels */
} Canvas;

typedef struct {
    uint32_t *px;     /* premultiplied? no: straight ARGB */
    int w, h;
} Image;

#define ARGB(a, r, g, b) (((uint32_t)(a) << 24) | ((uint32_t)(r) << 16) | ((uint32_t)(g) << 8) | (uint32_t)(b))

/* palette (same as the website) */
#define C_BG       0xFF070816u
#define C_BG2      0xFF12113Au
#define C_CARD     0xD9151A3Au
#define C_STROKE   0x29B0BAFFu
#define C_STROKE2  0x52B0BAFFu
#define C_INK      0xFFE8EBFFu
#define C_MIST     0xFF9AA3C7u
#define C_FAINT    0xFF6B7399u
#define C_ACCENT   0xFF8B7CFFu
#define C_ACCENT2  0xFF5AA9FFu
#define C_ACCENT3  0xFF5EE6F0u
#define C_OK       0xFF4ADE80u
#define C_WARN     0xFFFBBF24u
#define C_BAD      0xFFFB7185u

void gfx_clip(Canvas *c, int x, int y, int w, int h);  /* restrict drawing (w<=0 resets) */
void gfx_blend(Canvas *c, int x, int y, uint32_t argb);
void gfx_fill(Canvas *c, int x, int y, int w, int h, uint32_t argb);
void gfx_round(Canvas *c, int x, int y, int w, int h, int r, uint32_t argb);
void gfx_round_stroke(Canvas *c, int x, int y, int w, int h, int r, int t, uint32_t argb);
void gfx_round_gradient(Canvas *c, int x, int y, int w, int h, int r, uint32_t from, uint32_t mid, uint32_t to);
void gfx_vgradient(Canvas *c, int x, int y, int w, int h, uint32_t top, uint32_t bottom);
void gfx_glow(Canvas *c, int cx, int cy, int radius, uint32_t argb);
/* image scaled into the box (cover = crop to fill), rounded corners, overall opacity 0..255 */
void gfx_image(Canvas *c, const Image *img, int x, int y, int w, int h, int r, int opacity, int cover);
uint32_t gfx_mix(uint32_t a, uint32_t b, int t /* 0..255 */);

#endif
