#!/usr/bin/env python3
"""
Local stand-in for Sketchware Pro's build of this project, used to check that the SWB content compiles
and runs before handing it over. It reproduces what Sketchware Pro generates for this configuration
(AppCompat off, no visual widgets, custom Java in files/java, resource overlay in files/resource,
manifest injections) and then runs the same kind of toolchain: aapt2 → ECJ (Java 1.7) → D8 → zipalign → apksigner.

This is NOT Sketchware Pro itself. The real check is importing the .swb into Sketchware Pro and building there.
"""
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SDK = Path("/usr/lib/android-sdk")
BT = SDK / "build-tools/34.0.0"
ANDROID_JAR = SDK / "platforms/android-34/android.jar"
# A Sketchware Pro source checkout (https://github.com/Sketchware-Pro/Sketchware-Pro): its generated helper classes
# (SketchApplication, DebugActivity, SketchwareUtil, FileUtil) are recreated from it for the local check only;
# they are not part of this project and not included in the .swb (Sketchware Pro generates them itself).
SWPRO = Path(os.environ.get("SKETCHWARE_PRO_SRC", "sketchware-pro-src"))
ENV = dict(os.environ, JAVA_TOOL_OPTIONS="", _JAVA_OPTIONS="")


def run(cmd, **kw):
    r = subprocess.run([str(c) for c in cmd], capture_output=True, text=True, env=ENV, **kw)
    out = (r.stdout + r.stderr).replace("Picked up JAVA_TOOL_OPTIONS: \n", "").replace("Picked up _JAVA_OPTIONS: \n", "")
    if r.returncode != 0:
        print(out)
        raise SystemExit("FAILED: " + " ".join(str(c) for c in cmd[:3]))
    return out


def activity_class(name):
    parts = name.lower().split("_")
    s = parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:] if p)
    return s[:1].upper() + s[1:] + "Activity"


def manifest(p):
    pkg = p["package"]
    lines = ['<?xml version="1.0" encoding="utf-8"?>',
             '<manifest xmlns:android="http://schemas.android.com/apk/res/android"',
             '\tpackage="%s">' % pkg]
    for perm in p["permissions"]:
        lines.append('\t<uses-permission android:name="%s" />' % perm)
    app_attrs = ['android:allowBackup="true"', 'android:icon="@mipmap/ic_launcher"', 'android:label="@string/app_name"',
                 'android:name=".SketchApplication"', 'android:requestLegacyExternalStorage="true"',
                 'android:usesCleartextTraffic="true"'] + p["application_attrs"]
    lines.append("\t<application\n\t\t" + "\n\t\t".join(app_attrs) + ">")
    for a in p["activities"]:
        attrs = ['android:name=".%s"' % activity_class(a)] + p["activity_attrs"]
        if a == "main":
            attrs.append('android:exported="true"')
            lines.append("\t\t<activity\n\t\t\t" + "\n\t\t\t".join(attrs) + ">")
            lines.append('\t\t\t<intent-filter>\n\t\t\t\t<action android:name="android.intent.action.MAIN" />\n'
                         '\t\t\t\t<category android:name="android.intent.category.LAUNCHER" />\n\t\t\t</intent-filter>\n\t\t</activity>')
        else:
            lines.append("\t\t<activity\n\t\t\t" + "\n\t\t\t".join(attrs) + " />")
    lines.append('\t\t<activity android:name=".DebugActivity" android:screenOrientation="portrait" android:theme="@style/AppTheme.DebugActivity" />')
    lines.append('\t\t<uses-library android:name="org.apache.http.legacy" android:required="false" />')
    lines.append(p["app_components"])
    lines.append("\t</application>\n</manifest>\n")
    return "\n".join(lines)


def generated_resources(p, res, icon_png=None):
    (res / "values").mkdir(parents=True, exist_ok=True)
    c = p["colors"]
    (res / "values/strings.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n\t<string name="app_name">%s</string>\n</resources>\n' % p["app_name"], encoding="utf-8")
    (res / "values/colors.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '\t<color name="colorPrimary">%s</color>\n\t<color name="colorPrimaryDark">%s</color>\n\t<color name="colorAccent">%s</color>\n'
        '\t<color name="colorControlHighlight">%s</color>\n\t<color name="colorControlNormal">%s</color>\n</resources>\n'
        % (c["color_primary"], c["color_primary_dark"], c["color_accent"], c["color_control_highlight"], c["color_control_normal"]), encoding="utf-8")
    items = '\t\t<item name="android:colorPrimary">@color/colorPrimary</item>\n\t\t<item name="android:colorPrimaryDark">@color/colorPrimaryDark</item>\n' \
            '\t\t<item name="android:colorAccent">@color/colorAccent</item>\n\t\t<item name="android:colorControlHighlight">@color/colorControlHighlight</item>\n' \
            '\t\t<item name="android:colorControlNormal">@color/colorControlNormal</item>\n'
    (res / "values/styles.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '\t<style name="AppTheme" parent="@android:style/Theme.Material.Light.DarkActionBar">\n' + items + '\t</style>\n'
        '\t<style name="FullScreen" parent="@android:style/Theme.Material.Light.NoActionBar.Fullscreen">\n' + items + '\t</style>\n'
        '\t<style name="NoActionBar" parent="@android:style/Theme.Material.Light.NoActionBar">\n' + items + '\t</style>\n'
        '\t<style name="NoStatusBar" parent="AppTheme">\n\t\t<item name="android:windowFullscreen">true</item>\n\t</style>\n'
        '\t<style name="AppTheme.DebugActivity" parent="AppTheme" />\n</resources>\n', encoding="utf-8")
    (res / "layout").mkdir(exist_ok=True)
    for a in p["activities"]:
        (res / ("layout/%s.xml" % a)).write_text(
            '<?xml version="1.0" encoding="utf-8"?>\n<LinearLayout xmlns:android="http://schemas.android.com/apk/res/android"\n'
            '\tandroid:layout_width="match_parent"\n\tandroid:layout_height="match_parent"\n\tandroid:orientation="vertical" />\n', encoding="utf-8")
    # launcher icon: Sketchware copies the custom icon to mipmap
    from PIL import Image
    src = Image.open(icon_png or (ROOT / "icon/icon.png")).convert("RGBA")
    for d, size in (("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)):
        (res / ("mipmap-" + d)).mkdir(exist_ok=True)
        src.resize((size, size), Image.LANCZOS).save(res / ("mipmap-%s/ic_launcher.png" % d))


def _extract_method(src, signature):
    """Returns the source of one method of Lx.java (brace matching that skips strings and text blocks)."""
    start = src.index(signature)
    j = src.index("{", start)
    depth = 0
    while j < len(src):
        if src.startswith('"""', j):
            j = src.index('"""', j + 3) + 3
            continue
        c = src[j]
        if c in "\"'":
            j += 1
            while src[j] != c:
                j += 2 if src[j] == "\\" else 1
            j += 1
            continue
        if src.startswith("//", j):
            j = src.index("\n", j)
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return src[start:j + 1]
        j += 1
    raise SystemExit("cannot parse " + signature)


def templates(pkg, out):
    pdir = out / pkg.replace(".", "/")
    pdir.mkdir(parents=True, exist_ok=True)
    assets = SWPRO / "app/src/main/assets/debug"
    if not assets.exists():
        raise SystemExit("Set SKETCHWARE_PRO_SRC to a Sketchware Pro source checkout (needed for the local check only)")
    for name in ("SketchApplication.java", "DebugActivity.java"):
        src = (assets / name).read_text(encoding="utf-8").replace("<?package_name?>", pkg)
        (pdir / name).write_text(src, encoding="utf-8")
    lx = (SWPRO / "app/src/main/java/a/a/a/Lx.java").read_text(encoding="utf-8")
    gen_dir = out.parent / "lxgen"
    gen_dir.mkdir(exist_ok=True)
    (gen_dir / "Gen.java").write_text(
        "import java.nio.file.*;\npublic class Gen {\n"
        + _extract_method(lx, "public static String e(String packageName)") + "\n"
        + _extract_method(lx, "public static String i(String packageName, boolean isMaterial3Enabled)") + "\n"
        + "public static void main(String[] a) throws Exception {\n"
        + "Files.writeString(Path.of(a[1], \"FileUtil.java\"), e(a[0]));\n"
        + "Files.writeString(Path.of(a[1], \"SketchwareUtil.java\"), i(a[0], false));\n}\n}\n", encoding="utf-8")
    run(["javac", "-d", gen_dir, gen_dir / "Gen.java"])
    run(["java", "-cp", gen_dir, "Gen", pkg, pdir])


def build(p, java_dir, res_dir, assets_dir, icon_png, build):
    """Builds the APK from a project description and the four source folders."""
    if build.exists():
        shutil.rmtree(build)
    res = build / "res"
    generated_resources(p, res, icon_png)
    # files/resource overlay (copied over the generated resources, like Sketchware Pro does)
    shutil.copytree(res_dir, res, dirs_exist_ok=True)
    (build / "AndroidManifest.xml").write_text(manifest(p), encoding="utf-8")
    assets = build / "assets"
    shutil.copytree(assets_dir, assets)
    src_gen = build / "gen-src"
    templates(p["package"], src_gen)
    # activity classes in files/java replace generated ones: check that every activity has its class
    missing = [activity_class(a) for a in p["activities"] if not (java_dir / (activity_class(a) + ".java")).exists()]
    if missing:
        raise SystemExit("Missing activity classes in java/: " + ", ".join(missing))
    # generated helpers are skipped when files/java has a file of the same name (as Sketchware does)
    for f in list((src_gen / p["package"].replace(".", "/")).glob("*.java")):
        if (java_dir / f.name).exists():
            f.unlink()

    print("== aapt2 compile/link")
    run([BT / "aapt2", "compile", "--dir", res, "-o", build / "res.zip"])
    (build / "r").mkdir()
    run([BT / "aapt2", "link", "-I", ANDROID_JAR, "--manifest", build / "AndroidManifest.xml", "-A", assets,
         "--java", build / "r", "--min-sdk-version", p["min_sdk"], "--target-sdk-version", p["target_sdk"],
         "--version-code", p["version_code"], "--version-name", p["version_name"], "--auto-add-overlay",
         "-o", build / "base.apk", build / "res.zip"])

    print("== ecj -1.7")
    classes = build / "classes"
    classes.mkdir()
    out = run(["ecj", "-1.7", "-nowarn", "-proc:none", "-encoding", "UTF-8", "-d", classes, "-cp", ANDROID_JAR,
               src_gen, build / "r", java_dir])
    if out.strip():
        print(out.strip()[-3000:])

    print("== d8")
    dex = build / "dex"
    dex.mkdir()
    class_files = [str(f) for f in classes.rglob("*.class")]
    run([BT / "d8", "--release", "--min-api", p["min_sdk"], "--lib", ANDROID_JAR, "--output", dex] + class_files)

    print("== package, align, sign")
    unsigned = build / "unsigned.apk"
    shutil.copy(build / "base.apk", unsigned)
    run(["zip", "-j", "-q", unsigned, dex / "classes.dex"])
    aligned = build / "aligned.apk"
    run([BT / "zipalign", "-f", "-p", "4", unsigned, aligned])
    ks = ROOT / "tools/test-signing.keystore"
    if not ks.exists():
        run(["keytool", "-genkeypair", "-keystore", ks, "-storepass", "upsl-test", "-keypass", "upsl-test", "-alias", "upsl",
             "-keyalg", "RSA", "-keysize", "2048", "-validity", "10000", "-dname", "CN=UPSL Admin test build"])
    final = build / ("upsl-admin-%s-localtest.apk" % p["version_name"])
    run([BT / "apksigner", "sign", "--ks", ks, "--ks-pass", "pass:upsl-test", "--ks-key-alias", "upsl", "--out", final, aligned])
    print(run([BT / "apksigner", "verify", "--print-certs", final]).splitlines()[0])
    print("APK:", final, os.path.getsize(final), "bytes")
    return final


def main():
    p = json.loads((ROOT / "project.json").read_text(encoding="utf-8"))
    build_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("build/local")
    build(p, ROOT / "java", ROOT / "res", ROOT / "assets", ROOT / "icon/icon.png", build_dir)


if __name__ == "__main__":
    main()
