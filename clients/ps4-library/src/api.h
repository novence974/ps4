/* Client for the library's REST API (/api/v1). */
#ifndef UPSL_API_H
#define UPSL_API_H

#include "json.h"

typedef struct {
    int status;      /* HTTP status (0 = network error) */
    int ok;          /* {"ok": true} */
    JDoc *doc;
    JVal *root, *data, *meta;
    char error[300]; /* server message (Arabic) or network error */
} ApiResult;

void api_set_server(const char *base);   /* "https://library.example.com" */
const char *api_server(void);
void api_set_token(const char *token);   /* "" to sign out */
const char *api_token(void);

int api_call(const char *method, const char *path, const char *json_body, ApiResult *out);
void api_free(ApiResult *r);
void url_encode(const char *s, char *out, size_t n);
/* escapes a string for a JSON body */
void json_escape(const char *s, char *out, size_t n);

#endif
