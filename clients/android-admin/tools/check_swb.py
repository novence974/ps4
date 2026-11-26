#!/usr/bin/env python3
"""
Checks a .swb the way Sketchware Pro would use it, then builds an APK from the restored files only.

1. Structure: required entries, every encrypted file decrypts with Sketchware's key, every data file parses
   (Sketchware's section/JSON-lines formats), activity list <-> files/java, FAB bean per activity,
   manifest injections well-formed.
2. Restore: reproduces BackupFactory.restore(): data/ -> .sketchware/data/<id>/, resources/<sub>/ ->
   .sketchware/resources/<sub>/<id>/, project (sc_id rewritten, re-encrypted) -> .sketchware/mysc/list/<id>/project.
3. Build: manifest/resources/Java taken from the restored tree (not from the source folders) and compiled
   with build_local.build() (aapt2 -> ECJ 1.7 -> D8 -> signed APK).
"""
import json
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_local  # noqa: E402
from make_swb import activity_class, decrypt, encrypt  # noqa: E402

REQUIRED = ["project", "data/file", "data/library", "data/resource", "data/view", "data/logic", "data/permission",
            "data/project_config", "data/Injection/androidmanifest/attributes.json", "resources/icons/icon.png"]
problems = []


def ok(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        problems.append(msg)


def sections(text):
    out, cur = {}, None
    for line in text.split("\n"):
        if line.startswith("@"):
            cur = line[1:]
            out.setdefault(cur, [])
        elif line.strip():
            out[cur].append(line)
    return out


def main():
    swb = Path(sys.argv[1])
    work = Path(tempfile.mkdtemp(prefix="swbcheck-"))
    print("[structure]", swb.name)
    z = zipfile.ZipFile(swb)
    names = set(z.namelist())
    for r in REQUIRED:
        ok(r in names, "entry " + r)
    project = json.loads(decrypt(z.read("project")))
    ok(project.get("my_sc_pkg_name") == "com.upsl.admin" and project.get("custom_icon") is True, "project metadata decrypts (package, custom icon)")
    file_s = sections(decrypt(z.read("data/file")))
    acts = [json.loads(l)["fileName"] for l in file_s.get("activity", [])]
    ok(len(acts) > 0 and "main" in acts, "data/file lists %d activities incl. main" % len(acts))
    java = {n.split("/")[-1] for n in names if n.startswith("data/files/java/")}
    missing = [activity_class(a) for a in acts if activity_class(a) + ".java" not in java]
    ok(not missing, "every activity has its class in files/java " + (str(missing) if missing else ""))
    reserved = {"abstract", "class", "import", "new", "package", "public", "return", "static", "switch", "this", "default", "final"}
    ok(not [a for a in acts if a in reserved], "no activity name is a Java keyword (R.layout.<name> must compile)")
    lib = sections(decrypt(z.read("data/library")))
    ok(set(lib) == {"firebaseDB", "compat", "admob", "googleMap"} and json.loads(lib["compat"][0])["useYn"] == "N",
       "data/library: 4 sections, AppCompat off")
    ok(set(sections(decrypt(z.read("data/resource")))) == {"images", "sounds", "fonts"}, "data/resource sections")
    view = sections(decrypt(z.read("data/view")))
    ok(all((a + ".xml_fab") in view and json.loads(view[a + ".xml_fab"][0])["id"] == "_fab" for a in acts), "data/view: FAB bean for each activity")
    ok(decrypt(z.read("data/logic")) == "", "data/logic: no blocks (all logic lives in files/java)")
    perms = json.loads(z.read("data/permission"))
    ok("android.permission.INTERNET" in perms, "permissions: " + ", ".join(p.split(".")[-1] for p in perms))
    cfg = json.loads(z.read("data/project_config"))
    ok(cfg.get("min_sdk") == "21" and cfg.get("target_sdk") == "34", "project_config min 21 / target 34")
    attrs = json.loads(z.read("data/Injection/androidmanifest/attributes.json"))
    ok(all(isinstance(a.get("name"), str) and isinstance(a.get("value"), str) for a in attrs), "attributes.json entries are {name, value} strings")
    per_activity = {a["name"] for a in attrs if not a["name"].startswith("_")}
    ok(all(activity_class(a) in per_activity for a in acts), "theme/config attributes injected for every activity")

    print("[restore like BackupFactory.restore()]")
    sc_id = "742"
    sk = work / ".sketchware"
    unz = work / "unzipped"
    z.extractall(unz)
    data_dir = sk / "data" / sc_id
    shutil.copytree(unz / "data", data_dir)
    for sub in ("fonts", "icons", "images", "sounds"):
        dst = sk / "resources" / sub / sc_id
        src = unz / "resources" / sub
        if src.exists():
            shutil.copytree(src, dst, ignore=shutil.ignore_patterns(".nomedia"))
        else:
            dst.mkdir(parents=True)
    project["sc_id"] = sc_id
    (sk / "mysc/list" / sc_id).mkdir(parents=True)
    (sk / "mysc/list" / sc_id / "project").write_bytes(encrypt(json.dumps(project, ensure_ascii=False)))
    ok((data_dir / "files/java/MainActivity.java").exists(), "files/java restored")
    ok((sk / "resources/icons" / sc_id / "icon.png").exists(), "launcher icon restored")

    print("[build from the restored tree]")
    restored = json.loads(decrypt((sk / "mysc/list" / sc_id / "project").read_bytes()))
    rfile = sections(decrypt((data_dir / "file").read_bytes()))
    rattrs = json.loads((data_dir / "Injection/androidmanifest/attributes.json").read_text(encoding="utf-8"))
    p = {
        "package": restored["my_sc_pkg_name"],
        "app_name": restored["my_app_name"],
        "version_code": int(restored["sc_ver_code"]),
        "version_name": restored["sc_ver_name"],
        "min_sdk": int(json.loads((data_dir / "project_config").read_text())["min_sdk"]),
        "target_sdk": int(json.loads((data_dir / "project_config").read_text())["target_sdk"]),
        "activities": [json.loads(l)["fileName"] for l in rfile["activity"]],
        "permissions": json.loads((data_dir / "permission").read_text()),
        "application_attrs": [a["value"] for a in rattrs if a["name"] == "_application_attrs"],
        "activity_attrs": [a["value"] for a in rattrs if a["name"] == "MainActivity"],
        "app_components": (data_dir / "Injection/androidmanifest/app_components.txt").read_text(encoding="utf-8"),
        "colors": {k: "#%08X" % (restored[k] & 0xFFFFFFFF) for k in
                   ("color_primary", "color_primary_dark", "color_accent", "color_control_normal", "color_control_highlight")},
    }
    apk = build_local.build(p, data_dir / "files/java", data_dir / "files/resource", data_dir / "files/assets",
                            sk / "resources/icons" / sc_id / "icon.png", work / "build")
    ok(apk.exists(), "APK built from the restored project: " + apk.name)
    shutil.copy(apk, Path(sys.argv[2]) if len(sys.argv) > 2 else swb.with_suffix(".restored-build.apk"))
    print("\n%s" % ("ALL CHECKS PASSED" if not problems else "PROBLEMS: " + "; ".join(problems)))
    sys.exit(1 if problems else 0)


if __name__ == "__main__":
    main()
