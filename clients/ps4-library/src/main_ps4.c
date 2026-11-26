/*
 * PS4 platform layer (OpenOrbis toolchain): VideoOut framebuffer, DualShock 4 through libScePad,
 * HTTP/HTTPS through libSceHttp (system certificate store: certificates are verified, never skipped).
 */
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include <orbis/Http.h>
#include <orbis/Net.h>
#include <orbis/Pad.h>
#include <orbis/Ssl.h>
#include <orbis/Sysmodule.h>
#include <orbis/SystemService.h>
#include <orbis/UserService.h>
#include <orbis/VideoOut.h>
#include <orbis/libkernel.h>

#include "app.h"
#include "platform.h"

#define FB_W 1920
#define FB_H 1080
#define HEADER_OVERWRITE 0 /* SCE_HTTP_HEADER_OVERWRITE */
#define USER_AGENT "UPSL-PS4-Library/" APP_VERSION " (PlayStation 4)"

static int video = -1, pad = -1, http_ctx = -1, http_tpl = -1;
static OrbisKernelEqueue flip_queue;
static uint32_t *frames[2];
static int current;
static int64_t flip_arg;

uint64_t plat_ms(void) { return sceKernelGetProcessTime() / 1000; }
const char *plat_data_dir(void) { return "/data/upsl-library"; }
const char *plat_asset_dir(void) { return "/app0/assets"; }
const char *plat_device_name(void) { return "PlayStation 4"; }

/* ------------------------------------------------------------------ HTTP */

static int http_setup(void) {
    if (sceSysmoduleLoadModuleInternal(ORBIS_SYSMODULE_INTERNAL_NET) < 0) return 0;
    if (sceSysmoduleLoadModuleInternal(ORBIS_SYSMODULE_INTERNAL_SSL) < 0) return 0;
    if (sceSysmoduleLoadModuleInternal(ORBIS_SYSMODULE_INTERNAL_HTTP) < 0) return 0;
    sceNetInit();
    int net = sceNetPoolCreate("upsl-net", 64 * 1024, 0);
    if (net < 0) return 0;
    int ssl = sceSslInit(512 * 1024);
    if (ssl < 0) return 0;
    http_ctx = sceHttpInit(net, ssl, 512 * 1024);
    if (http_ctx < 0) return 0;
    http_tpl = sceHttpCreateTemplate(http_ctx, USER_AGENT, ORBIS_HTTP_VERSION_1_1, 1);
    if (http_tpl < 0) return 0;
    sceHttpSetConnectTimeOut(http_tpl, 10 * 1000 * 1000);
    sceHttpSetResolveTimeOut(http_tpl, 10 * 1000 * 1000);
    sceHttpSetSendTimeOut(http_tpl, 30 * 1000 * 1000);
    return 1;
}

void http_free(HttpResponse *r) {
    free(r->body);
    r->body = NULL;
}

int plat_http(const char *method, const char *url, const char *const *headers, const char *body, size_t body_len, HttpResponse *out) {
    memset(out, 0, sizeof(*out));
    if (http_tpl < 0) {
        snprintf(out->error, sizeof out->error, "network library not available");
        return 0;
    }
    int m = !strcmp(method, "POST") ? ORBIS_METHOD_POST : !strcmp(method, "PUT") ? ORBIS_METHOD_PUT : !strcmp(method, "DELETE") ? ORBIS_METHOD_DELETE : ORBIS_METHOD_GET;
    int conn = sceHttpCreateConnectionWithURL(http_tpl, url, true);
    if (conn < 0) {
        snprintf(out->error, sizeof out->error, "connection error 0x%08X", (unsigned)conn);
        return 0;
    }
    int req = sceHttpCreateRequestWithURL(conn, m, url, body ? body_len : 0);
    if (req < 0) {
        snprintf(out->error, sizeof out->error, "request error 0x%08X", (unsigned)req);
        sceHttpDeleteConnection(conn);
        return 0;
    }
    for (int i = 0; headers && headers[i]; i++) {
        char name[128];
        const char *colon = strchr(headers[i], ':');
        if (!colon) continue;
        size_t n = (size_t)(colon - headers[i]);
        if (n >= sizeof name) n = sizeof name - 1;
        memcpy(name, headers[i], n);
        name[n] = 0;
        const char *value = colon + 1;
        while (*value == ' ') value++;
        sceHttpAddRequestHeader(req, name, value, HEADER_OVERWRITE);
    }
    int rc = sceHttpSendRequest(req, body, body ? (uint32_t)body_len : 0);
    if (rc < 0) {
        /* includes certificate verification failures: HTTPS needs a certificate the console trusts */
        snprintf(out->error, sizeof out->error, "send error 0x%08X", (unsigned)rc);
    } else {
        int32_t status = 0;
        sceHttpGetStatusCode(req, &status);
        size_t cap = 64 * 1024, len = 0;
        char *buf = (char *)malloc(cap);
        for (;;) {
            if (len + 32 * 1024 + 1 > cap) {
                cap *= 2;
                char *nb = (char *)realloc(buf, cap);
                if (!nb) break;
                buf = nb;
            }
            int r = sceHttpReadData(req, buf + len, 32 * 1024);
            if (r <= 0) break;
            len += (size_t)r;
            if (len > 32u * 1024 * 1024) break; /* never buffer huge bodies */
        }
        buf[len] = 0;
        out->status = status;
        out->body = buf;
        out->len = len;
    }
    sceHttpDeleteRequest(req);
    sceHttpDeleteConnection(conn);
    return out->status;
}

/* ------------------------------------------------------------------ video */

static int video_setup(void) {
    video = sceVideoOutOpen(ORBIS_VIDEO_USER_MAIN, ORBIS_VIDEO_OUT_BUS_MAIN, 0, 0);
    if (video < 0) return 0;
    if (sceKernelCreateEqueue(&flip_queue, "upsl-flip") < 0) return 0;
    sceVideoOutAddFlipEvent(flip_queue, video, 0);
    size_t fb_size = (size_t)FB_W * FB_H * 4, align = 0x200000;
    size_t total = (2 * fb_size + align - 1) / align * align;
    off_t off = 0;
    void *mem = NULL;
    if (sceKernelAllocateDirectMemory(0, (off_t)sceKernelGetDirectMemorySize(), total, align, 3 /* write-combined GARLIC */, &off) < 0) return 0;
    if (sceKernelMapDirectMemory(&mem, total, 0x33 /* CPU+GPU read/write */, 0, off, align) < 0) return 0;
    frames[0] = (uint32_t *)mem;
    frames[1] = (uint32_t *)((char *)mem + fb_size);
    OrbisVideoOutBufferAttribute attr;
    sceVideoOutSetBufferAttribute(&attr, 0x80000000 /* A8R8G8B8 sRGB */, 1 /* linear */, 0, FB_W, FB_H, FB_W);
    if (sceVideoOutRegisterBuffers(video, 0, (void *const *)frames, 2, &attr) != 0) return 0;
    sceVideoOutSetFlipRate(video, 0);
    return 1;
}

static void wait_flip(int64_t arg) {
    for (;;) {
        OrbisVideoOutFlipStatus st;
        sceVideoOutGetFlipStatus(video, &st);
        if (st.flipArg >= arg) return;
        OrbisKernelEvent ev;
        int count;
        if (sceKernelWaitEqueue(flip_queue, &ev, 1, &count, 0) != 0) return;
    }
}

/* ------------------------------------------------------------------ input */

static int pad_setup(void) {
    if (scePadInit() != 0) return 0;
    OrbisUserServiceInitializeParams params;
    memset(&params, 0, sizeof(params));
    params.priority = 700; /* lowest FIFO priority, as in the OpenOrbis samples */
    sceUserServiceInitialize(&params);
    int32_t user = 0;
    sceUserServiceGetInitialUser(&user);
    pad = scePadOpen(user, 0, 0, NULL);
    return pad >= 0;
}

static unsigned map_buttons(uint32_t b, int lx, int ly) {
    unsigned m = 0;
    if ((b & ORBIS_PAD_BUTTON_UP) || ly < 40) m |= BTN_UP;
    if ((b & ORBIS_PAD_BUTTON_DOWN) || ly > 215) m |= BTN_DOWN;
    if ((b & ORBIS_PAD_BUTTON_LEFT) || lx < 40) m |= BTN_LEFT;
    if ((b & ORBIS_PAD_BUTTON_RIGHT) || lx > 215) m |= BTN_RIGHT;
    if (b & ORBIS_PAD_BUTTON_CROSS) m |= BTN_CROSS;
    if (b & ORBIS_PAD_BUTTON_CIRCLE) m |= BTN_CIRCLE;
    if (b & ORBIS_PAD_BUTTON_SQUARE) m |= BTN_SQUARE;
    if (b & ORBIS_PAD_BUTTON_TRIANGLE) m |= BTN_TRIANGLE;
    if (b & ORBIS_PAD_BUTTON_L1) m |= BTN_L1;
    if (b & ORBIS_PAD_BUTTON_R1) m |= BTN_R1;
    if (b & ORBIS_PAD_BUTTON_L2) m |= BTN_L2;
    if (b & ORBIS_PAD_BUTTON_R2) m |= BTN_R2;
    if (b & ORBIS_PAD_BUTTON_OPTIONS) m |= BTN_OPTIONS;
    return m;
}

/* ------------------------------------------------------------------ main loop */

int main(void) {
    sceSystemServiceHideSplashScreen();
    sceKernelMkdir(plat_data_dir(), 0777);
    int net_ok = http_setup();
    if (!video_setup() || !pad_setup()) return 1;
    Canvas canvas = {frames[0], FB_W, FB_H, FB_W};
    if (!app_init(&canvas)) return 1;
    (void)net_ok;
    unsigned prev = 0;
    uint64_t held_since = 0, last_repeat = 0;
    for (;;) {
        OrbisPadData pd;
        unsigned now = 0;
        if (scePadReadState(pad, &pd) == 0 && pd.connected) now = map_buttons(pd.buttons, pd.leftStick.x, pd.leftStick.y);
        unsigned pressed = now & ~prev;
        /* auto-repeat for the directions while held */
        uint64_t t = plat_ms();
        unsigned dirs = now & (BTN_UP | BTN_DOWN | BTN_LEFT | BTN_RIGHT);
        if (pressed & dirs) held_since = t;
        if (dirs && (dirs & prev) == dirs && t - held_since > 420 && t - last_repeat > 110) {
            pressed |= dirs;
            last_repeat = t;
        }
        prev = now;
        canvas.px = frames[current];
        int changed = app_frame(&canvas, pressed);
        if (!changed && !pressed && app_idle()) changed = app_frame(&canvas, 0);
        if (changed) {
            sceVideoOutSubmitFlip(video, current, ORBIS_VIDEO_OUT_FLIP_VSYNC, ++flip_arg);
            wait_flip(flip_arg);
            current ^= 1;
        } else {
            sceKernelUsleep(16000);
        }
        if (app_should_quit()) break;
    }
    return 0;
}
