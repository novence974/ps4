# ULTIMATE PS LIBRARY for PS4 — OpenOrbis build (Linux / macOS; on Windows use WSL).
#
#   export OO_PS4_TOOLCHAIN=/path/to/OpenOrbis/PS4Toolchain     (release zip, not the bare git checkout)
#   make SERVER_URL=https://library.example.com
#
# Produces eboot.bin, sce_sys/param.sfo and $(CONTENT_ID).pkg (fake-signed homebrew package).

TITLE       := ULTIMATE PS LIBRARY
VERSION     := 1.00
TITLE_ID    := UPSL00001
CONTENT_ID  := IV0000-UPSL00001_00-UPSLLIBRARY00000
SERVER_URL  ?= https://library.example.com

LIBS        := -lc -lkernel -lSceVideoOut -lScePad -lSceUserService -lSceSystemService -lSceSysmodule -lSceNet -lSceSsl -lSceHttp

TOOLCHAIN   := $(OO_PS4_TOOLCHAIN)
INTDIR      := build/ps4
SOURCES     := src/main_ps4.c src/app.c src/api.c src/json.c src/gfx.c src/text.c src/image.c
OBJS        := $(patsubst src/%.c,$(INTDIR)/%.o,$(SOURCES))
ASSETS      := assets/fonts/readex_regular.ttf assets/fonts/readex_semibold.ttf assets/server.txt
ABOUT       := $(wildcard $(TOOLCHAIN)/samples/hello_world/sce_sys/about/right.sprx)

CFLAGS      := --target=x86_64-pc-freebsd12-elf -fPIC -funwind-tables -O2 -std=c11 -c -isysroot $(TOOLCHAIN) -isystem $(TOOLCHAIN)/include
LDFLAGS     := -m elf_x86_64 -pie --script $(TOOLCHAIN)/link.x --eh-frame-hdr -L$(TOOLCHAIN)/lib $(LIBS) $(TOOLCHAIN)/lib/crt1.o

UNAME_S     := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
CC          := /usr/local/opt/llvm/bin/clang
LD          := /usr/local/opt/llvm/bin/ld.lld
CDIR        := macos
else
CC          := clang
LD          := ld.lld
CDIR        := linux
endif
BIN         := $(TOOLCHAIN)/bin/$(CDIR)

.PHONY: all clean check-toolchain desktop

all: check-toolchain $(CONTENT_ID).pkg

check-toolchain:
	@test -n "$(TOOLCHAIN)" || (echo "Set OO_PS4_TOOLCHAIN to the OpenOrbis toolchain (release build)"; exit 1)
	@test -x "$(BIN)/create-fself" || (echo "create-fself not found in $(BIN): use the OpenOrbis release zip"; exit 1)

assets/server.txt: FORCE
	@printf '%s\n' "$(SERVER_URL)" > $@
FORCE:

$(INTDIR)/%.o: src/%.c
	@mkdir -p $(INTDIR)
	$(CC) $(CFLAGS) -o $@ $<

eboot.bin: $(OBJS)
	$(LD) $(OBJS) -o $(INTDIR)/upsl.elf $(LDFLAGS)
	$(BIN)/create-fself -in=$(INTDIR)/upsl.elf -out=$(INTDIR)/upsl.oelf --eboot "eboot.bin" --paid 0x3800000000000011

sce_sys/param.sfo: Makefile
	$(BIN)/PkgTool.Core sfo_new $@
	$(BIN)/PkgTool.Core sfo_setentry $@ APP_TYPE --type Integer --maxsize 4 --value 1
	$(BIN)/PkgTool.Core sfo_setentry $@ APP_VER --type Utf8 --maxsize 8 --value '$(VERSION)'
	$(BIN)/PkgTool.Core sfo_setentry $@ ATTRIBUTE --type Integer --maxsize 4 --value 0
	$(BIN)/PkgTool.Core sfo_setentry $@ CATEGORY --type Utf8 --maxsize 4 --value 'gd'
	$(BIN)/PkgTool.Core sfo_setentry $@ CONTENT_ID --type Utf8 --maxsize 48 --value '$(CONTENT_ID)'
	$(BIN)/PkgTool.Core sfo_setentry $@ DOWNLOAD_DATA_SIZE --type Integer --maxsize 4 --value 0
	$(BIN)/PkgTool.Core sfo_setentry $@ SYSTEM_VER --type Integer --maxsize 4 --value 0
	$(BIN)/PkgTool.Core sfo_setentry $@ TITLE --type Utf8 --maxsize 128 --value '$(TITLE)'
	$(BIN)/PkgTool.Core sfo_setentry $@ TITLE_ID --type Utf8 --maxsize 12 --value '$(TITLE_ID)'
	$(BIN)/PkgTool.Core sfo_setentry $@ VERSION --type Utf8 --maxsize 8 --value '$(VERSION)'

sce_sys/about/right.sprx:
	@mkdir -p sce_sys/about
	@if [ -n "$(ABOUT)" ]; then cp "$(ABOUT)" $@; fi

pkg.gp4: eboot.bin sce_sys/param.sfo sce_sys/icon0.png sce_sys/about/right.sprx $(ASSETS)
	$(BIN)/create-gp4 -out $@ --content-id=$(CONTENT_ID) --files "eboot.bin sce_sys/param.sfo sce_sys/icon0.png $(if $(ABOUT),sce_sys/about/right.sprx) $(ASSETS)"

$(CONTENT_ID).pkg: pkg.gp4
	$(BIN)/PkgTool.Core pkg_build $< .

# Linux test build (no PS4 needed): same app code, memory framebuffer, scripted controller, screenshots
desktop:
	@mkdir -p build
	gcc -std=c11 -D_GNU_SOURCE -O2 -o build/upsl-desktop src/main_desktop.c src/app.c src/api.c src/json.c src/gfx.c src/text.c src/image.c -lm

clean:
	rm -rf build eboot.bin pkg.gp4 sce_sys/param.sfo $(CONTENT_ID).pkg
