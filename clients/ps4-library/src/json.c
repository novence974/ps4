#include "json.h"

#include <stdlib.h>
#include <string.h>

struct Block {
    struct Block *next;
    size_t used, size;
    char data[];
};

struct JDoc {
    struct Block *blocks;
    JVal *root;
    const char *p, *end;
    int failed;
};

static void *arena(JDoc *d, size_t n) {
    n = (n + 7) & ~(size_t)7;
    struct Block *b = d->blocks;
    if (!b || b->used + n > b->size) {
        size_t size = n > 65536 ? n : 65536;
        b = (struct Block *)malloc(sizeof(struct Block) + size);
        if (!b) {
            d->failed = 1;
            return NULL;
        }
        b->next = d->blocks;
        b->used = 0;
        b->size = size;
        d->blocks = b;
    }
    void *r = b->data + b->used;
    b->used += n;
    return r;
}

static void ws(JDoc *d) {
    while (d->p < d->end && (*d->p == ' ' || *d->p == '\n' || *d->p == '\r' || *d->p == '\t')) d->p++;
}

static int hex4(const char *s, unsigned *out) {
    unsigned v = 0;
    for (int i = 0; i < 4; i++) {
        char c = s[i];
        v <<= 4;
        if (c >= '0' && c <= '9') v |= (unsigned)(c - '0');
        else if (c >= 'a' && c <= 'f') v |= (unsigned)(c - 'a' + 10);
        else if (c >= 'A' && c <= 'F') v |= (unsigned)(c - 'A' + 10);
        else return 0;
    }
    *out = v;
    return 1;
}

static char *put_utf8(char *o, unsigned cp) {
    if (cp < 0x80) *o++ = (char)cp;
    else if (cp < 0x800) { *o++ = (char)(0xC0 | (cp >> 6)); *o++ = (char)(0x80 | (cp & 0x3F)); }
    else if (cp < 0x10000) { *o++ = (char)(0xE0 | (cp >> 12)); *o++ = (char)(0x80 | ((cp >> 6) & 0x3F)); *o++ = (char)(0x80 | (cp & 0x3F)); }
    else { *o++ = (char)(0xF0 | (cp >> 18)); *o++ = (char)(0x80 | ((cp >> 12) & 0x3F)); *o++ = (char)(0x80 | ((cp >> 6) & 0x3F)); *o++ = (char)(0x80 | (cp & 0x3F)); }
    return o;
}

static const char *string(JDoc *d) {
    /* d->p is after the opening quote */
    const char *s = d->p;
    size_t n = 0;
    while (s < d->end && *s != '"') { if (*s == '\\') s++; s++; n++; }
    char *out = (char *)arena(d, n * 4 + 1), *o = out;
    if (!out) return "";
    while (d->p < d->end && *d->p != '"') {
        char c = *d->p++;
        if (c != '\\') { *o++ = c; continue; }
        if (d->p >= d->end) break;
        c = *d->p++;
        switch (c) {
            case 'n': *o++ = '\n'; break;
            case 't': *o++ = '\t'; break;
            case 'r': *o++ = '\r'; break;
            case 'b': *o++ = '\b'; break;
            case 'f': *o++ = '\f'; break;
            case 'u': {
                unsigned cp;
                if (d->end - d->p < 4 || !hex4(d->p, &cp)) { d->failed = 1; return ""; }
                d->p += 4;
                if (cp >= 0xD800 && cp <= 0xDBFF && d->end - d->p >= 6 && d->p[0] == '\\' && d->p[1] == 'u') {
                    unsigned lo;
                    if (hex4(d->p + 2, &lo) && lo >= 0xDC00 && lo <= 0xDFFF) {
                        cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                        d->p += 6;
                    }
                }
                o = put_utf8(o, cp);
                break;
            }
            default: *o++ = c;
        }
    }
    *o = 0;
    if (d->p < d->end) d->p++; /* closing quote */
    return out;
}

static JVal *value(JDoc *d, int depth);

static JVal *node(JDoc *d, JType t) {
    JVal *v = (JVal *)arena(d, sizeof(JVal));
    if (v) { memset(v, 0, sizeof(*v)); v->t = t; }
    return v;
}

static JVal *value(JDoc *d, int depth) {
    ws(d);
    if (d->p >= d->end || depth > 64) { d->failed = 1; return NULL; }
    char c = *d->p;
    if (c == '{' || c == '[') {
        int obj = c == '{';
        JVal *v = node(d, obj ? J_OBJ : J_ARR), *last = NULL;
        if (!v) return NULL;
        d->p++;
        ws(d);
        if (d->p < d->end && *d->p == (obj ? '}' : ']')) { d->p++; return v; }
        for (;;) {
            const char *key = NULL;
            ws(d);
            if (obj) {
                if (d->p >= d->end || *d->p != '"') { d->failed = 1; return v; }
                d->p++;
                key = string(d);
                ws(d);
                if (d->p >= d->end || *d->p != ':') { d->failed = 1; return v; }
                d->p++;
            }
            JVal *child = value(d, depth + 1);
            if (!child) return v;
            child->key = key;
            if (last) last->next = child; else v->child = child;
            last = child;
            v->count++;
            ws(d);
            if (d->p < d->end && *d->p == ',') { d->p++; continue; }
            if (d->p < d->end && *d->p == (obj ? '}' : ']')) { d->p++; return v; }
            d->failed = 1;
            return v;
        }
    }
    if (c == '"') {
        JVal *v = node(d, J_STR);
        d->p++;
        if (v) v->s = string(d);
        return v;
    }
    if (c == 't' && d->end - d->p >= 4 && !strncmp(d->p, "true", 4)) { d->p += 4; JVal *v = node(d, J_BOOL); if (v) v->b = 1; return v; }
    if (c == 'f' && d->end - d->p >= 5 && !strncmp(d->p, "false", 5)) { d->p += 5; return node(d, J_BOOL); }
    if (c == 'n' && d->end - d->p >= 4 && !strncmp(d->p, "null", 4)) { d->p += 4; return node(d, J_NULL); }
    {
        char buf[64];
        int i = 0;
        while (d->p < d->end && i < 63 && strchr("+-0123456789.eE", *d->p)) buf[i++] = *d->p++;
        buf[i] = 0;
        if (i == 0) { d->failed = 1; return NULL; }
        JVal *v = node(d, J_NUM);
        if (v) v->n = strtod(buf, NULL);
        return v;
    }
}

JDoc *json_parse(const char *text, size_t len) {
    JDoc *d = (JDoc *)calloc(1, sizeof(JDoc));
    if (!d || !text) return d;
    d->p = text;
    d->end = text + len;
    d->root = value(d, 0);
    if (d->failed) d->root = NULL;
    return d;
}

JVal *json_root(JDoc *d) { return d ? d->root : NULL; }

void json_free(JDoc *d) {
    if (!d) return;
    struct Block *b = d->blocks;
    while (b) { struct Block *n = b->next; free(b); b = n; }
    free(d);
}

JVal *jget(const JVal *obj, const char *key) {
    if (!obj || obj->t != J_OBJ) return NULL;
    for (JVal *c = obj->child; c; c = c->next)
        if (c->key && !strcmp(c->key, key)) return c;
    return NULL;
}

JVal *jat(const JVal *arr, int index) {
    if (!arr || arr->t != J_ARR) return NULL;
    JVal *c = arr->child;
    while (c && index-- > 0) c = c->next;
    return c;
}

JVal *jpath(const JVal *v, const char *dotted) {
    char part[64];
    const char *p = dotted;
    JVal *cur = (JVal *)v;
    while (cur && *p) {
        int n = 0;
        while (*p && *p != '.' && n < 63) part[n++] = *p++;
        part[n] = 0;
        if (*p == '.') p++;
        cur = jget(cur, part);
    }
    return cur;
}

const char *jstr(const JVal *obj, const char *key, const char *def) {
    JVal *v = key ? jget(obj, key) : (JVal *)obj;
    return v && v->t == J_STR && v->s[0] ? v->s : def;
}

double jnum(const JVal *obj, const char *key, double def) {
    JVal *v = key ? jget(obj, key) : (JVal *)obj;
    if (!v) return def;
    if (v->t == J_NUM) return v->n;
    if (v->t == J_STR) return strtod(v->s, NULL);
    return def;
}

int jbool(const JVal *obj, const char *key) {
    JVal *v = jget(obj, key);
    return v && ((v->t == J_BOOL && v->b) || (v->t == J_NUM && v->n != 0));
}
