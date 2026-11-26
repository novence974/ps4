#ifndef UPSL_IMAGE_H
#define UPSL_IMAGE_H

#include "gfx.h"

Image *image_decode(const unsigned char *data, int len, int max_w);
void image_free(Image *img);
/* cached picture for this URL (NULL while not loaded or if it failed) */
const Image *image_cached(const char *url);
int image_known(const char *url);
/* downloads and decodes one picture into the cache; returns 1 if a request was made */
int image_fetch(const char *url, int max_w);

#endif
