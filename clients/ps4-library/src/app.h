#ifndef UPSL_APP_H
#define UPSL_APP_H

#include "gfx.h"

#define APP_VERSION "1.00"

int app_init(Canvas *c);
/* one frame: pressed = buttons that went down this frame; returns 1 if the canvas changed (flip needed) */
int app_frame(Canvas *c, unsigned pressed);
/* background work between frames (one cover download); returns 1 if something new can be drawn */
int app_idle(void);
int app_should_quit(void);
void app_debug_type(const char *s);

#endif
