/*
 * Desktop test build (Linux): runs the same app code with a 1920x1080 memory framebuffer, a scripted controller
 * and a plain-socket HTTP client (http:// only), and saves screenshots. Used to test the app against a live server
 * where no PS4 is available. Not part of the PKG.
 *
 *   ./upsl-desktop <assets dir> <data dir> <shots dir> "wait:1500 R1 RIGHT CROSS shot:details CIRCLE ..."
 */
#include <errno.h>
#include <netdb.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

#include "app.h"
#include "platform.h"

#define STB_IMAGE_WRITE_IMPLEMENTATION
#include "stb_image_write.h"

static const char *assets = "assets", *data = "data";

uint64_t plat_ms(void) {
    struct timeval tv;
    gettimeofday(&tv, NULL);
    return (uint64_t)tv.tv_sec * 1000 + tv.tv_usec / 1000;
}
const char *plat_data_dir(void) { return data; }
const char *plat_asset_dir(void) { return assets; }
const char *plat_device_name(void) { return "PS4 (desktop test build)"; }

void http_free(HttpResponse *r) {
    free(r->body);
    r->body = NULL;
}

int plat_http(const char *method, const char *url, const char *const *headers, const char *body, size_t body_len, HttpResponse *out) {
    memset(out, 0, sizeof(*out));
    if (strncmp(url, "http://", 7)) {
        snprintf(out->error, sizeof out->error, "the desktop test build supports http:// only");
        return 0;
    }
    char host[256], port[8] = "80";
    const char *p = url + 7, *slash = strchr(p, '/');
    const char *path = slash ? slash : "/";
    size_t hl = slash ? (size_t)(slash - p) : strlen(p);
    snprintf(host, sizeof host, "%.*s", (int)hl, p);
    char *colon = strchr(host, ':');
    if (colon) { *colon = 0; snprintf(port, sizeof port, "%s", colon + 1); }
    struct addrinfo hints = {0}, *res;
    hints.ai_socktype = SOCK_STREAM;
    if (getaddrinfo(host, port, &hints, &res)) { snprintf(out->error, sizeof out->error, "cannot resolve %s", host); return 0; }
    int fd = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (fd < 0 || connect(fd, res->ai_addr, res->ai_addrlen)) {
        snprintf(out->error, sizeof out->error, "%s", strerror(errno));
        freeaddrinfo(res);
        if (fd >= 0) close(fd);
        return 0;
    }
    freeaddrinfo(res);
    char req[4096];
    int n = snprintf(req, sizeof req, "%s %s HTTP/1.1\r\nHost: %.*s\r\nUser-Agent: UPSL-PS4-Library/1.00 (desktop test)\r\nConnection: close\r\n", method, path, (int)hl, p);
    for (int i = 0; headers && headers[i]; i++) n += snprintf(req + n, sizeof req - (size_t)n, "%s\r\n", headers[i]);
    n += snprintf(req + n, sizeof req - (size_t)n, "Content-Length: %zu\r\n\r\n", body ? body_len : 0);
    send(fd, req, (size_t)n, 0);
    if (body && body_len) send(fd, body, body_len, 0);
    size_t cap = 1 << 16, len = 0;
    char *buf = malloc(cap);
    for (;;) {
        if (len + 65536 > cap) { cap *= 2; buf = realloc(buf, cap); }
        ssize_t r = recv(fd, buf + len, cap - len - 1, 0);
        if (r <= 0) break;
        len += (size_t)r;
    }
    close(fd);
    buf[len] = 0;
    char *sep = strstr(buf, "\r\n\r\n");
    if (!sep || sscanf(buf, "HTTP/%*s %d", &out->status) != 1) { free(buf); snprintf(out->error, sizeof out->error, "bad response"); return 0; }
    *sep = 0;
    int chunked = strstr(buf, "chunked") != NULL || strstr(buf, "Chunked") != NULL;
    char *b = sep + 4;
    size_t blen = len - (size_t)(b - buf);
    out->body = malloc(blen + 1);
    if (chunked) {
        size_t o = 0;
        char *q = b;
        for (;;) {
            unsigned long sz = strtoul(q, &q, 16);
            q = strstr(q, "\r\n");
            if (!q || sz == 0) break;
            q += 2;
            memcpy(out->body + o, q, sz);
            o += sz;
            q += sz + 2;
        }
        out->len = o;
    } else {
        memcpy(out->body, b, blen);
        out->len = blen;
    }
    out->body[out->len] = 0;
    free(buf);
    return out->status;
}

static unsigned button(const char *t) {
    static const struct { const char *n; unsigned b; } map[] = {
        {"UP", BTN_UP}, {"DOWN", BTN_DOWN}, {"LEFT", BTN_LEFT}, {"RIGHT", BTN_RIGHT}, {"CROSS", BTN_CROSS}, {"CIRCLE", BTN_CIRCLE},
        {"SQUARE", BTN_SQUARE}, {"TRIANGLE", BTN_TRIANGLE}, {"L1", BTN_L1}, {"R1", BTN_R1}, {"L2", BTN_L2}, {"R2", BTN_R2}, {"OPTIONS", BTN_OPTIONS}};
    for (size_t i = 0; i < sizeof map / sizeof map[0]; i++) if (!strcmp(map[i].n, t)) return map[i].b;
    return 0;
}

static void settle(Canvas *c) {
    for (int i = 0; i < 200; i++) {
        app_frame(c, 0);
        if (!app_idle()) break;
    }
    app_frame(c, 0);
}

int main(int argc, char **argv) {
    if (argc < 5) {
        fprintf(stderr, "usage: %s <assets> <data> <shots> \"script\"\n", argv[0]);
        return 2;
    }
    assets = argv[1];
    data = argv[2];
    mkdir(data, 0755);
    mkdir(argv[3], 0755);
    Canvas c = {malloc(1920 * 1080 * 4), 1920, 1080, 1920};
    if (!app_init(&c)) { fprintf(stderr, "init failed (fonts?)\n"); return 1; }
    settle(&c);
    char *script = strdup(argv[4]);
    int frames = 0;
    for (char *tok = strtok(script, " "); tok; tok = strtok(NULL, " ")) {
        if (!strncmp(tok, "shot:", 5)) {
            char path[512];
            snprintf(path, sizeof path, "%s/%s.png", argv[3], tok + 5);
            /* framebuffer is ARGB (memory order B,G,R,A); PNG wants R,G,B */
            unsigned char *rgb = malloc(1920 * 1080 * 3);
            for (int i = 0; i < 1920 * 1080; i++) {
                uint32_t px = c.px[i];
                rgb[i * 3] = (px >> 16) & 255; rgb[i * 3 + 1] = (px >> 8) & 255; rgb[i * 3 + 2] = px & 255;
            }
            stbi_write_png(path, 1920, 1080, 3, rgb, 1920 * 3);
            free(rgb);
            printf("shot %s\n", path);
        } else if (!strncmp(tok, "type:", 5)) {
            for (char *u = tok + 5; *u; u++) if (*u == '_') *u = ' ';
            app_debug_type(tok + 5);
            settle(&c);
        } else if (!strncmp(tok, "wait:", 5)) {
            uint64_t until = plat_ms() + (uint64_t)atoi(tok + 5);
            while (plat_ms() < until) { app_frame(&c, 0); app_idle(); usleep(20000); }
        } else {
            unsigned b = button(tok);
            if (!b) { fprintf(stderr, "unknown token %s\n", tok); continue; }
            uint64_t t0 = plat_ms();
            app_frame(&c, b);
            settle(&c);
            frames++;
            printf("%-9s %4llu ms\n", tok, (unsigned long long)(plat_ms() - t0));
        }
        if (app_should_quit()) { printf("app asked to quit\n"); break; }
    }
    free(script);
    return 0;
}
