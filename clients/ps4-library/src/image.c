/* Remote images (covers, banners, screenshots): JPEG/PNG decode with stb_image, small LRU cache keyed by URL. */
#include "image.h"

#include <stdlib.h>
#include <string.h>

#include "platform.h"

#define STB_IMAGE_IMPLEMENTATION
#define STBI_ONLY_JPEG
#define STBI_ONLY_PNG
#define STBI_NO_STDIO
#include "stb_image.h"

typedef struct {
    char url[512];
    Image img;
    int failed;
    uint64_t used;
} Slot;

#define SLOTS 64 /* about 35 MB of covers at most */
static Slot slots[SLOTS];
static uint64_t tick;

Image *image_decode(const unsigned char *data, int len, int max_w) {
    int w, h, n;
    unsigned char *rgba = stbi_load_from_memory(data, len, &w, &h, &n, 4);
    if (!rgba) return NULL;
    /* shrink big pictures (box filter) so a page of covers stays small in memory */
    int f = 1;
    while (max_w > 0 && w / (f * 2) >= max_w) f *= 2;
    int ow = w / f, oh = h / f;
    Image *img = (Image *)calloc(1, sizeof(Image));
    img->w = ow;
    img->h = oh;
    img->px = (uint32_t *)malloc((size_t)ow * oh * 4);
    for (int y = 0; y < oh; y++)
        for (int x = 0; x < ow; x++) {
            unsigned r = 0, g = 0, b = 0, a = 0;
            for (int yy = 0; yy < f; yy++)
                for (int xx = 0; xx < f; xx++) {
                    const unsigned char *p = rgba + ((size_t)(y * f + yy) * w + (x * f + xx)) * 4;
                    r += p[0]; g += p[1]; b += p[2]; a += p[3];
                }
            unsigned d = (unsigned)(f * f);
            img->px[y * ow + x] = ((a / d) << 24) | ((r / d) << 16) | ((g / d) << 8) | (b / d);
        }
    stbi_image_free(rgba);
    return img;
}

void image_free(Image *img) {
    if (!img) return;
    free(img->px);
    free(img);
}

const Image *image_cached(const char *url) {
    if (!url || !*url) return NULL;
    for (int i = 0; i < SLOTS; i++)
        if (slots[i].url[0] && !strcmp(slots[i].url, url)) {
            slots[i].used = ++tick;
            return slots[i].failed ? NULL : &slots[i].img;
        }
    return NULL;
}

int image_known(const char *url) {
    for (int i = 0; i < SLOTS; i++)
        if (slots[i].url[0] && !strcmp(slots[i].url, url)) return 1;
    return 0;
}

int image_fetch(const char *url, int max_w) {
    if (!url || !*url || image_known(url)) return 0;
    int victim = 0;
    for (int i = 0; i < SLOTS; i++) {
        if (!slots[i].url[0]) { victim = i; break; }
        if (slots[i].used < slots[victim].used) victim = i;
    }
    Slot *s = &slots[victim];
    free(s->img.px);
    memset(s, 0, sizeof(*s));
    strncpy(s->url, url, sizeof(s->url) - 1);
    s->used = ++tick;
    HttpResponse r;
    const char *headers[] = {"Accept: image/jpeg,image/png", NULL};
    plat_http("GET", url, headers, NULL, 0, &r);
    Image *img = r.status == 200 ? image_decode((const unsigned char *)r.body, (int)r.len, max_w) : NULL;
    http_free(&r);
    if (!img) { s->failed = 1; return 1; }
    s->img = *img;
    free(img);
    return 1;
}
