/* What the app needs from the system. Implemented by main_ps4.c (OpenOrbis) and main_desktop.c (Linux test build). */
#ifndef UPSL_PLATFORM_H
#define UPSL_PLATFORM_H

#include <stddef.h>
#include <stdint.h>

enum {
    BTN_UP = 1 << 0, BTN_DOWN = 1 << 1, BTN_LEFT = 1 << 2, BTN_RIGHT = 1 << 3,
    BTN_CROSS = 1 << 4, BTN_CIRCLE = 1 << 5, BTN_SQUARE = 1 << 6, BTN_TRIANGLE = 1 << 7,
    BTN_L1 = 1 << 8, BTN_R1 = 1 << 9, BTN_L2 = 1 << 10, BTN_R2 = 1 << 11, BTN_OPTIONS = 1 << 12
};

typedef struct {
    int status;          /* HTTP status, 0 when the request could not be sent */
    char *body;          /* response body (NUL-terminated), free with http_free */
    size_t len;
    char error[200];     /* transport error text when status == 0 */
} HttpResponse;

/* headers: NULL-terminated list of "Name: value" strings */
int plat_http(const char *method, const char *url, const char *const *headers, const char *body, size_t body_len, HttpResponse *out);
void http_free(HttpResponse *r);

uint64_t plat_ms(void);
const char *plat_data_dir(void);   /* writable: settings and the sign-in token */
const char *plat_asset_dir(void);  /* read-only: fonts and images shipped with the app */
const char *plat_device_name(void);

#endif
