#!/usr/bin/env python3
"""
Packs the admin app as a Sketchware Pro backup (.swb).

Layout (what Sketchware Pro's BackupFactory.restore() expects):
  project                                   AES-encrypted project metadata (JSON)
  data/file, data/library, data/resource,   AES-encrypted project data (Sketchware's own text formats)
  data/view, data/logic
  data/permission                           JSON list of permissions
  data/project_config                       JSON map (min/target SDK)
  data/Injection/androidmanifest/...        manifest injections (attributes.json, app_components.txt)
  data/files/java/*.java                    the app's code (each *Activity.java replaces the generated one)
  data/files/resource/**                    extra resources (icons, theme)
  data/files/assets/**                      fonts and licence texts
  resources/icons/icon.png                  launcher icon
  resources/{images,sounds,fonts}/.nomedia
Encryption: AES/CBC/PKCS5Padding with key = IV = "sketchwaresecure" (Sketchware's fixed project-file key).
"""
import json
import os
import sys
import time
import zipfile
from pathlib import Path

from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

ROOT = Path(__file__).resolve().parents[1]
KEY = b"sketchwaresecure"

# Sketchware's default floating-action-button bean (every activity has one, hidden unless the FAB option is on)
FAB = ('{"adSize":"","adUnitId":"","alpha":1.0,"checked":0,"choiceMode":0,"clickable":1,"customView":"","dividerHeight":1,"enabled":1,'
       '"firstDayOfWeek":1,"id":"_fab","image":{"rotate":0,"scaleType":"CENTER"},"indeterminate":"false","index":0,"layout":{"backgroundColor":16777215,'
       '"gravity":0,"height":-2,"layoutGravity":85,"marginBottom":16,"marginLeft":16,"marginRight":16,"marginTop":16,"orientation":-1,"paddingBottom":0,'
       '"paddingLeft":0,"paddingRight":0,"paddingTop":0,"weight":0,"weightSum":0,"width":-2},"max":100,"parentType":-1,"preIndex":0,"preParentType":0,'
       '"progress":0,"progressStyle":"?android:progressBarStyle","scaleX":1.0,"scaleY":1.0,"spinnerMode":1,"text":{"hint":"","hintColor":-10453621,'
       '"imeOption":0,"inputType":1,"line":0,"singleLine":0,"text":"","textColor":-16777216,"textFont":"default_font","textSize":12,"textType":0},'
       '"translationX":0.0,"translationY":0.0,"type":16}')


def encrypt(text):
    data = text.strip().encode("utf-8")
    padder = padding.PKCS7(128).padder()
    padded = padder.update(data) + padder.finalize()
    enc = Cipher(algorithms.AES(KEY), modes.CBC(KEY)).encryptor()
    return enc.update(padded) + enc.finalize()


def decrypt(blob):
    dec = Cipher(algorithms.AES(KEY), modes.CBC(KEY)).decryptor()
    padded = dec.update(blob) + dec.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return (unpadder.update(padded) + unpadder.finalize()).decode("utf-8")


def activity_class(name):
    parts = name.lower().split("_")
    s = parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:] if p)
    return s[:1].upper() + s[1:] + "Activity"


def argb(hex_color):
    v = int(hex_color.lstrip("#"), 16)
    if len(hex_color.lstrip("#")) == 6:
        v |= 0xFF000000
    return v - (1 << 32) if v >= (1 << 31) else v


def build_entries(p):
    acts = p["activities"]
    entries = {}
    project = {
        "sc_id": "601",
        "my_sc_pkg_name": p["package"],
        "my_ws_name": p["workspace_name"],
        "my_app_name": p["app_name"],
        "sc_ver_code": str(p["version_code"]),
        "sc_ver_name": p["version_name"],
        "my_sc_reg_dt": time.strftime("%Y%m%d%H%M%S"),
        "custom_icon": True,
        "isIconAdaptive": False,
        "sketchware_ver": 150,
    }
    for k, v in p["colors"].items():
        project[k] = argb(v)
    entries["project"] = encrypt(json.dumps(project, ensure_ascii=False, separators=(",", ":")))

    file_lines = ["@activity"]
    for a in acts:
        file_lines.append(json.dumps({"fileName": a, "fileType": 0, "keyboardSetting": 0, "options": 0, "orientation": 2, "theme": -1},
                                     separators=(",", ":")))
    file_lines.append("@customview")
    entries["data/file"] = encrypt("\n".join(file_lines))

    lib = []
    for i, name in enumerate(["firebaseDB", "compat", "admob", "googleMap"]):
        lib.append("@" + name)
        lib.append(json.dumps({"adUnits": [], "data": "", "libType": i, "reserved1": "", "reserved2": "", "reserved3": "",
                               "testDevices": [], "useYn": "N"}, separators=(",", ":")))
    entries["data/library"] = encrypt("\n".join(lib))
    entries["data/resource"] = encrypt("@images\n@sounds\n@fonts")
    entries["data/view"] = encrypt("\n".join("@%s.xml_fab\n%s\n" % (a, FAB) for a in acts))
    entries["data/logic"] = encrypt("")

    entries["data/permission"] = json.dumps(p["permissions"]).encode()
    entries["data/project_config"] = json.dumps({"min_sdk": str(p["min_sdk"]), "target_sdk": str(p["target_sdk"])}).encode()
    attrs = [{"name": "_application_attrs", "value": v} for v in p["application_attrs"]]
    for a in acts:
        for v in p["activity_attrs"]:
            attrs.append({"name": activity_class(a), "value": v})
    entries["data/Injection/androidmanifest/attributes.json"] = json.dumps(attrs, ensure_ascii=False, indent=1).encode()
    entries["data/Injection/androidmanifest/app_components.txt"] = p["app_components"].encode()

    for f in sorted((ROOT / "java").glob("*.java")):
        entries["data/files/java/" + f.name] = f.read_bytes()
    for f in sorted((ROOT / "res").rglob("*")):
        if f.is_file():
            entries["data/files/resource/" + f.relative_to(ROOT / "res").as_posix()] = f.read_bytes()
    for f in sorted((ROOT / "assets").rglob("*")):
        if f.is_file():
            entries["data/files/assets/" + f.relative_to(ROOT / "assets").as_posix()] = f.read_bytes()
    entries["resources/icons/icon.png"] = (ROOT / "icon/icon.png").read_bytes()
    for sub in ("images", "sounds", "fonts"):
        entries["resources/%s/.nomedia" % sub] = b""
    return entries


def main():
    p = json.loads((ROOT / "project.json").read_text(encoding="utf-8"))
    out = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / ("dist/UPSL_Admin_v%s.swb" % p["version_name"])
    out.parent.mkdir(parents=True, exist_ok=True)
    missing = [activity_class(a) for a in p["activities"] if not (ROOT / "java" / (activity_class(a) + ".java")).exists()]
    if missing:
        raise SystemExit("missing activity classes: " + ", ".join(missing))
    entries = build_entries(p)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for name in sorted(entries):
            info = zipfile.ZipInfo(name, date_time=time.localtime()[:6])
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, entries[name])
    print("SWB:", out, os.path.getsize(out), "bytes,", len(entries), "entries")


if __name__ == "__main__":
    main()
