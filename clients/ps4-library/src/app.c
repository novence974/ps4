/*
 * ULTIMATE PS LIBRARY for PS4 — the app itself (platform independent).
 * Browse the library of the site with the controller: platforms, games, search, details, news,
 * favourites and notifications of a signed-in account. It does not download or install games.
 */
#include "app.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "api.h"
#include "image.h"
#include "platform.h"
#include "text.h"

#define W 1920
#define H 1080
#define MX 96
#define TOP 210
#define COLS 6
#define TILE_W 224
#define TILE_H 336
#define GAP_X 40
#define ROW_H (TILE_H + 120)
#define VISIBLE_ROWS 2

typedef enum { TAB_LIBRARY, TAB_SEARCH, TAB_NEWS, TAB_FAVORITES, TAB_NOTIFS, TAB_SETTINGS, TAB_COUNT } Tab;
static const char *TAB_NAMES[TAB_COUNT] = {"المكتبة", "بحث", "الأخبار", "المفضلة", "الإشعارات", "الإعدادات"};
static const char *SORT_KEYS[] = {"newest", "downloads", "rating", "title"};
static const char *SORT_NAMES[] = {"الأحدث", "الأكثر تنزيلاً", "الأعلى تقييماً", "الاسم"};

typedef enum { KB_NONE, KB_SEARCH, KB_SERVER, KB_USER, KB_PASS } KbTarget;

typedef struct {
    JDoc *pages[64];
    int npages;
    JVal *items[1500];
    int count, total, page, pages_total;
    int focus, top_row;
    int loaded;
    char error[300];
} Grid;

static struct {
    Tab tab;
    int details;              /* details screen open */
    Grid *details_from;
    JDoc *game_doc;
    JVal *game;
    int detail_scroll;
    int login;                /* sign-in screen open */
    int login_focus;
    char login_user[128], login_pass[128];
    KbTarget kb;              /* on-screen keyboard target */
    char kb_text[300];
    int kb_layout, kb_row, kb_col;
    JDoc *plat_doc;
    JVal *platforms;
    int platform, sort;
    Grid lib, search, favs;
    char query[300];
    JDoc *news_doc;
    int news_focus;
    char news_error[300];
    JDoc *notif_doc;
    int notif_focus;
    char notif_error[300];
    int settings_focus;
    char server[400], token[300], user_name[128];
    char toast[300];
    uint32_t toast_color;
    uint64_t toast_until;
    void (*pending)(void);
    char loading[160];
    int dirty, quit;
    Image bg;
    char conn_error[300];
} S;

/* ------------------------------------------------------------------ settings file */

static void settings_path(char *out, size_t n) { snprintf(out, n, "%s/settings.json", plat_data_dir()); }

static void save_settings(void) {
    char p[512], a[900], b[700], c[300];
    settings_path(p, sizeof p);
    FILE *f = fopen(p, "wb");
    if (!f) return;
    json_escape(S.server, a, sizeof a);
    json_escape(S.token, b, sizeof b);
    json_escape(S.user_name, c, sizeof c);
    fprintf(f, "{\"server\":\"%s\",\"token\":\"%s\",\"user\":\"%s\"}\n", a, b, c);
    fclose(f);
}

static void load_settings(void) {
    char p[512];
    settings_path(p, sizeof p);
    FILE *f = fopen(p, "rb");
    if (f) {
        char buf[4096];
        size_t n = fread(buf, 1, sizeof buf - 1, f);
        fclose(f);
        buf[n] = 0;
        JDoc *d = json_parse(buf, n);
        JVal *r = json_root(d);
        snprintf(S.server, sizeof S.server, "%s", jstr(r, "server", ""));
        snprintf(S.token, sizeof S.token, "%s", jstr(r, "token", ""));
        snprintf(S.user_name, sizeof S.user_name, "%s", jstr(r, "user", ""));
        json_free(d);
    }
    if (!S.server[0]) {
        /* default address baked into the package at build time (assets/server.txt) */
        snprintf(p, sizeof p, "%s/server.txt", plat_asset_dir());
        f = fopen(p, "rb");
        if (f) {
            size_t n = fread(S.server, 1, sizeof S.server - 1, f);
            fclose(f);
            S.server[n] = 0;
            while (n > 0 && (S.server[n - 1] == '\n' || S.server[n - 1] == '\r' || S.server[n - 1] == ' ')) S.server[--n] = 0;
        }
    }
    api_set_server(S.server);
    api_set_token(S.token);
}

/* ------------------------------------------------------------------ helpers */

static void toast(const char *msg, uint32_t color) {
    snprintf(S.toast, sizeof S.toast, "%s", msg);
    S.toast_color = color;
    S.toast_until = plat_ms() + 3500;
    S.dirty = 1;
}

static void later(void (*fn)(void), const char *msg) {
    S.pending = fn;
    snprintf(S.loading, sizeof S.loading, "%s", msg);
    S.dirty = 1;
}

static void grid_clear(Grid *g) {
    for (int i = 0; i < g->npages; i++) json_free(g->pages[i]);
    memset(g, 0, sizeof(*g));
}

static const char *cover_url(const JVal *game) {
    JVal *cv = jget(game, "cover");
    const char *u = jstr(cv, "sm_jpg", NULL);
    return u ? u : jstr(cv, "md_jpg", NULL);
}

static const char *region_name(const char *r) {
    if (!r) return NULL;
    if (!strcmp(r, "GLOBAL")) return "عالمي";
    if (!strcmp(r, "EUR")) return "أوروبا";
    if (!strcmp(r, "USA")) return "أمريكا";
    if (!strcmp(r, "JPN")) return "اليابان";
    if (!strcmp(r, "ASIA")) return "آسيا";
    if (!strcmp(r, "MENA")) return "الشرق الأوسط";
    return r;
}

static const char *access_name(const char *a) {
    if (!a) return "";
    if (!strcmp(a, "public")) return "للجميع";
    if (!strcmp(a, "registered")) return "للحسابات المسجلة";
    if (!strcmp(a, "verified")) return "للحسابات المؤكدة";
    if (!strcmp(a, "moderator")) return "للمشرفين";
    if (!strcmp(a, "admin")) return "للمدراء";
    return a;
}

/* ------------------------------------------------------------------ data loading (runs from app_idle, after the spinner is shown) */

static int api_ok(ApiResult *r, char *err, size_t n) {
    if (r->ok) return 1;
    snprintf(err, n, "%s", r->error);
    if (r->status == 401 && S.token[0]) {
        S.token[0] = 0;
        api_set_token("");
        save_settings();
    }
    return 0;
}

static void grid_append(Grid *g, const char *path) {
    ApiResult r;
    api_call("GET", path, NULL, &r);
    if (!api_ok(&r, g->error, sizeof g->error)) {
        if (r.status == 0) snprintf(S.conn_error, sizeof S.conn_error, "%s", r.error);
        api_free(&r);
        g->loaded = 1;
        return;
    }
    S.conn_error[0] = 0;
    g->error[0] = 0;
    if (g->npages < 64) {
        g->pages[g->npages++] = r.doc;
        r.doc = NULL;
        JVal *data = jget(json_root(g->pages[g->npages - 1]), "data");
        for (JVal *it = data ? data->child : NULL; it && g->count < 1500; it = it->next) g->items[g->count++] = it;
        JVal *meta = jget(json_root(g->pages[g->npages - 1]), "meta");
        g->total = (int)jnum(meta, "total", g->count);
        g->page = (int)jnum(meta, "page", g->page + 1);
        g->pages_total = (int)jnum(meta, "pages", 1);
    }
    g->loaded = 1;
    api_free(&r);
}

static void grid_path(Grid *g, int page, char *out, size_t n) {
    if (g == &S.search) {
        char q[600];
        url_encode(S.query, q, sizeof q);
        snprintf(out, n, "/search?q=%s&page=%d&per_page=24", q, page);
    } else if (g == &S.favs) {
        snprintf(out, n, "/user/favorites?page=%d&per_page=24", page);
    } else {
        const char *slug = S.platform > 0 ? jstr(jat(S.platforms, S.platform - 1), "slug", "") : "";
        snprintf(out, n, "/games?page=%d&per_page=24&sort=%s%s%s", page, SORT_KEYS[S.sort], slug[0] ? "&platform=" : "", slug);
    }
}

static Grid *current_grid(void) {
    return S.tab == TAB_SEARCH ? &S.search : S.tab == TAB_FAVORITES ? &S.favs : &S.lib;
}

static void do_load_first(void) {
    Grid *g = current_grid();
    grid_clear(g);
    char p[800];
    grid_path(g, 1, p, sizeof p);
    grid_append(g, p);
}

static void do_load_more(void) {
    Grid *g = current_grid();
    char p[800];
    grid_path(g, g->page + 1, p, sizeof p);
    grid_append(g, p);
}

static void do_platforms(void) {
    ApiResult r;
    api_call("GET", "/platforms", NULL, &r);
    if (r.ok) {
        json_free(S.plat_doc);
        S.plat_doc = r.doc;
        r.doc = NULL;
        S.platforms = jget(json_root(S.plat_doc), "data");
        S.conn_error[0] = 0;
    } else {
        snprintf(S.conn_error, sizeof S.conn_error, "%s", r.error);
    }
    api_free(&r);
    do_load_first();
}

static int64_t details_id;

static void do_details(void) {
    char p[64];
    snprintf(p, sizeof p, "/games/%lld", (long long)details_id);
    ApiResult r;
    api_call("GET", p, NULL, &r);
    if (!api_ok(&r, S.conn_error, sizeof S.conn_error)) {
        toast(r.error, C_BAD);
        api_free(&r);
        return;
    }
    json_free(S.game_doc);
    S.game_doc = r.doc;
    r.doc = NULL;
    S.game = jget(json_root(S.game_doc), "data");
    S.details = 1;
    S.detail_scroll = 0;
    api_free(&r);
    /* banner and cover for the page */
    image_fetch(jstr(jget(S.game, "banner"), "sm_jpg", NULL), 960);
    image_fetch(cover_url(S.game), 300);
}

static void do_news(void) {
    ApiResult r;
    api_call("GET", "/updates?per_page=30", NULL, &r);
    json_free(S.news_doc);
    S.news_doc = NULL;
    if (api_ok(&r, S.news_error, sizeof S.news_error)) {
        S.news_doc = r.doc;
        r.doc = NULL;
        S.news_error[0] = 0;
    }
    S.news_focus = 0;
    api_free(&r);
}

static void do_notifs(void) {
    ApiResult r;
    json_free(S.notif_doc);
    S.notif_doc = NULL;
    S.notif_focus = 0;
    if (!S.token[0]) return;
    api_call("GET", "/user/notifications?per_page=30", NULL, &r);
    if (api_ok(&r, S.notif_error, sizeof S.notif_error)) {
        S.notif_doc = r.doc;
        r.doc = NULL;
        S.notif_error[0] = 0;
        ApiResult m;
        api_call("POST", "/user/notifications/read", "{}", &m);
        api_free(&m);
    }
    api_free(&r);
}

static void do_toggle_favorite(void) {
    if (!S.game) return;
    int id = (int)jnum(S.game, "id", 0), fav = jbool(S.game, "is_favorite");
    char p[64], body[64];
    ApiResult r;
    if (fav) {
        snprintf(p, sizeof p, "/user/favorites/%d", id);
        api_call("DELETE", p, NULL, &r);
    } else {
        snprintf(body, sizeof body, "{\"game_id\":%d}", id);
        api_call("POST", "/user/favorites", body, &r);
    }
    if (r.ok) {
        JVal *v = jget(S.game, "is_favorite");
        if (v) { v->t = J_BOOL; v->b = !fav; }
        toast(fav ? "أُزيلت من المفضلة" : "أُضيفت إلى المفضلة", C_OK);
        S.favs.loaded = 0;
    } else {
        toast(r.error, C_BAD);
    }
    api_free(&r);
}

static void do_login(void) {
    char u[300], pw[300], dev[200], body[1200];
    json_escape(S.login_user, u, sizeof u);
    json_escape(S.login_pass, pw, sizeof pw);
    json_escape(plat_device_name(), dev, sizeof dev);
    snprintf(body, sizeof body, "{\"login\":\"%s\",\"password\":\"%s\",\"device_name\":\"%s\"}", u, pw, dev);
    ApiResult r;
    api_call("POST", "/auth/login", body, &r);
    if (r.ok) {
        snprintf(S.token, sizeof S.token, "%s", jstr(r.data, "token", ""));
        snprintf(S.user_name, sizeof S.user_name, "%s", jstr(jget(r.data, "user"), "display_name", S.login_user));
        api_set_token(S.token);
        save_settings();
        memset(S.login_pass, 0, sizeof S.login_pass);
        S.login = 0;
        S.favs.loaded = 0;
        if (S.tab == TAB_FAVORITES) do_load_first();
        if (S.tab == TAB_NOTIFS) do_notifs();
        toast("تم تسجيل الدخول", C_OK);
    } else {
        toast(r.error, C_BAD);
    }
    api_free(&r);
}

static void do_logout(void) {
    ApiResult r;
    api_call("POST", "/auth/logout", "{}", &r);
    api_free(&r);
    S.token[0] = 0;
    S.user_name[0] = 0;
    api_set_token("");
    save_settings();
    grid_clear(&S.favs);
    json_free(S.notif_doc);
    S.notif_doc = NULL;
    toast("تم تسجيل الخروج من هذا الجهاز", C_OK);
}

static void do_reconnect(void) {
    api_set_server(S.server);
    save_settings();
    grid_clear(&S.lib);
    grid_clear(&S.search);
    grid_clear(&S.favs);
    S.platform = 0;
    do_platforms();
    if (!S.conn_error[0]) toast("تم الاتصال بالخادم", C_OK);
}

/* ------------------------------------------------------------------ drawing primitives */

static void line(Canvas *c, int x0, int y0, int x1, int y1, int t, uint32_t col) {
    int steps = abs(x1 - x0) > abs(y1 - y0) ? abs(x1 - x0) : abs(y1 - y0);
    for (int i = 0; i <= steps; i++) {
        int x = x0 + (x1 - x0) * i / (steps ? steps : 1), y = y0 + (y1 - y0) * i / (steps ? steps : 1);
        gfx_round(c, x - t / 2, y - t / 2, t, t, t / 2, col);
    }
}

/* PlayStation button symbol (or a shoulder-button label) inside a 40px badge; returns its width */
static int button_badge(Canvas *c, int x, int y, unsigned b) {
    int s = 40;
    if (b & (BTN_L1 | BTN_R1 | BTN_L2 | BTN_R2 | BTN_OPTIONS)) {
        const char *t = b & BTN_L1 ? "L1" : b & BTN_R1 ? "R1" : b & BTN_L2 ? "L2" : b & BTN_R2 ? "R2" : "OPTIONS";
        int w = text_width(FONT_BOLD, 20, t, -1) + 20;
        gfx_round(c, x - w, y, w, s, 10, 0x33B0BAFF);
        text_draw(c, FONT_BOLD, 20, t, -1, x - w / 2, y + 4, ALIGN_CENTER, C_INK);
        return w;
    }
    gfx_round(c, x - s, y, s, s, s / 2, 0x26B0BAFF);
    int cx = x - s / 2, cy = y + s / 2;
    if (b & BTN_CROSS) { line(c, cx - 9, cy - 9, cx + 9, cy + 9, 4, 0xFF7FB4FF); line(c, cx - 9, cy + 9, cx + 9, cy - 9, 4, 0xFF7FB4FF); }
    else if (b & BTN_CIRCLE) gfx_round_stroke(c, cx - 11, cy - 11, 22, 22, 11, 3, 0xFFFF6E8A);
    else if (b & BTN_SQUARE) gfx_round_stroke(c, cx - 10, cy - 10, 20, 20, 2, 3, 0xFFE38AE5);
    else if (b & BTN_TRIANGLE) { line(c, cx, cy - 11, cx - 11, cy + 8, 3, 0xFF4FD8C0); line(c, cx, cy - 11, cx + 11, cy + 8, 3, 0xFF4FD8C0); line(c, cx - 11, cy + 8, cx + 11, cy + 8, 3, 0xFF4FD8C0); }
    else if (b & (BTN_UP | BTN_DOWN | BTN_LEFT | BTN_RIGHT)) { gfx_fill(c, cx - 3, cy - 12, 6, 24, C_MIST); gfx_fill(c, cx - 12, cy - 3, 24, 6, C_MIST); }
    return s;
}

/* hint bar: pairs of (button, label) from right to left */
static void hints(Canvas *c, const unsigned *btn, const char **label, int n) {
    int x = W - MX, y = H - 84;
    gfx_fill(c, 0, H - 110, W, 1, C_STROKE);
    for (int i = 0; i < n; i++) {
        x -= button_badge(c, x, y, btn[i]) + 12;
        int w = text_width(FONT_REGULAR, 24, label[i], -1);
        text_draw(c, FONT_REGULAR, 24, label[i], -1, x, y + 2, ALIGN_RIGHT, C_MIST);
        x -= w + 44;
    }
}

static void chip(Canvas *c, int *x, int y, const char *t, uint32_t col) {
    int w = text_width(FONT_BOLD, 22, t, -1) + 32;
    gfx_round(c, *x - w, y, w, 44, 22, (col & 0xFFFFFF) | 0x24000000);
    gfx_round_stroke(c, *x - w, y, w, 44, 22, 2, (col & 0xFFFFFF) | 0x66000000);
    text_draw(c, FONT_BOLD, 22, t, -1, *x - w / 2, y + 6, ALIGN_CENTER, col);
    *x -= w + 12;
}

static void background(Canvas *c) {
    if (!S.bg.px) {
        S.bg.w = W;
        S.bg.h = H;
        S.bg.px = (uint32_t *)malloc((size_t)W * H * 4);
        Canvas b = {S.bg.px, W, H, W};
        gfx_clip(&b, 0, 0, 0, 0);
        gfx_vgradient(&b, 0, 0, W, H, 0xFF16134A, C_BG);
        gfx_glow(&b, W - 260, 120, 620, 0x408B7CFF);
        gfx_glow(&b, 220, H - 120, 640, 0x305AA9FF);
        gfx_glow(&b, W / 2, H + 200, 520, 0x205EE6F0);
        gfx_clip(c, 0, 0, 0, 0);
    }
    for (int y = 0; y < H; y++) memcpy(&c->px[y * c->stride], &S.bg.px[y * W], (size_t)W * 4);
}

static void logo(Canvas *c, int x, int y) {
    /* the site's mark: three book spines on a shelf */
    gfx_round(c, x - 64, y, 64, 64, 18, 0xFF141A4A);
    gfx_round_stroke(c, x - 64, y, 64, 64, 18, 2, 0xCC6E8CFF);
    gfx_round_gradient(c, x - 50, y + 16, 9, 30, 3, 0x808B7CFF, 0x805AA9FF, 0x805EE6F0);
    gfx_round_gradient(c, x - 37, y + 11, 9, 35, 3, 0xC88B7CFF, 0xC85AA9FF, 0xC85EE6F0);
    gfx_round_gradient(c, x - 24, y + 15, 9, 31, 3, C_ACCENT, C_ACCENT2, C_ACCENT3);
    gfx_round_gradient(c, x - 52, y + 49, 40, 4, 2, C_ACCENT, C_ACCENT2, C_ACCENT3);
}

static void top_bar(Canvas *c) {
    logo(c, W - MX, 44);
    text_draw(c, FONT_BOLD, 34, "ULTIMATE PS LIBRARY", -1, W - MX - 84, 46, ALIGN_RIGHT, C_INK);
    /* sections (L2 / R2) */
    int x = W - MX - 560;
    for (int i = 0; i < TAB_COUNT; i++) {
        int sel = i == (int)S.tab;
        int w = text_width(FONT_BOLD, 26, TAB_NAMES[i], -1) + 48;
        if (sel) gfx_round_gradient(c, x - w, 40, w, 60, 30, C_ACCENT3, C_ACCENT2, C_ACCENT);
        else gfx_round(c, x - w, 40, w, 60, 30, 0x14FFFFFF);
        text_draw(c, FONT_BOLD, 26, TAB_NAMES[i], -1, x - w / 2, 47, ALIGN_CENTER, sel ? 0xFF0B0C24 : C_INK);
        x -= w + 14;
    }
    button_badge(c, x - 4, 50, BTN_R2);
    button_badge(c, W - MX - 500, 50, BTN_L2);
    if (S.user_name[0]) {
        int ux = MX;
        int w = text_width(FONT_REGULAR, 24, S.user_name, -1);
        gfx_round(c, ux, 44, w + 40, 52, 26, 0x1AFFFFFF);
        text_draw(c, FONT_REGULAR, 24, S.user_name, -1, ux + 20, 52, ALIGN_LEFT, C_MIST);
    }
}

static void empty_state(Canvas *c, const char *title, const char *body, uint32_t col) {
    gfx_round(c, W / 2 - 46, 420, 92, 92, 30, (col & 0xFFFFFF) | 0x2A000000);
    gfx_round_stroke(c, W / 2 - 22, 444, 44, 44, 10, 4, col);
    text_draw(c, FONT_BOLD, 36, title, -1, W / 2, 540, ALIGN_CENTER, C_INK);
    if (body && *body) text_box(c, FONT_REGULAR, 26, body, W / 2 - 520, 600, 1040, ALIGN_CENTER, C_MIST, 4);
}

/* ------------------------------------------------------------------ grid screens */

static void tile(Canvas *c, const JVal *g, int x, int y, int focused) {
    if (focused) {
        gfx_round_gradient(c, x - 9, y - 9, TILE_W + 18, TILE_H + 18, 24, C_ACCENT3, C_ACCENT2, C_ACCENT);
        gfx_glow(c, x + TILE_W / 2, y + TILE_H / 2, 260, 0x205AA9FF);
    }
    const Image *img = image_cached(cover_url(g));
    if (img) gfx_image(c, img, x, y, TILE_W, TILE_H, 18, 255, 1);
    else {
        gfx_round(c, x, y, TILE_W, TILE_H, 18, 0xFF1A1F4A);
        text_box(c, FONT_BOLD, 26, jstr(g, "title", ""), x + 16, y + TILE_H / 2 - 40, TILE_W - 32, ALIGN_CENTER, C_MIST, 3);
    }
    text_fit(c, FONT_BOLD, 26, jstr(g, "title", ""), x + TILE_W, y + TILE_H + 14, TILE_W, ALIGN_RIGHT, focused ? C_INK : 0xFFD0D5F0);
    char sub[160];
    int year = (int)jnum(g, "release_year", 0);
    snprintf(sub, sizeof sub, "%s%s%s", jstr(jget(g, "platform"), "short_name", ""), year ? " • " : "", "");
    if (year) snprintf(sub + strlen(sub), sizeof sub - strlen(sub), "%d", year);
    text_fit(c, FONT_REGULAR, 22, sub, x + TILE_W, y + TILE_H + 56, TILE_W, ALIGN_RIGHT, C_FAINT);
    if (jbool(g, "is_favorite")) {
        gfx_round(c, x + 12, y + 12, 36, 36, 18, 0xCC0B0C24);
        gfx_round(c, x + 22, y + 22, 16, 16, 8, C_BAD);
    }
}

static void grid_draw(Canvas *c, Grid *g, int top) {
    if (!g->loaded) return;
    if (g->count == 0) {
        if (S.conn_error[0] && g->error[0]) empty_state(c, "لا يوجد اتصال بالخادم", S.conn_error, C_BAD);
        else if (g->error[0]) empty_state(c, "تعذر التحميل", g->error, C_BAD);
        else if (g == &S.search) empty_state(c, S.query[0] ? "لا توجد نتائج" : "ابحث في المكتبة", S.query[0] ? "جرّب كلمة أخرى أو اسم المطور." : "اضغط X لكتابة اسم اللعبة أو رمز المنتج.", C_ACCENT);
        else if (g == &S.favs) empty_state(c, "لا توجد ألعاب في المفضلة", "افتح لعبة واضغط X لإضافتها.", C_ACCENT);
        else empty_state(c, "لا توجد ألعاب", "لم تُنشر ألعاب لهذا الجهاز بعد.", C_ACCENT);
        return;
    }
    int right = W - MX;
    int frow = g->focus / COLS;
    if (frow < g->top_row) g->top_row = frow;
    if (frow >= g->top_row + VISIBLE_ROWS) g->top_row = frow - VISIBLE_ROWS + 1;
    gfx_clip(c, 0, top - 20, W, H - 120 - (top - 20));
    for (int r = g->top_row; r < g->top_row + VISIBLE_ROWS + 1; r++) {
        for (int col = 0; col < COLS; col++) {
            int i = r * COLS + col;
            if (i >= g->count) break;
            int x = right - (col + 1) * TILE_W - col * GAP_X;
            int y = top + (r - g->top_row) * ROW_H;
            tile(c, g->items[i], x, y, i == g->focus);
        }
    }
    gfx_clip(c, 0, 0, 0, 0);
    /* scrollbar and counter */
    char cnt[120];
    snprintf(cnt, sizeof cnt, "%d / %d", g->focus + 1, g->total);
    text_draw(c, FONT_REGULAR, 24, cnt, -1, MX, top - 64, ALIGN_LEFT, C_FAINT);
}

static int grid_input(Grid *g, unsigned b) {
    if (!g->count) return 0;
    int f = g->focus;
    if (b & BTN_LEFT) f++;
    if (b & BTN_RIGHT) f--;
    if (b & BTN_DOWN) f += COLS;
    if (b & BTN_UP) f -= COLS;
    if (f < 0) f = (b & BTN_UP) ? g->focus : 0;
    if (f >= g->count) f = (b & BTN_DOWN) && g->focus / COLS < (g->count - 1) / COLS ? g->count - 1 : g->count - 1;
    if (f != g->focus) {
        g->focus = f;
        S.dirty = 1;
        if (g->count - f <= COLS * 2 && g->page < g->pages_total) later(do_load_more, "جارٍ تحميل المزيد…");
    }
    if (b & BTN_CROSS) {
        details_id = (int64_t)jnum(g->items[g->focus], "id", 0);
        S.details_from = g;
        later(do_details, "جارٍ فتح اللعبة…");
    }
    return 1;
}

static void library_draw(Canvas *c) {
    /* platform pills (L1 / R1) and the sort */
    int x = W - MX, y = 136;
    int n = S.platforms ? S.platforms->count + 1 : 1;
    for (int i = 0; i < n; i++) {
        const char *name = i == 0 ? "كل الأجهزة" : jstr(jat(S.platforms, i - 1), "short_name", "?");
        int sel = i == S.platform;
        int w = text_width(FONT_BOLD, 24, name, -1) + 40;
        if (sel) gfx_round(c, x - w, y, w, 52, 26, 0x338B7CFF), gfx_round_stroke(c, x - w, y, w, 52, 26, 2, C_ACCENT);
        else gfx_round(c, x - w, y, w, 52, 26, 0x12FFFFFF);
        text_draw(c, FONT_BOLD, 24, name, -1, x - w / 2, y + 7, ALIGN_CENTER, sel ? C_INK : C_MIST);
        x -= w + 12;
    }
    x -= 8;
    x -= button_badge(c, x, y + 6, BTN_R1) + 8;
    button_badge(c, x, y + 6, BTN_L1);
    char sort[120];
    snprintf(sort, sizeof sort, "الترتيب: %s", SORT_NAMES[S.sort]);
    text_draw(c, FONT_REGULAR, 24, sort, -1, MX + 50, y + 9, ALIGN_LEFT, C_MIST);
    button_badge(c, MX + 40, y + 6, BTN_SQUARE);
    grid_draw(c, &S.lib, TOP + 70);
    unsigned hb[] = {BTN_CROSS, BTN_L1, BTN_SQUARE, BTN_L2, BTN_OPTIONS};
    const char *hl[] = {"فتح اللعبة", "تغيير الجهاز", "الترتيب", "الأقسام", "الإعدادات"};
    hints(c, hb, hl, 5);
}

/* ------------------------------------------------------------------ details */

static void details_draw(Canvas *c) {
    const JVal *g = S.game;
    const Image *banner = image_cached(jstr(jget(g, "banner"), "sm_jpg", NULL));
    if (banner) {
        gfx_image(c, banner, 0, 0, W, 620, 0, 90, 1);
        gfx_vgradient(c, 0, 380, W, 240, 0x00070816, 0xFF0C0D28);
    }
    int right = W - MX;
    const Image *cover = image_cached(cover_url(g));
    int cy = 150 - S.detail_scroll;
    if (cover) gfx_image(c, cover, right - 360, cy, 360, 540, 24, 255, 1);
    else gfx_round(c, right - 360, cy, 360, 540, 24, 0xFF1A1F4A);
    int tx = right - 420, tw = tx - MX;
    int y = cy;
    y += text_box(c, FONT_BOLD, 56, jstr(g, "title", ""), MX, y, tw, ALIGN_RIGHT, C_INK, 2) + 6;
    char line1[300];
    snprintf(line1, sizeof line1, "%s%s%s", jstr(jget(g, "platform"), "name", ""), jstr(g, "edition", NULL) ? " — " : "", jstr(g, "edition", ""));
    text_draw(c, FONT_REGULAR, 30, line1, -1, tx, y, ALIGN_RIGHT, C_MIST);
    y += 64;
    int x = tx;
    if (jstr(g, "region", NULL)) chip(c, &x, y, region_name(jstr(g, "region", NULL)), C_ACCENT2);
    char buf[200];
    if (jnum(g, "release_year", 0) > 0) { snprintf(buf, sizeof buf, "%d", (int)jnum(g, "release_year", 0)); chip(c, &x, y, buf, C_ACCENT2); }
    if (jstr(g, "size_human", NULL)) chip(c, &x, y, jstr(g, "size_human", ""), C_ACCENT2);
    if (jnum(g, "rating", 0) > 0) { snprintf(buf, sizeof buf, "التقييم %.1f", jnum(g, "rating", 0)); chip(c, &x, y, buf, C_WARN); }
    const char *fs = jstr(g, "files_status", "unavailable");
    chip(c, &x, y, !strcmp(fs, "available") ? "الملفات متوفرة" : !strcmp(fs, "coming_soon") ? "قريباً" : "غير متوفرة حالياً", !strcmp(fs, "available") ? C_OK : C_MIST);
    if (jbool(g, "is_favorite")) chip(c, &x, y, "في مفضلتك", C_BAD);
    y += 76;
    if (jstr(g, "developer", NULL) || jstr(g, "publisher", NULL)) {
        snprintf(buf, sizeof buf, "المطور: %s   •   الناشر: %s", jstr(g, "developer", "—"), jstr(g, "publisher", "—"));
        text_draw(c, FONT_REGULAR, 26, buf, -1, tx, y, ALIGN_RIGHT, C_MIST);
        y += 46;
    }
    if (jstr(g, "description", NULL)) {
        y += 10;
        y += text_box(c, FONT_REGULAR, 28, jstr(g, "description", ""), MX, y, tw, ALIGN_RIGHT, 0xFFD8DCF5, 0) + 16;
    }
    /* sections below the cover use the full width */
    if (y < cy + 580) y = cy + 580;
    JVal *files = jget(g, "files");
    if (files && files->count) {
        text_draw(c, FONT_BOLD, 32, "الملفات", -1, right, y, ALIGN_RIGHT, C_ACCENT3);
        y += 56;
        for (JVal *f = files->child; f; f = f->next) {
            gfx_round(c, MX, y, right - MX, 76, 18, C_CARD);
            gfx_round_stroke(c, MX, y, right - MX, 76, 18, 2, C_STROKE);
            text_fit(c, FONT_BOLD, 26, jstr(f, "name", ""), right - 24, y + 8, 900, ALIGN_RIGHT, C_INK);
            snprintf(buf, sizeof buf, "%s • %s • %s", jstr(f, "type_label", ""), jstr(f, "size_human", ""), access_name(jstr(f, "access_level", NULL)));
            text_draw(c, FONT_REGULAR, 22, buf, -1, right - 24, y + 44, ALIGN_RIGHT, C_MIST);
            text_draw(c, FONT_REGULAR, 22, "التنزيل من الموقع فقط", -1, MX + 24, y + 26, ALIGN_LEFT, C_FAINT);
            y += 92;
        }
    }
    JVal *vers = jget(g, "versions");
    if (vers && vers->count) {
        y += 10;
        text_draw(c, FONT_BOLD, 32, "الإصدارات", -1, right, y, ALIGN_RIGHT, C_ACCENT3);
        y += 56;
        for (JVal *v = vers->child; v; v = v->next) {
            snprintf(buf, sizeof buf, "الإصدار %s%s%s%s", jstr(v, "version", ""), jstr(v, "release_date", NULL) ? " — " : "", jstr(v, "release_date", ""),
                     jbool(v, "is_latest") ? "  (الأحدث)" : "");
            text_draw(c, FONT_BOLD, 26, buf, -1, right, y, ALIGN_RIGHT, C_INK);
            y += 44;
            if (jstr(v, "changelog", NULL)) y += text_box(c, FONT_REGULAR, 24, jstr(v, "changelog", ""), MX, y, right - MX, ALIGN_RIGHT, C_MIST, 3) + 8;
        }
    }
    JVal *compat = jget(g, "compatibility");
    if (compat && compat->count) {
        y += 10;
        text_draw(c, FONT_BOLD, 32, "التوافق", -1, right, y, ALIGN_RIGHT, C_ACCENT3);
        y += 56;
        for (JVal *v = compat->child; v; v = v->next) {
            snprintf(buf, sizeof buf, "%s — %s", jstr(v, "target", ""), jstr(v, "status_label", ""));
            text_draw(c, FONT_REGULAR, 26, buf, -1, right, y, ALIGN_RIGHT, C_INK);
            y += 44;
        }
    }
    y += 20;
    snprintf(buf, sizeof buf, "صفحة اللعبة على الموقع: %s", jstr(g, "url", ""));
    text_draw(c, FONT_REGULAR, 24, buf, -1, right, y, ALIGN_RIGHT, C_FAINT);
    y += 40;
    text_draw(c, FONT_REGULAR, 24, "هذا التطبيق لتصفح المكتبة فقط: لا ينزّل الألعاب ولا يثبّتها.", -1, right, y, ALIGN_RIGHT, C_FAINT);
    /* hint bar on a solid strip */
    gfx_fill(c, 0, H - 112, W, 112, 0xF0090A1E);
    unsigned hb[] = {BTN_CROSS, BTN_CIRCLE, BTN_UP, BTN_L1};
    const char *hl[] = {S.token[0] ? (jbool(g, "is_favorite") ? "إزالة من المفضلة" : "إضافة للمفضلة") : "سجّل الدخول للمفضلة", "رجوع", "تمرير", "اللعبة السابقة/التالية"};
    hints(c, hb, hl, 4);
}

static void details_input(unsigned b) {
    if (b & BTN_CIRCLE) {
        S.details = 0;
        S.dirty = 1;
        return;
    }
    if (b & BTN_DOWN) { S.detail_scroll += 160; S.dirty = 1; }
    if (b & BTN_UP) { S.detail_scroll -= 160; if (S.detail_scroll < 0) S.detail_scroll = 0; S.dirty = 1; }
    if (b & BTN_CROSS) {
        if (S.token[0]) later(do_toggle_favorite, "جارٍ الحفظ…");
        else { S.login = 1; S.details = 0; S.dirty = 1; }
    }
    if ((b & (BTN_L1 | BTN_R1)) && S.details_from && S.details_from->count) {
        Grid *g = S.details_from;
        int f = g->focus + ((b & BTN_R1) ? 1 : -1);
        if (f >= 0 && f < g->count) {
            g->focus = f;
            details_id = (int64_t)jnum(g->items[f], "id", 0);
            later(do_details, "جارٍ فتح اللعبة…");
        }
    }
}

/* ------------------------------------------------------------------ news, notifications, settings, sign-in */

static void list_draw(Canvas *c, JVal *items, int focus, int news) {
    int right = W - MX, y = TOP - 10, i = 0;
    int first = focus > 3 ? focus - 3 : 0;
    for (JVal *it = items ? items->child : NULL; it; it = it->next, i++) {
        if (i < first) continue;
        if (y > H - 260) break;
        int sel = i == focus;
        int h = 150;
        gfx_round(c, MX, y, right - MX, h, 22, sel ? 0xE61E2350 : C_CARD);
        gfx_round_stroke(c, MX, y, right - MX, h, 22, sel ? 3 : 2, sel ? C_ACCENT2 : C_STROKE);
        text_fit(c, FONT_BOLD, 30, jstr(it, "title", ""), right - 32, y + 16, right - MX - 360, ALIGN_RIGHT, C_INK);
        char meta[300];
        const char *when = jstr(it, news ? "published_at" : "created_at", "");
        snprintf(meta, sizeof meta, "%s%s%.10s", news ? jstr(it, "type_label", "") : (jbool(it, "is_read") ? "مقروء" : "جديد"), " • ", when);
        if (news && jget(it, "game") && jget(it, "game")->t == J_OBJ) snprintf(meta + strlen(meta), sizeof meta - strlen(meta), " • %s", jstr(jget(it, "game"), "title", ""));
        text_draw(c, FONT_REGULAR, 22, meta, -1, right - 32, y + 62, ALIGN_RIGHT, news ? C_ACCENT3 : (jbool(it, "is_read") ? C_FAINT : C_WARN));
        if (jstr(it, "body", NULL)) text_fit(c, FONT_REGULAR, 24, jstr(it, "body", ""), right - 32, y + 98, right - MX - 64, ALIGN_RIGHT, C_MIST);
        y += h + 18;
    }
}

static const char *SETTINGS_ITEMS[] = {"عنوان الخادم", "الحساب", "إعادة الاتصال", "عن التطبيق", "خروج من التطبيق"};

static void settings_draw(Canvas *c) {
    int right = W - MX, y = TOP;
    for (int i = 0; i < 5; i++) {
        int sel = i == S.settings_focus;
        gfx_round(c, MX + 300, y, right - MX - 300, 110, 22, sel ? 0xE61E2350 : C_CARD);
        gfx_round_stroke(c, MX + 300, y, right - MX - 300, 110, 22, sel ? 3 : 2, sel ? C_ACCENT2 : C_STROKE);
        text_draw(c, FONT_BOLD, 30, SETTINGS_ITEMS[i], -1, right - 32, y + 14, ALIGN_RIGHT, C_INK);
        char v[500];
        switch (i) {
            case 0: snprintf(v, sizeof v, "%s", S.server[0] ? S.server : "غير محدد — اضغط X لكتابته"); break;
            case 1: snprintf(v, sizeof v, "%s", S.token[0] ? (S.user_name[0] ? S.user_name : "مسجل") : "غير مسجل — اضغط X لتسجيل الدخول"); break;
            case 2: snprintf(v, sizeof v, "%s", S.conn_error[0] ? S.conn_error : "متصل"); break;
            case 3: snprintf(v, sizeof v, "الإصدار %s — خط Readex Pro (OFL 1.1). للتصفح فقط.", APP_VERSION); break;
            default: snprintf(v, sizeof v, "العودة إلى الشاشة الرئيسية للجهاز"); break;
        }
        text_fit(c, FONT_REGULAR, 24, v, right - 32, y + 62, right - MX - 360, ALIGN_RIGHT, i == 2 && S.conn_error[0] ? C_BAD : C_MIST);
        y += 128;
    }
    if (S.token[0] && S.settings_focus == 1) {
        text_draw(c, FONT_REGULAR, 24, "اضغط X لتسجيل الخروج من هذا الجهاز", -1, right, y + 10, ALIGN_RIGHT, C_FAINT);
    }
}

static void login_draw(Canvas *c) {
    int cw = 900, x = (W - cw) / 2, y = 230;
    gfx_round(c, x, y, cw, 560, 32, C_CARD);
    gfx_round_stroke(c, x, y, cw, 560, 32, 2, C_STROKE);
    text_draw(c, FONT_BOLD, 42, "تسجيل الدخول", -1, x + cw - 48, y + 36, ALIGN_RIGHT, C_INK);
    text_box(c, FONT_REGULAR, 24, "للمفضلة والإشعارات. استخدم حساب المكتبة العادي، لا حساب المدير.", x + 48, y + 100, cw - 96, ALIGN_RIGHT, C_MIST, 2);
    const char *labels[] = {"اسم المستخدم أو البريد", "كلمة المرور", "دخول"};
    for (int i = 0; i < 3; i++) {
        int fy = y + 190 + i * 120, sel = i == S.login_focus;
        if (i < 2) {
            text_draw(c, FONT_BOLD, 24, labels[i], -1, x + cw - 48, fy - 2, ALIGN_RIGHT, C_MIST);
            gfx_round(c, x + 48, fy + 36, cw - 96, 64, 18, 0x1FFFFFFF);
            gfx_round_stroke(c, x + 48, fy + 36, cw - 96, 64, 18, sel ? 3 : 2, sel ? C_ACCENT : C_STROKE2);
            char shown[160];
            if (i == 1) { size_t n = strlen(S.login_pass); if (n > 40) n = 40; memset(shown, '*', n); shown[n] = 0; }
            else snprintf(shown, sizeof shown, "%s", S.login_user);
            text_draw(c, FONT_REGULAR, 28, shown, -1, x + 72, fy + 44, ALIGN_LEFT, C_INK);
        } else {
            if (sel) gfx_round_gradient(c, x + 48, fy + 20, cw - 96, 72, 22, C_ACCENT3, C_ACCENT2, C_ACCENT);
            else gfx_round(c, x + 48, fy + 20, cw - 96, 72, 22, 0x26FFFFFF);
            text_draw(c, FONT_BOLD, 30, labels[i], -1, x + cw / 2, fy + 32, ALIGN_CENTER, sel ? 0xFF0B0C24 : C_INK);
        }
    }
    unsigned hb[] = {BTN_CROSS, BTN_CIRCLE};
    const char *hl[] = {"اختيار", "إلغاء"};
    hints(c, hb, hl, 2);
}

/* ------------------------------------------------------------------ on-screen keyboard */

static const char *KB_LAYOUTS[4][4] = {
    {"1234567890", "qwertyuiop", "asdfghjkl-", "zxcvbnm_.@"},
    {"1234567890", "QWERTYUIOP", "ASDFGHJKL-", "ZXCVBNM_.@"},
    {"!#$%&*()+=", "/:;,?'\"<>~", "[]{}\\|^`-_", "0123456789"},
    {"ضصثقفغعهخح", "شسيبلاتنمك", "ئءؤرىةوزظط", "ذدجأإآ.،؟/"},
};
static const char *KB_LAYOUT_NAMES[4] = {"abc", "ABC", "#+=", "عربي"};
static const char *KB_SPECIAL[4] = {"تغيير اللغة", "مسافة", "حذف", "تم"};

static int kb_row_len(int layout, int row) {
    if (row == 4) return 4;
    const char *s = KB_LAYOUTS[layout][row];
    int n = 0;
    for (; *s; s++) if (((unsigned char)*s & 0xC0) != 0x80) n++;
    return n;
}

static void kb_key(int layout, int row, int col, char *out) {
    const char *s = KB_LAYOUTS[layout][row];
    int k = 0;
    while (*s) {
        int len = 1;
        while ((((unsigned char)s[len]) & 0xC0) == 0x80) len++;
        if (k == col) { memcpy(out, s, (size_t)len); out[len] = 0; return; }
        s += len;
        k++;
    }
    out[0] = 0;
}

static void kb_open(KbTarget t, const char *initial) {
    S.kb = t;
    snprintf(S.kb_text, sizeof S.kb_text, "%s", initial ? initial : "");
    S.kb_row = 1;
    S.kb_col = 0;
    S.kb_layout = t == KB_SEARCH ? 0 : 0;
    S.dirty = 1;
}

static void kb_done(void) {
    KbTarget t = S.kb;
    S.kb = KB_NONE;
    S.dirty = 1;
    if (t == KB_SEARCH) {
        snprintf(S.query, sizeof S.query, "%s", S.kb_text);
        if (S.query[0]) later(do_load_first, "جارٍ البحث…");
    } else if (t == KB_SERVER) {
        char v[400];
        snprintf(v, sizeof v, "%s", S.kb_text);
        if (v[0] && strncmp(v, "http://", 7) && strncmp(v, "https://", 8)) snprintf(S.server, sizeof S.server, "https://%s", v);
        else snprintf(S.server, sizeof S.server, "%s", v);
        later(do_reconnect, "جارٍ الاتصال بالخادم…");
    } else if (t == KB_USER) {
        snprintf(S.login_user, sizeof S.login_user, "%s", S.kb_text);
    } else if (t == KB_PASS) {
        snprintf(S.login_pass, sizeof S.login_pass, "%s", S.kb_text);
        memset(S.kb_text, 0, sizeof S.kb_text);
    }
}

static void kb_draw(Canvas *c) {
    int kw = 1240, kh = 640, x = (W - kw) / 2, y = H - kh - 40;
    gfx_fill(c, 0, 0, W, H, 0x99000000);
    gfx_round(c, x, y, kw, kh, 32, 0xFA151A3A);
    gfx_round_stroke(c, x, y, kw, kh, 32, 2, C_STROKE2);
    const char *title = S.kb == KB_SEARCH ? "البحث" : S.kb == KB_SERVER ? "عنوان الخادم (https://…)" : S.kb == KB_USER ? "اسم المستخدم" : "كلمة المرور";
    text_draw(c, FONT_BOLD, 30, title, -1, x + kw - 40, y + 26, ALIGN_RIGHT, C_MIST);
    gfx_round(c, x + 40, y + 80, kw - 80, 72, 18, 0x24FFFFFF);
    char shown[320];
    if (S.kb == KB_PASS) { size_t n = strlen(S.kb_text); if (n > 60) n = 60; memset(shown, '*', n); shown[n] = 0; }
    else snprintf(shown, sizeof shown, "%s", S.kb_text);
    strncat(shown, "|", sizeof shown - strlen(shown) - 1);
    text_fit(c, FONT_REGULAR, 32, shown, x + 60, y + 92, kw - 120, ALIGN_LEFT, C_INK);
    for (int r = 0; r < 5; r++) {
        int n = kb_row_len(S.kb_layout, r);
        int keyw = r == 4 ? 270 : 104, gap = 14;
        int total = n * keyw + (n - 1) * gap;
        int kx = x + (kw - total) / 2, ky = y + 180 + r * 86;
        for (int k = 0; k < n; k++) {
            int sel = r == S.kb_row && k == S.kb_col;
            int px = kx + k * (keyw + gap);
            if (sel) gfx_round_gradient(c, px, ky, keyw, 72, 16, C_ACCENT3, C_ACCENT2, C_ACCENT);
            else gfx_round(c, px, ky, keyw, 72, 16, 0x1AFFFFFF);
            char label[64];
            if (r == 4) snprintf(label, sizeof label, "%s", k == 0 ? KB_LAYOUT_NAMES[(S.kb_layout + 1) % 4] : KB_SPECIAL[k]);
            else kb_key(S.kb_layout, r, k, label);
            text_draw(c, FONT_BOLD, 30, label, -1, px + keyw / 2, ky + 12, ALIGN_CENTER, sel ? 0xFF0B0C24 : C_INK);
        }
    }
    unsigned hb[] = {BTN_CROSS, BTN_SQUARE, BTN_TRIANGLE, BTN_R1, BTN_OPTIONS, BTN_CIRCLE};
    const char *hl[] = {"كتابة", "حذف", "مسافة", "اللغة", "تم", "إلغاء"};
    hints(c, hb, hl, 6);
}

static void kb_input(unsigned b) {
    int n = kb_row_len(S.kb_layout, S.kb_row);
    if (b & BTN_RIGHT) S.kb_col = (S.kb_col + 1) % n;
    if (b & BTN_LEFT) S.kb_col = (S.kb_col + n - 1) % n;
    if (b & (BTN_UP | BTN_DOWN)) {
        S.kb_row = (S.kb_row + ((b & BTN_DOWN) ? 1 : 4)) % 5;
        int m = kb_row_len(S.kb_layout, S.kb_row);
        if (S.kb_col >= m) S.kb_col = m - 1;
    }
    size_t len = strlen(S.kb_text);
    if (b & BTN_SQUARE) {
        while (len > 0 && (((unsigned char)S.kb_text[len - 1]) & 0xC0) == 0x80) len--;
        if (len > 0) len--;
        S.kb_text[len] = 0;
    }
    if ((b & BTN_TRIANGLE) && len + 1 < sizeof S.kb_text) strcat(S.kb_text, " ");
    if (b & BTN_R1) S.kb_layout = (S.kb_layout + 1) % 4;
    if (b & BTN_CROSS) {
        if (S.kb_row == 4) {
            if (S.kb_col == 0) S.kb_layout = (S.kb_layout + 1) % 4;
            else if (S.kb_col == 1 && len + 1 < sizeof S.kb_text) strcat(S.kb_text, " ");
            else if (S.kb_col == 2) { while (len > 0 && (((unsigned char)S.kb_text[len - 1]) & 0xC0) == 0x80) len--; if (len > 0) S.kb_text[len - 1] = 0; }
            else { kb_done(); return; }
        } else {
            char k[8];
            kb_key(S.kb_layout, S.kb_row, S.kb_col, k);
            if (len + strlen(k) < sizeof S.kb_text - 1) strcat(S.kb_text, k);
        }
    }
    if (b & BTN_OPTIONS) { kb_done(); return; }
    if (b & BTN_CIRCLE) { S.kb = KB_NONE; memset(S.kb_text, 0, sizeof S.kb_text); }
    S.dirty = 1;
}

/* ------------------------------------------------------------------ frame */

static void switch_tab(int dir) {
    S.tab = (Tab)((S.tab + TAB_COUNT + dir) % TAB_COUNT);
    S.dirty = 1;
    if (S.tab == TAB_NEWS && !S.news_doc) later(do_news, "جارٍ تحميل الأخبار…");
    if (S.tab == TAB_NOTIFS && S.token[0]) later(do_notifs, "جارٍ تحميل الإشعارات…");
    if (S.tab == TAB_FAVORITES && S.token[0] && !S.favs.loaded) later(do_load_first, "جارٍ تحميل المفضلة…");
    if (S.tab == TAB_LIBRARY && !S.lib.loaded) later(do_platforms, "جارٍ تحميل المكتبة…");
}

int app_init(Canvas *c) {
    char reg[512], bold[512];
    snprintf(reg, sizeof reg, "%s/fonts/readex_regular.ttf", plat_asset_dir());
    snprintf(bold, sizeof bold, "%s/fonts/readex_semibold.ttf", plat_asset_dir());
    if (!text_init(reg, bold)) return 0;
    load_settings();
    gfx_clip(c, 0, 0, 0, 0);
    if (S.server[0]) later(do_platforms, "جارٍ الاتصال بالمكتبة…");
    else { S.tab = TAB_SETTINGS; kb_open(KB_SERVER, "https://"); }
    S.dirty = 1;
    return 1;
}

int app_should_quit(void) { return S.quit; }

/* test hook (desktop harness): types text into the open on-screen keyboard */
void app_debug_type(const char *s) {
    if (S.kb != KB_NONE && strlen(S.kb_text) + strlen(s) < sizeof S.kb_text) {
        strcat(S.kb_text, s);
        S.dirty = 1;
    }
}

int app_idle(void) {
    if (S.pending) {
        void (*fn)(void) = S.pending;
        S.pending = NULL;
        fn();
        S.loading[0] = 0;
        S.dirty = 1;
        return 1;
    }
    if (S.toast[0] && plat_ms() > S.toast_until) {
        S.toast[0] = 0;
        S.dirty = 1;
        return 1;
    }
    /* one cover per idle tick: visible tiles first */
    Grid *g = current_grid();
    if (!S.details && (S.tab == TAB_LIBRARY || S.tab == TAB_SEARCH || S.tab == TAB_FAVORITES)) {
        int first = g->top_row * COLS, last = first + COLS * (VISIBLE_ROWS + 1);
        for (int i = first; i < last && i < g->count; i++) {
            const char *u = cover_url(g->items[i]);
            if (u && !image_known(u)) {
                image_fetch(u, 300);
                S.dirty = 1;
                return 1;
            }
        }
    }
    return 0;
}

int app_frame(Canvas *c, unsigned b) {
    if (b && !S.pending) {
        S.dirty = 1; /* every button press redraws the screen */
        if (S.kb != KB_NONE) kb_input(b);
        else if (S.login) {
            if (b & BTN_DOWN) S.login_focus = (S.login_focus + 1) % 3;
            if (b & BTN_UP) S.login_focus = (S.login_focus + 2) % 3;
            if (b & BTN_CIRCLE) S.login = 0;
            if (b & BTN_CROSS) {
                if (S.login_focus == 0) kb_open(KB_USER, S.login_user);
                else if (S.login_focus == 1) kb_open(KB_PASS, "");
                else if (S.login_user[0] && S.login_pass[0]) later(do_login, "جارٍ تسجيل الدخول…");
                else toast("اكتب اسم المستخدم وكلمة المرور", C_WARN);
            }
            S.dirty = 1;
        } else if (S.details) details_input(b);
        else {
            if (b & BTN_R2) switch_tab(1);
            if (b & BTN_L2) switch_tab(-1);
            if ((b & BTN_OPTIONS) && S.tab != TAB_SETTINGS) { S.tab = TAB_SETTINGS; S.dirty = 1; }
            switch (S.tab) {
                case TAB_LIBRARY:
                    if (b & (BTN_L1 | BTN_R1)) {
                        int n = S.platforms ? S.platforms->count + 1 : 1;
                        S.platform = (S.platform + ((b & BTN_L1) ? 1 : n - 1)) % n;
                        later(do_load_first, "جارٍ التحميل…");
                    } else if (b & BTN_SQUARE) {
                        S.sort = (S.sort + 1) % 4;
                        later(do_load_first, "جارٍ الترتيب…");
                    } else grid_input(&S.lib, b);
                    break;
                case TAB_SEARCH:
                    if ((b & BTN_TRIANGLE) || ((b & BTN_CROSS) && !S.search.count)) kb_open(KB_SEARCH, S.query);
                    else grid_input(&S.search, b);
                    break;
                case TAB_FAVORITES:
                    if (!S.token[0] && (b & BTN_CROSS)) S.login = 1;
                    else grid_input(&S.favs, b);
                    break;
                case TAB_NEWS:
                case TAB_NOTIFS: {
                    JDoc *d = S.tab == TAB_NEWS ? S.news_doc : S.notif_doc;
                    int *focus = S.tab == TAB_NEWS ? &S.news_focus : &S.notif_focus;
                    JVal *items = jget(json_root(d), "data");
                    int n = items ? items->count : 0;
                    if ((b & BTN_DOWN) && *focus < n - 1) (*focus)++;
                    if ((b & BTN_UP) && *focus > 0) (*focus)--;
                    if (S.tab == TAB_NOTIFS && !S.token[0] && (b & BTN_CROSS)) S.login = 1;
                    if (S.tab == TAB_NEWS && (b & BTN_CROSS) && n) {
                        JVal *game = jget(jat(items, *focus), "game");
                        if (game && game->t == J_OBJ) {
                            details_id = (int64_t)jnum(game, "id", 0);
                            S.details_from = NULL;
                            later(do_details, "جارٍ فتح اللعبة…");
                        }
                    }
                    if (b & BTN_TRIANGLE) later(S.tab == TAB_NEWS ? do_news : do_notifs, "جارٍ التحديث…");
                    S.dirty = 1;
                    break;
                }
                case TAB_SETTINGS:
                    if (b & BTN_DOWN) S.settings_focus = (S.settings_focus + 1) % 5;
                    if (b & BTN_UP) S.settings_focus = (S.settings_focus + 4) % 5;
                    if (b & BTN_CROSS) {
                        if (S.settings_focus == 0) kb_open(KB_SERVER, S.server[0] ? S.server : "https://");
                        else if (S.settings_focus == 1) { if (S.token[0]) later(do_logout, "جارٍ تسجيل الخروج…"); else S.login = 1; }
                        else if (S.settings_focus == 2) later(do_reconnect, "جارٍ الاتصال…");
                        else if (S.settings_focus == 4) S.quit = 1;
                    }
                    S.dirty = 1;
                    break;
                default:
                    break;
            }
        }
    }
    if (!S.dirty) return 0;
    S.dirty = 0;

    background(c);
    if (S.details && S.game) details_draw(c);
    else if (S.login) { top_bar(c); login_draw(c); }
    else {
        top_bar(c);
        switch (S.tab) {
            case TAB_LIBRARY: library_draw(c); break;
            case TAB_SEARCH: {
                char q[400];
                snprintf(q, sizeof q, "البحث: %s", S.query[0] ? S.query : "—");
                text_draw(c, FONT_BOLD, 32, q, -1, W - MX, 140, ALIGN_RIGHT, C_INK);
                grid_draw(c, &S.search, TOP + 20);
                if (!S.search.loaded) empty_state(c, "ابحث في المكتبة", "اضغط X أو المثلث لكتابة اسم اللعبة أو المطور أو رمز المنتج.", C_ACCENT);
                unsigned hb[] = {BTN_TRIANGLE, BTN_CROSS, BTN_L2};
                const char *hl[] = {"كتابة البحث", "فتح اللعبة", "الأقسام"};
                hints(c, hb, hl, 3);
                break;
            }
            case TAB_FAVORITES:
                if (!S.token[0]) empty_state(c, "المفضلة تحتاج تسجيل الدخول", "اضغط X لتسجيل الدخول بحساب المكتبة.", C_ACCENT);
                else grid_draw(c, &S.favs, TOP + 20);
                { unsigned hb[] = {BTN_CROSS, BTN_L2}; const char *hl[] = {S.token[0] ? "فتح اللعبة" : "تسجيل الدخول", "الأقسام"}; hints(c, hb, hl, 2); }
                break;
            case TAB_NEWS:
                if (S.news_error[0]) empty_state(c, "تعذر تحميل الأخبار", S.news_error, C_BAD);
                else list_draw(c, jget(json_root(S.news_doc), "data"), S.news_focus, 1);
                { unsigned hb[] = {BTN_CROSS, BTN_TRIANGLE, BTN_L2}; const char *hl[] = {"فتح اللعبة المرتبطة", "تحديث", "الأقسام"}; hints(c, hb, hl, 3); }
                break;
            case TAB_NOTIFS:
                if (!S.token[0]) empty_state(c, "الإشعارات تحتاج تسجيل الدخول", "اضغط X لتسجيل الدخول بحساب المكتبة.", C_ACCENT);
                else if (S.notif_error[0]) empty_state(c, "تعذر تحميل الإشعارات", S.notif_error, C_BAD);
                else if (!S.notif_doc || !jget(json_root(S.notif_doc), "data") || !jget(json_root(S.notif_doc), "data")->count) empty_state(c, "لا توجد إشعارات", "", C_ACCENT);
                else list_draw(c, jget(json_root(S.notif_doc), "data"), S.notif_focus, 0);
                { unsigned hb[] = {BTN_TRIANGLE, BTN_L2}; const char *hl[] = {"تحديث", "الأقسام"}; hints(c, hb, hl, 2); }
                break;
            case TAB_SETTINGS:
                settings_draw(c);
                { unsigned hb[] = {BTN_CROSS, BTN_UP, BTN_L2}; const char *hl[] = {"اختيار", "تنقل", "الأقسام"}; hints(c, hb, hl, 3); }
                break;
            default:
                break;
        }
    }
    if (S.kb != KB_NONE) kb_draw(c);
    if (S.loading[0]) {
        int w = text_width(FONT_BOLD, 28, S.loading, -1) + 120;
        gfx_round(c, (W - w) / 2, H / 2 - 50, w, 100, 30, 0xF2151A3A);
        gfx_round_stroke(c, (W - w) / 2, H / 2 - 50, w, 100, 30, 2, C_ACCENT2);
        gfx_round_stroke(c, (W + w) / 2 - 76, H / 2 - 18, 36, 36, 18, 4, C_ACCENT3);
        text_draw(c, FONT_BOLD, 28, S.loading, -1, (W + w) / 2 - 92, H / 2 - 22, ALIGN_RIGHT, C_INK);
    }
    if (S.toast[0]) {
        int w = text_width(FONT_BOLD, 26, S.toast, -1) + 80;
        if (w > W - 200) w = W - 200;
        gfx_round(c, (W - w) / 2, H - 200, w, 76, 24, 0xF2151A3A);
        gfx_round_stroke(c, (W - w) / 2, H - 200, w, 76, 24, 2, S.toast_color);
        text_fit(c, FONT_BOLD, 26, S.toast, W / 2, H - 186, w - 40, ALIGN_CENTER, S.toast_color);
    }
    return 1;
}
