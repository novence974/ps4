#!/bin/sh
# Runs the PS4 app's code on Linux against a server (http:// only) with a scripted controller and saves screenshots.
#   SERVER=http://127.0.0.1:8080 USER=playerdemo PASS='…' sh tests/run_desktop.sh
# The account must be a normal library account; the test adds nothing except a sign-in token named "PS4 (desktop test build)".
set -e
cd "$(dirname "$0")/.."
make desktop
mkdir -p build/test-assets/fonts
cp assets/fonts/*.ttf build/test-assets/fonts/
echo "${SERVER:-http://127.0.0.1:8080}" > build/test-assets/server.txt
rm -rf build/test-data build/shots
./build/upsl-desktop build/test-assets build/test-data build/shots \
  "shot:01-library LEFT LEFT DOWN shot:02-focus L1 shot:03-platform CROSS shot:04-details DOWN DOWN DOWN shot:05-details-scrolled CIRCLE \
   R1 SQUARE shot:06-sorted R2 TRIANGLE shot:07-keyboard type:star OPTIONS shot:08-search R2 shot:09-news R2 shot:10-favorites-signed-out \
   CROSS shot:11-login CROSS type:${USER} OPTIONS DOWN CROSS type:${PASS} OPTIONS DOWN shot:11b-login-filled CROSS shot:12-signed-in-favorites \
   R2 shot:13-notifications R2 shot:14-settings DOWN CROSS shot:15-signed-out"
echo "screenshots in build/shots"
