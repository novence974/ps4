/* Small JSON reader for the API responses (one arena per document, freed at once). */
#ifndef UPSL_JSON_H
#define UPSL_JSON_H

#include <stddef.h>

typedef enum { J_NULL, J_BOOL, J_NUM, J_STR, J_ARR, J_OBJ } JType;

typedef struct JVal {
    JType t;
    double n;
    int b;
    const char *s;         /* string value (UTF-8) */
    const char *key;       /* key when the value is an object member */
    struct JVal *child;    /* first element / member */
    struct JVal *next;     /* next sibling */
    int count;             /* number of children */
} JVal;

typedef struct JDoc JDoc;

JDoc *json_parse(const char *text, size_t len);
JVal *json_root(JDoc *d);
void json_free(JDoc *d);

JVal *jget(const JVal *obj, const char *key);           /* member or NULL */
JVal *jat(const JVal *arr, int index);                   /* element or NULL */
JVal *jpath(const JVal *v, const char *dotted);          /* "data.platform.name" */
const char *jstr(const JVal *obj, const char *key, const char *def);
double jnum(const JVal *obj, const char *key, double def);
int jbool(const JVal *obj, const char *key);

#endif
