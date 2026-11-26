/* Text: UTF-8, Arabic joining (forms taken from the font's own tables), simple bidi, Readex Pro via stb_truetype. */
#ifndef UPSL_TEXT_H
#define UPSL_TEXT_H

#include "gfx.h"

enum { FONT_REGULAR = 0, FONT_BOLD = 1 };
enum { ALIGN_RIGHT = 0, ALIGN_LEFT = 1, ALIGN_CENTER = 2 };

int text_init(const char *regular_ttf, const char *bold_ttf);
/* width in pixels of one line */
int text_width(int font, float px, const char *utf8, int len);
/* draws one line; x is the right edge for ALIGN_RIGHT, the left edge for ALIGN_LEFT, the centre for ALIGN_CENTER;
   y is the top of the line box (line height = px * 1.45) */
void text_draw(Canvas *c, int font, float px, const char *utf8, int len, int x, int y, int align, uint32_t color);
/* draws wrapped text inside a box of width w starting at y; returns the height used. max_lines 0 = no limit */
int text_box(Canvas *c, int font, float px, const char *utf8, int x, int y, int w, int align, uint32_t color, int max_lines);
/* single line clipped with an ellipsis to fit max_w */
void text_fit(Canvas *c, int font, float px, const char *utf8, int x, int y, int max_w, int align, uint32_t color);
int text_line_height(float px);

#endif
