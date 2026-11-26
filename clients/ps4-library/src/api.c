#include "api.h"

#include <stdio.h>
#include <string.h>

#include "platform.h"

static char server[400];
static char token[300];

void api_set_server(const char *base) {
    snprintf(server, sizeof server, "%s", base ? base : "");
    size_t n = strlen(server);
    while (n > 0 && server[n - 1] == '/') server[--n] = 0;
    if (n > 7 && !strcmp(server + n - 7, "/api/v1")) server[n - 7] = 0;
}

const char *api_server(void) { return server; }
void api_set_token(const char *t) { snprintf(token, sizeof token, "%s", t ? t : ""); }
const char *api_token(void) { return token; }

void url_encode(const char *s, char *out, size_t n) {
    static const char hex[] = "0123456789ABCDEF";
    size_t o = 0;
    for (; *s && o + 4 < n; s++) {
        unsigned char c = (unsigned char)*s;
        if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') out[o++] = (char)c;
        else { out[o++] = '%'; out[o++] = hex[c >> 4]; out[o++] = hex[c & 15]; }
    }
    out[o] = 0;
}

void json_escape(const char *s, char *out, size_t n) {
    size_t o = 0;
    for (; *s && o + 7 < n; s++) {
        unsigned char c = (unsigned char)*s;
        if (c == '"' || c == '\\') { out[o++] = '\\'; out[o++] = (char)c; }
        else if (c < 0x20) o += (size_t)snprintf(out + o, n - o, "\\u%04x", c);
        else out[o++] = (char)c;
    }
    out[o] = 0;
}

int api_call(const char *method, const char *path, const char *json_body, ApiResult *out) {
    memset(out, 0, sizeof(*out));
    char url[1200];
    snprintf(url, sizeof url, "%s/api/v1%s", server, path);
    char auth[340];
    const char *headers[5];
    int h = 0;
    headers[h++] = "Accept: application/json";
    if (json_body) headers[h++] = "Content-Type: application/json; charset=utf-8";
    if (token[0]) {
        snprintf(auth, sizeof auth, "Authorization: Bearer %s", token);
        headers[h++] = auth;
    }
    headers[h] = NULL;
    HttpResponse r;
    plat_http(method, url, headers, json_body, json_body ? strlen(json_body) : 0, &r);
    out->status = r.status;
    if (r.status == 0) {
        snprintf(out->error, sizeof out->error, "تعذر الاتصال بالخادم: %s", r.error[0] ? r.error : "لا استجابة");
        http_free(&r);
        return 0;
    }
    out->doc = json_parse(r.body, r.len);
    http_free(&r);
    out->root = json_root(out->doc);
    out->data = jget(out->root, "data");
    out->meta = jget(out->root, "meta");
    out->ok = jbool(out->root, "ok");
    if (!out->ok) {
        const char *msg = jstr(jget(out->root, "error"), "message", NULL);
        if (msg) snprintf(out->error, sizeof out->error, "%s", msg);
        else if (out->status == 404) snprintf(out->error, sizeof out->error, "لم يُعثر على واجهة API على هذا الرابط");
        else snprintf(out->error, sizeof out->error, "استجابة غير متوقعة من الخادم (HTTP %d)", out->status);
    }
    return out->ok;
}

void api_free(ApiResult *r) {
    json_free(r->doc);
    memset(r, 0, sizeof(*r));
}
